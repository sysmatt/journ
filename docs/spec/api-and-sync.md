# Sync Protocol & API

Status: draft spec, promoted from `docs/brain-dump.md`. Covers how clients
and the default backend talk to each other, and how clients reconcile
data locally. See `data-model.md` for what's being synced, and
`identity-and-security.md` for auth on top of these endpoints.

## Design principles

- **Server is a dumb pipe for sync.** No server-side JSON parsing or
  reduction on the client-facing sync path. Whole files only, delivered
  as-is; the client always does the reduce/dedup locally. (Compaction is
  a deliberate, separate exception — see below.)
- **Explicitly not designed for low-quality/slow links.** Connectivity is
  treated as binary (present or absent), not a spectrum to optimize
  bandwidth for.
- **Data volume is assumed small** (not big-data scale) — this justifies
  several simplifications throughout: full-reduce-on-change instead of
  incremental merging, simple list-and-diff instead of delta sync, etc.
- **No unattended/background sync.** Sync only happens while the app is
  open in an active tab — no reliance on browser background-sync APIs.
- **No sneakernet / peer-to-peer sync.** The server is the one mandatory
  rendezvous point; two clients that never both reach the same server
  cannot exchange data with each other directly.

## Sync API (default backend)

Because fragments are **immutable once written** (never overwritten), a
**filename alone is a sufficient cache key** — no ETags/content hashes
needed.

**Path prefix note**: every path below is written as the PHP router
(`api/index.php`) sees it — i.e. already stripped of the `/api` alias
prefix. The browser-facing URL is `{site}/api/journal/{uuid}/list`, not
`{site}/journal/{uuid}/list` — see `docs/deployment.md` for the web
server config that does this stripping. This bit the frontend
implementation once already (`src/App.svelte` originally called the API
at the site root with no `/api` prefix at all) — worth being explicit
about here so it isn't repeated.

| Endpoint (shape) | Purpose |
|---|---|
| `GET /journal/{uuid}/list` (and per-event) | Returns the array of fragment filenames present. Client diffs against what it already has locally and fetches only what's missing. |
| `GET /journal/{uuid}/{filename}` | Fetch one fragment's raw bytes. |
| `PUT /journal/{uuid}/{filename}` | Create a new fragment. Reject if a file already exists at that exact name (no overwrite ever); reject malformed paths/names. No delete endpoint exists at all — the backend is add-only. |
| `GET /journal/{uuid}/freshness` (naming TBD) | Returns the timestamp of the journal's most recent write, across all fragments. Doubles as the reachability check — see Sync Trigger Mechanism below. |
| `POST /journal` (bootstrap) | One atomic call: allocates the journal folder, writes journal metadata, and writes the initial contact set with already-working secrets. Gated by the bootstrap secret — see `identity-and-security.md`. |
| Archive event (privileged) | Physically moves an entire event's fragments into attic storage. Not achievable via the ordinary add-only write path — real server-side logic, same category as compaction. One-way, no restore endpoint. |
| Attachment upload/download | Separate from the JSON fragment path entirely — own endpoints, UUID-keyed. Subject to size/type limits (see `operations.md` for config). |
| `GET /tags` | Install-wide, not journal-scoped. Exposes the server's `[tag:*]` color/behavior config so the client can render tag chips — added once frontend work surfaced that the client otherwise has no way to reach `journ-config.ini`, which deliberately lives outside the docroot. See `payload-shapes.md`. |
| `GET /journal/{uuid}/events/{uuid}/dashboard` | The one exception to "reads are never gated" — a purpose-built read-only endpoint for public event sharing, actually checking `X-Journ-Dashboard-Secret` server-side so revocation is real. Not part of the ordinary fragment sync path at all. See `identity-and-security.md` § Public dashboard secret and `payload-shapes.md`. |
| `GET /journal/{uuid}/events/{uuid}/dashboard/freshness` | Same secret gate, but a cheap stat()-only timestamp check — what the public dashboard actually polls every few seconds, only falling through to the full endpoint above when it's changed. |

### Listing/discovery mechanism (server-side)

The `list` endpoints (see `payload-shapes.md` for the exact response
shapes) are implemented directly against the filesystem, no index or
database — consistent with the "dumb pipe" principle:

- **Journal-root fragment list**: a non-recursive glob at the journal
  root for `metadata.*.json` and `contact.*.json`.
- **Event discovery** (populates the `events` array in the journal-root
  list response — how a client learns which events exist at all):
  `opendir()` on `{journal}/events/`, treating each subdirectory name as
  a candidate event UUID, then confirming it with a glob for
  `events/{uuid}/metadata.*.json` inside it. A directory only counts as
  a real event if that glob matches — guards against a stray or
  half-initialized directory ever being reported as a real event.
- **Per-event fragment list**: a non-recursive glob at
  `events/{uuid}/*.json`. Attachments are automatically excluded with no
  special-casing needed — since they live in their own `attachments/`
  subfolder (see `data-model.md`), a non-recursive glob simply never
  descends into it.

### Fragment-write enforcement

The server, not just the client, enforces the write rules:
- Reject writing to a filename that already exists (immutability).
- Reject malformed paths/filenames (must match the `{type}.{uuid}.json`
  convention under the expected folder).
