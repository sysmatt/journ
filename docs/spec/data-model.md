# Data Model

Status: draft spec, promoted from `docs/brain-dump.md` (2026-07-30/31 design
sessions). This is the authoritative reference for journ's data shape; the
brain dump remains as the decision-log/rationale trail.

## Overview

Three nested levels, plus a journal-scoped contact list:

```
Journal
├── Contacts (list)
└── Events (one or more)
    └── Entries (one or more)
```

Every object (journal, event, entry, contact) gets a globally unique UUID
at creation time. UUIDs are never reused and never compared across journal
boundaries — two different journals can contain identical event/entry
UUIDs (e.g. after a clone) without any collision, because nothing in the
system ever looks across that boundary.

## Journal

The outermost container. One journal = one incident/event-journaling
context, one storage location.

| Field | Mutable? | Notes |
|---|---|---|
| `uuid` | No | Assigned at creation, immutable forever |
| `name` | **Yes** | The only journal-level field that can change after creation |
| storage location / backend config | No | Baked in at creation; see below |

- **No lifecycle/state.** A journal is never "closed." The only way to
  retire one is an out-of-band, server-side directory delete — not an
  in-app operation.
- **Storage location/backend config is immutable** once the journal is
  created. Deliberate: prevents one operator from repointing storage to
  something invalid and disconnecting everyone else. The only way to
  "move" a journal is export + re-import onto new storage (see
  `api-and-sync.md` § Export & Import).
- **No general "app-wide config" field.** Settings like theme or
  timezone-display preference are client-side/browser-local only — never
  synced journal data.
- Contains the journal's **contact list** and one or more **events**.

## Event

| Field | Mutable? | Notes |
|---|---|---|
| `uuid` | No | Assigned at creation |
| start date/time | Yes (LWW) | |
| creator | Yes (LWW) | Reference to a contact UUID |
| description | Yes (LWW) | Free text, same treatment as entry text (Markdown-rendered, may contain contact chips / tags) |
| open/closed state | Yes (LWW) | See lifecycle below |

- **Multiple events may be open concurrently** within one journal — events
  are not implicitly sequential.
- **A brand-new journal starts with zero events.** No auto-created first
  event; an explicit "new event" action is required before any entry can
  be posted.
- Contains a collection of **entries**.

### Event lifecycle: open / closed

- Closing an event records an end-of-event timestamp. **Once closed, no
  new entries can be added, and no edits/trashes of existing entries
  either** — a closed event is fully frozen from the client UI's
  perspective.
- Closing is reversible ("re-open").
- **Metadata changes to an event — including close/re-open — get recorded
  as entries too**, so the entry timeline doubles as an audit log of
  metadata-level changes, not just user-authored text.
- **The server enforces nothing about open/closed state** — consistent
  with the dumb-pipe backend design (see `api-and-sync.md`), it just
  accepts whatever writes arrive. The freeze is client-enforced only.
  Because the closed state itself propagates via ordinary
  eventually-consistent sync, a client that hasn't yet learned an event
  closed could legitimately write an entry moments before the closure
  reaches it — a genuine race, not a bug. **Resolution: late-arriving
  entries are simply merged into the timeline normally once synced** —
  no special rejection/quarantine logic for entries that lost the race.

### Event archive

Distinct from entry-level trash (see below). Archiving an **entire
event** physically moves all its fragments into attic storage — a real
file move that ordinary clients cannot perform under the add-only backend
model (see `api-and-sync.md` § Archive Event endpoint for the mechanism).

- **One-way.** Once archived, content is entirely outside the list/get
  API surface — invisible to every client, not part of the app's data
  model at all. No in-app restore. Recovery is manual/out-of-band only
  (someone with direct storage access moves files back by hand). This
  applies uniformly to archived events and compacted-away fragments.

## Entry

| Field | Mutable? | Notes |
|---|---|---|
| `uuid` | No | Assigned at creation; used for cross-file dedup/edit matching |
| timestamp | N/A | See Time Handling below |
| text | Yes (LWW, see write model) | Plain text; see Content & Formatting |
| attachments | Yes | See Attachments below |
| trashed (soft-delete) | Yes | Hide-only flag, see below |

### Content & formatting

- Entries are **plain text in storage and in the input box** — no rich
  schema, no rich-text editor component.
- **Display** renders that plain text through a sanitized Markdown pass:
  bold/italic/lists/links from optional syntax, plus auto-linking of bare
  URLs. Text with no special syntax displays as plain text, unchanged.
  Sanitization is mandatory (entries from one contact render in every
  other contact's browser — unsanitized rendering is an XSS risk).
- **Unicode/emoji fully supported** — JSON strings and browser text
  inputs are Unicode-native; the only requirement is consistent UTF-8
  end-to-end (client storage, server file writes, HTTP headers). Caveat
  for any future text-truncation feature: many emoji are multiple
  Unicode codepoints (skin tones, flags, ZWJ sequences) — truncate by
  grapheme cluster, never by naive string-length slicing.
- **Contact chips (`@`-mentions)** and **tags/completion tracking** are
  both special embedded-token mechanisms layered on top of this same
  plain-text-plus-Markdown-render pipeline — see `ui-ux.md` for full
  behavior; the storage-format implications are:
  - A mention is a Markdown-link-shaped token: `[@ShortName](contact:{uuid})`.
  - A tag is a bare `word:value` token recognized by trigger word (`tag:`,
    `update:`), no bracket syntax needed.
  - Both are plain text as far as storage/sync/reduce are concerned —
    only the display layer treats them specially.

### Entry write model

An entry fragment file's JSON body is always an **array of one-or-more
entries** — one shape, no separate single-entry-vs-batch schema.

- **Filename = the UUID of the first entry *write* in the file** —
  `entry.{fragment-uuid}.json`. **Clarified during implementation
  (2026-08-01)**: for a brand-new entry, `fragment-uuid` and the entry's
  own persistent `uuid` field are the same value (they're minted
  together). For an **edit** of an already-existing entry, they are
  *not* the same — the fragment filename gets a **fresh UUID**, while the
  entry's own `uuid` field inside the JSON stays unchanged (that's what
  lets the reducer recognize it as a newer version of the *same* logical
  entry). Reusing the entry's persistent UUID as the filename for every
  edit was the original wording, but that's a real bug, not just
  imprecise phrasing: filenames are immutable and add-only, so a second
  write reusing the same name would always be rejected as a duplicate —
  even though an edit's content is genuinely different from the
  original, not an idempotent retry of it.
- **While connected**: every entry write (new or edited) is written
  immediately as its own file, containing exactly one entry.
- **While disconnected**: entries accumulate locally and, on reconnect,
  flush as one file containing everything accumulated (array of many) —
  filename in that case is the fresh fragment UUID described above,
  taken from whichever entry write happens to be first in the batch.
- **An edit is not a special case in every other respect** — it's just
  another entry write, following the same connected/disconnected rule as
  any other entry, just with the filename nuance above. Because fragments
  are never deleted or overwritten, full edit history falls out for free
  on disk, even though the UI only ever surfaces the latest version (see
  `ui-ux.md` — no "edited" indicator, no version history UI; recovery of
  a superseded edit is forensic/file-level only).
- **Idempotent retries**: because a genuinely-identical resend (the
  client unsure whether its last write actually landed) reuses the exact
  same fragment UUID it already generated for that attempt, a `PUT` retry
  after an uncertain network failure is still naturally idempotent — a
  409 "already exists" on retry with identical unsent content means
  "that already succeeded," not a real collision. This property only
  holds because the *same* client generates the *same* fragment UUID for
  a given logical write attempt, retried — not because the filename is
  deterministically derivable from the entry's identity across edits.

### Entry deletion semantics

- Trashing an entry is **soft delete / hide only** — the record stays in
  the JSON permanently, just hidden from the default view. A global "show
  all entries, including deleted" toggle reveals them again.

### Attachments

- Recorded in an entry's data, but **not synced/pulled to every client
  automatically** — fetched on-demand only when a client actually accesses
  one, directly from storage.
- **Schema**: per attachment, store the **original filename** and the
  **storage filename** as JSON attributes.
  - **Storage filename is UUID-only** (e.g. `attachment.{uuid}.png` —
    extension kept only as a content-type hint). The original filename is
    *never* used to construct a filesystem path (user-controlled text
    could contain unsafe characters or path-traversal sequences); it's
    stored purely as a string attribute, shown in the UI and restored on
    download/export.
  - **Stored in a dedicated subfolder**, `events/{event-uuid}/attachments/`
    — not mixed into the same folder as entry/metadata fragments, so the
    JSON-fragment listing endpoint never has to filter binary files out
    of its results.
- Full **export** of a journal or event bundles attachments too,
  serialized as blobs within the export JSON (see `api-and-sync.md` §
  Export & Import).

## Contact

Journal-scoped, not global — see `identity-and-security.md` for the full
rationale (self-containment, access-control simplicity) and the
auth/onboarding mechanics. Data shape:

| Field | Notes |
|---|---|
| `uuid` | Assigned at creation |
| `name` | Full name; optional |
| `short name` | e.g. "MattH"; optional; **not unique** — two contacts may pick the same one, accepted tradeoff |
| `email` | Optional in principle, but contact creation requires at least a name or an email |
| secret (hash only) | See `identity-and-security.md` — plaintext never stored/synced |
| `deleted`, `deleted_at`, `deleted_by` | See § Contact deletion below |