- No delete capability exists anywhere in the client-facing API.

## Sync trigger mechanism

- Sync fires **on every entry post** — send the update, and in the same
  round trip check for anything new waiting to be pulled.
- A **timer** additionally performs the same check periodically while the
  app is open.
- Cheap check: compare the server's reported "most recent write"
  timestamp (scoped **per-journal**, not per-event) against the client's
  own last-synced timestamp. Only do a real listing/diff if the server's
  value is newer. This single call also serves as the "is the endpoint
  reachable" check — no separate plain ping endpoint.
- Client-visible state (see `ui-ux.md` for the top-bar icon): **synced**
  (no pending local writes, server reachable), **syncing** (actively
  pushing/pulling), **offline** (server unreachable — may have entries
  queued locally waiting to go out).

## Client/backend coupling & hosting model

- A client is **coupled to one storage backend from first connection
  onward** — no server-switching within a single app install. The backend
  a given journ installation (app + default backend, deployed together)
  uses is configured in that installation's own server-side config, not
  chosen per-journal by the user.
- **Platform consequence**: because PWA installs and browser storage
  (IndexedDB) are scoped per origin, a browser used against multiple journ
  deployments on different URLs ends up with multiple separate installed
  app icons, one per origin, each with its own isolated local storage.
  There is no single app instance listing journals spanning multiple
  servers — a natural consequence of the web platform.
- **Persistent storage requested**: the app calls
  `navigator.storage.persist()` to deprioritize this origin's data for
  browser-storage eviction under pressure. Treated as a mitigation, not a
  guarantee — some browsers prompt for permission, it's not airtight even
  when granted, and it's weakest on iOS Safari (already the platform weak
  link for PWA behavior generally). The sync-status icon (see `ui-ux.md`)
  is the real backstop.

## Read/merge (reduction) strategy

- Multiple clients can concurrently write new fragments, or files could
  get duplicated at the storage layer.
- Reconciliation: **read all relevant JSON fragments in, and deduplicate
  into a single in-memory structure using last-written timestamp** as the
  tiebreaker for any given record (by that record's own UUID).
- **One generalized reducer function**, not bespoke logic per object
  type — works uniformly across journal metadata, event metadata,
  contacts, and entries. This exact same function is reused in three
  places: client cold-load, client post-sync refresh, and server-side
  compaction.

### Client-side caching strategy

**"Full reduce, then cache the result"** — not true incremental/delta
merging. Whenever new fragments arrive (sync pull or local write), the
client reruns the same full reducer over the complete fragment set and
persists the output locally as a cache; reads in between (e.g.
re-rendering the same screen) just load the cached result rather than
recomputing.

Deliberately **not** a separate incremental delta-merge code path that
patches just the changed records into a long-lived view — that would be a
second implementation that has to stay perfectly semantically identical
to the full reducer forever, a real drift/correctness risk for no real
benefit given the assumed small data volume.

Same-device, multi-tab redundant reprocessing is an accepted edge case —
no cross-tab coordination logic planned. Browser storage is shared
same-origin, so nothing breaks, just possibly some duplicated work.

## Compaction

- Reduce-and-archive job reusing the same reducer logic as clients — the
  one deliberate, intentional exception to "no server-side JSON parsing."
- **No scheduler.** Triggered opportunistically by any server-side write
  operation that notices a folder's fragment count has crossed a
  threshold (configurable — see `operations.md`) — the next write after
  the threshold is crossed kicks off compaction.
- **Must be safe to trigger concurrently/redundantly** without
  corruption: compaction only reads current state and writes new files;
  "archiving" old fragments means copying them aside into `attic/`, never
  removing something a concurrent writer might still depend on.

## Export & Import

- **Journal-creation-time UUID collision errors out.** Importing into a
  target that already has a journal at that UUID is rejected — no merge
  attempted at the journal-creation level. (This only blocks
  re-creating an already-existing journal; restoring onto a fresh/empty
  target works fine, since there's nothing to collide with.)
- **Fragment-level replay is always safe, no special "merge mode"
  needed.** Because every fragment write is UUID-named, immutable, and
  already idempotent (re-`PUT`ing something that already exists just
  409s harmlessly), an export's fragments can always be safely replayed
  against *any* target — new or already-existing. This covers **partial
  recovery** (an old export has fragments a live journal is currently
  missing, e.g. after storage damage) for free, with zero dedicated
  import-merge logic.
- **Clone via "Import as new UUID"**: a checkbox on import that mints a
  fresh journal UUID and replays all fragments under it. Event/entry
  UUIDs inside the clone stay identical to the original — safe, since
  nothing in the API ever compares UUIDs across journal boundaries.
  - Cloning does **not** carry over original contact secret hashes.
    Every imported contact gets a **freshly auto-regenerated secret** as
    part of the clone operation itself (run under the bootstrap secret,
    same authority as any new journal's initial setup) — not cleared to
    nothing (which would leave nobody, including the importer, with
    working write access). With auto-regeneration, every contact already
    has a valid secret the moment the clone finishes; remaining manual
    work is pure distribution (copy/send invite per contact), not
    regeneration. None of the original journal's secrets grant any
    access to the clone.
- Attachments bundle into the export too, serialized as blobs, for
  portability/backup purposes (see `data-model.md` § Attachments).