No privilege levels (see `identity-and-security.md`): any contact with
valid credentials for the journal can write an edit to any *other*
contact's record, not only their own — the UI doesn't restrict this
either. In practice this covers both self-service ("I was bulk-invited
by email only, let me fill in my own name") and an operator fixing up
someone else's details.

### Contact deletion

A metadata flag, not a real removal — deliberately consistent with
"nothing is ever discarded" elsewhere in this model. Deleting a contact
writes an ordinary edit fragment (same LWW mechanism as any other
contact edit) that:

- sets `deleted: true`, `deleted_at`, `deleted_by`
- clears `secret_hash` to `null`, which immediately invalidates their
  secret for future auth (`journ_verify_secret` treats a null hash as
  "no valid secret") — same mechanism `regenerate` already uses, just
  toward null instead of a fresh hash
- otherwise keeps every other field (`name`, `short_name`, `email`)
  untouched, so past entries authored by this contact keep displaying
  correct attribution rather than falling back to "Unknown"

The UI shows a deleted contact's row struck through with a single
**Reactivate** action in place of the usual edit/copy-link/send/
regenerate/delete set. Reactivate reuses the exact same "issue a fresh
secret" mechanism as regenerating an active contact's key — it just also
clears `deleted`/`deleted_at`/`deleted_by` — so undo is exact: same
write path, opposite direction, not a separate parallel feature to keep
in sync.

### Display name derivation

Computed **live at display time** from the current LWW-reduced contact
record — never stored/cached as its own field. Order, first non-null
wins:

1. Short name
2. First whitespace-delimited word of the full name
3. Text before the `@` in the email address

## Time handling

- All dates/times stored and reconciled internally as **UTC**. Display
  localizes to the viewer's timezone, with a per-user toggle for
  local-vs-UTC display (see `ui-ux.md`).
- **Two different time authorities, by design:**
  - Entry/record timestamps (used for record-level LWW) are **trusted as
    embedded/authored** by the client — no server-side correction.
  - File-level operations use server/filesystem time; where the two could
    conflict, server time wins for file-level concerns only.
- **Accepted risk**: a client with a skewed clock could have its
  entries/edits win LWW resolution incorrectly, with no automatic
  detection. Not solved; worth eventually recording server-received time
  alongside client-claimed time per fragment, purely to support future
  debugging, without changing merge behavior.

## Conflict vs. collision

- **Collision** (two unrelated writes accidentally being confused as "the
  same object") is what global UUIDs prevent, everywhere.
- **Conflict** (two different people deliberately editing the *same
  already-existing* object concurrently) is a different, unavoidable
  scenario UUIDs do nothing to prevent — handled by LWW + forensic
  recovery instead, by design. It happens in exactly two places:
  - **Event or journal metadata** — LWW picks a winner; the loser's
    version survives only as an unreferenced fragment on disk.
  - **An existing entry being edited** — LWW picks whichever edit is
    later; there is no "show all versions" UI for a superseded edit,
    unlike trashed entries — recovery is forensic/file-level only.

## On-disk layout (default backend)

```
{journal-uuid}/
  metadata.{uuid}.json     # one fragment per journal metadata create/edit (name, storage config)
  contact.{uuid}.json      # one fragment file per contact, per create/edit
  attic/                   # compacted journal-level fragments — see § Compaction below
  events/
    {event-uuid}/
      metadata.{uuid}.json          # one fragment per event metadata create/edit
      entry.{first-entry-uuid}.json # one or more entries; single online writes, or a catch-up batch after reconnect
      attachments/
        attachment.{uuid}.{ext}     # uuid-keyed blob storage; original filename kept only as a JSON attribute
      attic/                        # compacted fragments for THIS event, colocated rather than mirrored elsewhere
  attic/
    archived-events/
      {event-uuid}/           # a WHOLE event, moved here by the archive-event endpoint (one-way, leaves events/
                               # entirely so it stops being discoverable) — includes its own attic/ subfolder above
                               # if this event had ever been compacted before being fully archived
```

Compaction's attic is always a sibling `attic/` of whatever it's archiving — journal-level fragments land in `{journal}/attic/`, event-level ones in `{journal}/events/{uuid}/attic/` — never mirrored into one shared tree. This keeps an event's whole history (live + compacted) colocated, and means a later whole-event archive is a single directory move that naturally carries the compacted history along with it. The one exception is that whole-event-archive destination itself: it has to live outside `events/` (under the journal's own `attic/archived-events/`), since the event must stop being discoverable via `events/` at all, not merely gain an attic subfolder in place.

## Generalized write convention

**Every write of every kind, everywhere, uses the same one pattern**: a
fragment file named `{type}.{uuid}.json`, never overwritten in place.
Applies uniformly to journal metadata, event metadata, contacts, and
entries — no special-cased data type, no separate backup mechanism.

- Deliberately **not** "one mutable file per data type" (e.g. a single
  `metadata.json` or `contacts.json`) — a single shared mutable file would
  let two offline clients each overwrite the other's changes wholesale on
  sync. Fragment-per-write avoids that: every write is a new file, merged
  at the record level by last-writer-wins (see `api-and-sync.md` §
  Read/Merge Strategy).
- Because old fragments are never deleted or overwritten, **the backup/
  audit trail falls out for free** — the prior version of any record is
  simply the previous fragment, still sitting on disk.

## Format/schema versioning

Every fragment (journal metadata, event metadata, contact, entry) and the
export bundle format carry an explicit **version field**, so a future
format change has something to branch/migrate on. Adopted deliberately
early — this project is explicitly experimental and the format will
change; adding a version field now, before real data exists, is far
cheaper than retrofitting it later. Exact field name/shape is TBD at
implementation time; the requirement itself is locked in.
