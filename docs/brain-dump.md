# Brain Dump — Raw Design Notes

Running capture of ideas as they come up, in no particular order. This is raw
material, not a finalized spec. Once the brain dump is done, we'll move to
structured Q&A to pin down decisions and fill gaps, and promote settled
decisions into proper spec docs.

## 2026-07-30

### Client platform
- **Service workers** are the mechanism to make the app "resident" in the
  browser: install once (while online), then the app can be relaunched and
  used indefinitely with zero network, no server round-trip to boot.
- Pair with a **Web App Manifest** so the browser treats it as an installable
  PWA (icon, standalone window, no address bar).
- Local data storage: **IndexedDB** for entries/records.
- Known platform caveat: iOS Safari is the weak link for PWA background
  behavior / storage persistence guarantees — design should not assume
  anything happens while the app isn't actually open.
- **Persistent storage requested** *(resolved this round)*: the app asks
  the browser (`navigator.storage.persist()`) to deprioritize this
  origin's data for eviction under storage pressure — real risk given a
  device may go a long time between syncs. Essentially free to request
  (no cost to the device/other apps), but treated as a **mitigation, not a
  guarantee**: some browsers prompt the user for permission (minor
  friction risk if dismissed/denied), it's not airtight even when granted,
  and it's weakest on exactly the platform already flagged as the weak
  link (iOS Safari). The top-bar sync status icon (see UI/UX) remains the
  real backstop, not a redundant belt-and-suspenders.

### Sync model
- **No unattended/background sync required.** Sync only needs to happen
  while the app is open in an active tab.
- Sync is **opportunistic**: the client periodically/occasionally checks
  whether a configured "endpoint" is reachable, and if so, syncs.
- Data volume is expected to be small (not big-data scale) — this simplifies
  a lot of design choices (e.g. can afford simpler diff/pull logic rather
  than needing sophisticated delta sync).

### Data format & sync mechanics
- All data is represented as **JSON files**.
- On connect, the client lists what files exist on the server/storage and
  compares against what it has locally; it pulls new/modified files it
  doesn't yet have.
- The client tracks **provenance** — which local file/record originated
  from which server file — so it can tell its own new/updated data apart
  from data it already pulled, and knows what still needs to be pushed.
- When the client has new/updated data, it pushes it to the server as
  (a) new JSON file(s).

### Storage/server backend
- Default backend: a **lightweight custom HTTPS service** (not raw dumb
  object storage) that stores the JSON structures server-side. This is the
  priority backend, built alongside the app itself.
  - **Add-only**: clients can create/write files; there is **no delete
    capability** exposed to clients at all.
  - Because it's a real (if minimal) service rather than raw storage, it
    can enforce small bits of app-level logic — e.g. per-contact secret
    validation on write (see Identity, below). This is *not* guaranteed to
    be portable to other backend types.
- Alternate backends (e.g. **S3-compatible object storage**) are a lower
  priority — "cross that bridge when we come to it." Anything backend
  fundamentally needs to support at minimum: list files, get file, put
  file.

### Data model — object hierarchy

Three levels, nested:

1. **Journal** (outermost container)
   - Globally unique UUID, assigned at create time, **immutable**.
   - Metadata is deliberately sparse: `name` (the only field that can be
     changed after creation) plus storage location/backend config. There
     is no "app-wide config" beyond that — general app settings (e.g.
     theme, timezone display preference) are **client-side/browser-local
     only**, never synced journal data.
   - **Storage location and backend config are baked in at creation and
     cannot be changed** except via export/import to a new journal. This is
     deliberate: prevents one operator from repointing storage to something
     invalid and disconnecting everyone else.
   - Contains a **contact list** — people associated with the events being
     journaled (see Identity & contacts, below).
   - Contains one or more **events**.
   - **No lifecycle/state.** A journal is never "closed." The only way to
     retire one is an out-of-band server-side directory delete (not an
     in-app operation).

2. **Event**
   - Globally unique UUID at create time.
   - Metadata: start date/time, creator (a reference to a contact-list
     person), and a **free-text description** (same treatment as entry
     text — resolved 2026-07-31, e.g. an "objective/what this event is
     about" statement distinct from the chronological entry log).
   - Contains a collection of **entries**.
   - **Multiple events may be open concurrently** within a journal — events
     are not implicitly sequential.
   - **A brand-new journal starts with zero events** — no auto-created
     first event. An explicit "new event" action is required before any
     entry can be posted.

3. **Entry**
   - Globally unique UUID at create time (for cross-file dedup/edit
     matching — see Read/merge strategy).
   - Date/timestamped, plain text content entered by a person as the event
     progresses.
   - Must support **attachments** (see below).

### Format/schema versioning *(adopted this round)*

- Every fragment (journal metadata, event metadata, contact, entry) and
  the export bundle format carry an explicit **version field**, so a
  future format change has something to branch/migrate on. Given this is
  explicitly experimental and the format will change, adding this now
  (while there's no real data in the wild yet) is far cheaper than
  retrofitting it later. Exact field name/shape TBD when we get to
  implementation, but the requirement is locked in.

### On-disk layout (default backend)

```
{journal-uuid}/
  metadata.{uuid}.json     # one fragment per journal metadata create/edit (name, storage config)
  contact.{uuid}.json      # one fragment file per contact, per create/edit
  events/
    {event-uuid}/
      metadata.{uuid}.json          # one fragment per event metadata create/edit (start time, creator, open/closed state)
      entry.{first-entry-uuid}.json # one or more entries; single online writes, or a catch-up batch after reconnect
      attachments/
        attachment.{uuid}.{ext}     # uuid-keyed blob storage; original filename kept only as a JSON attribute, never in the path
  attic/                    # compacted/archived fragments and archived events; entirely outside the list/get API, invisible to all clients, manual-recovery-only
```

### Generalized write convention

- **Every write of every kind, everywhere, uses the same one pattern:**
  a fragment file named `{type}.{uuid}.json`, never overwritten in place.
  This applies uniformly to journal metadata, event metadata, contacts,
  and entries — there is no special-cased data type and no separate
  backup mechanism.
- This is deliberately **not** "one mutable file per data type" (e.g. a
  single `metadata.json` or `contacts.json`) — a single shared mutable
  file would let two offline clients each overwrite the other's changes
  wholesale on sync. Fragment-per-write avoids that: every write is a new
  file, and **one generalized reducer** (see Read/merge strategy) merges
  fragments at the record level using last-writer-wins.
- Because old fragments are never deleted or overwritten, **the backup/
  audit trail falls out for free** — no explicit "keep a backup copy on
  metadata update" step is needed; the prior version is simply the
  previous fragment, still sitting on disk.

### Entry write model *(superseded — see below, now resolved)*

~~Time/count/edit-triggered session-file rotation (1hr / 100 entries /
on-edit).~~ Superseded: bandwidth was the main rationale for batching
entries into a rotating session file, and that rationale has been
explicitly dropped (see Protocol design decisions, below) — the network is
treated as either present or absent, not something to optimize for at low
quality. Replaced by a simpler connected/disconnected split:

- **One file shape, always**: an entry fragment file's JSON body is always
  an **array of one-or-more entries**. No separate single-entry vs. batch
  schema.
- **Filename = the uuid of the first entry in the file** —
  `entry.{first-entry-uuid}.json`. No separate session/batch uuid concept.
- **While connected:** every entry (new or edited) is written immediately
  as its own file, which happens to contain exactly one entry.
- **While disconnected:** entries accumulate locally, and on reconnect are
  flushed as one file containing everything accumulated (array of many).
- An edit is not a special case — it's just another entry write, same rule
  as any other entry. Full edit history still falls out for free, since
  fragments are never deleted or overwritten.
- Emergent property: because the filename is content-derived, a `PUT`
  retry after an uncertain network failure (client unsure if the write
  landed) is naturally idempotent — a 409 "already exists" on retry with
  identical unsent content means "that already succeeded," not a real
  collision.
- Minor accepted tradeoff: a catch-up file with many entries is named
  after only the first one, so the filename alone doesn't hint how many
  entries it contains. Fine, just noted as a conscious choice.

### Protocol design decisions

- **Server is a dumb pipe for sync** — no server-side JSON parsing/reduction
  on the client-facing sync path. Whole files only, delivered as-is; the
  client always does the reduce/dedup locally.
- Because fragments are **immutable once written** (never overwritten), a
  **filename alone is a sufficient cache key** for sync — no ETags/content
  hashes needed. Sync API is just:
  - `GET /journal/{uuid}/list` (and per-event) → array of filenames.
  - `GET /journal/{uuid}/{filename}` → raw file bytes.
  - `PUT /journal/{uuid}/{filename}` → create; reject if it already exists.
  Client diffs the filename list against what it already has and fetches
  only what's missing.
- **Explicitly not designing for low-quality/slow links** — connectivity is
  treated as binary (present or absent), not a spectrum to optimize
  bandwidth for.
- **Compaction is the one deliberate exception** to "no server-side JSON
  parsing": it necessarily runs the same reducer logic as the client, in
  order to consolidate fragments. This is intentional, not a contradiction
  of the dumb-pipe principle for the sync path.
- Compaction must be **safe to run concurrently/redundantly** without
  corruption — two writers noticing the threshold at once and both
  triggering compaction should be harmless, since compaction only reads
  current state and writes new files; "archiving" old fragments means
  copying them aside (`attic/`), never removing something a concurrent
  writer might still depend on.

### Client/backend coupling & hosting model

- A client is **coupled to one storage backend from first connection
  onward** — no server-switching within a single app install. The backend
  a given journ **installation** (app + default backend, deployed
  together) uses is configured in that installation's own server-side INI
  config, not chosen per-journal by the user.
- **Platform consequence (not a problem, just explicit):** because PWA
  installs and browser storage (IndexedDB) are scoped **per origin**, a
  browser used against multiple journ deployments on different URLs ends
  up with **multiple separate installed app icons**, one per origin, each
  with its own isolated local storage. There is no single app instance
  that lists journals spanning multiple servers — that's a natural
  consequence of the web platform, not a gap.
- **No sneakernet / peer-to-peer sync.** The server is the one mandatory
  rendezvous point — two clients that never both reach the same server
  cannot exchange data with each other directly. This narrows the purpose
  of export/import (below) to **backup/portability only**, not a sync
  bridge.

### Sync trigger mechanism

- Sync fires **on every entry post** (send the update, and in the same
  round trip check for anything new waiting to be pulled).
- Additionally, a **timer** performs the same check periodically while the
  app is open.
- Cheap check mechanism: the server tracks the **timestamp of the most
  recent write** to a journal (across all its fragments) and exposes it
  via a lightweight endpoint. The client compares that against its own
  last-synced timestamp — only does a real listing/diff if the server's
  value is newer. This single endpoint doubles as the "is the endpoint
  reachable" check from the cross-cutting server responsibilities noted
  earlier (no separate plain ping endpoint needed). Scoped **per-journal**
  (not per-event) — simplest thing that works, since a full listing is
  cheap once you know something changed.

### Conflict vs. collision (terminology + where conflicts actually happen)

- **Collision** (two unrelated writes accidentally being confused as "the
  same object") is what global UUIDs prevent, everywhere.
- **Conflict** (two different people deliberately editing the *same
  already-existing* object concurrently) is a **different, unavoidable**
  scenario that UUIDs do nothing to prevent — it's handled by LWW +
  forensic recovery instead, by design. It genuinely happens in exactly
  two places:
  - **Event or journal metadata** — two people concurrently edit the same
    event/journal's metadata (or one edits while another closes it). LWW
    picks a winner; the loser's version survives only as an unreferenced
    fragment on disk.
  - **An existing entry being edited** — two people concurrently edit the
    *same* entry. LWW picks whichever edit is later; unlike trashed
    entries, there is **no "show all versions" UI** for a superseded edit
    — recovery is forensic/file-level only.

### Time handling

- All dates/times stored and reconciled internally as **UTC**.
- UX layer localizes to the viewer's local timezone for display only.
- **Two different time authorities, by design:**
  - Entry/record timestamps (used for record-level last-writer-wins) are
    **trusted as embedded/authored** by the client — no server-side
    correction.
  - File-level operations use **server/filesystem time**.
  - Where the two could conflict, **server time wins** for file-level
    concerns; entry content's authored time is trusted as-is regardless of
    client clock accuracy.
- **Accepted risk:** a client with a skewed clock could have its
  entries/edits win LWW resolution incorrectly, with no automatic
  detection. Not solved now; worth eventually recording server-received
  time alongside client-claimed time per fragment, purely to support
  future debugging/auditing, even without changing merge behavior.

### Event lifecycle: open / closed

- An event can be **closed**, which records an end-of-event timestamp.
- Once closed, **no new entries can be added**, and no edits/trashes of
  existing entries either — a closed event is fully frozen from the
  client UI's perspective.
- Closing toggles the UI control to **"re-open"** (closed is reversible).
- When closed, the entry-creation form is grayed out/disabled in the UI.
- **Metadata changes to an event (including close/re-open) get recorded as
  entries too** — so the entry timeline doubles as an audit log of
  metadata-level changes, not just user-authored text.
- **The server has no opinion about open/closed state and enforces
  nothing** — consistent with the dumb-pipe design, it just accepts
  whatever writes arrive. Clients enforce the freeze locally, but since
  the closed state itself propagates via ordinary eventually-consistent
  sync, a client that hasn't yet learned an event closed could still
  legitimately write an entry moments before the closure reaches it —
  a genuine race, not a bug. Resolution: late-arriving entries like this
  are simply **merged into the timeline normally** once synced, consistent
  with "nothing is ever discarded" — no special rejection/quarantine logic
  for entries that lost the race with a close.

### Event archive (distinct from entry-level trash)

- Unlike entry trashing (a metadata flag flip via ordinary LWW fragment
  write), archiving an **entire event** means physically moving all its
  fragments into an attic-style location — a real file move, which
  ordinary clients cannot do under the add-only backend model.
- This needs its own **dedicated privileged backend endpoint** ("archive
  event X"), the same category of server-side operation as compaction —
  not a plain fragment write.
- **Resolved:** one-way from the app's perspective, same as compaction's
  attic. Once moved into `attic/`, content is **entirely outside the
  list/get API surface** — invisible to every client, not part of the
  app's data model at all. No in-app restore feature. Recovery is manual
  and out-of-band only: someone with direct storage/filesystem access digs
  into the attic and moves files back by hand. This applies uniformly to
  both compacted-away fragments and archived events.

### Metadata mutability & conflict strategy

- No privilege levels or permissions — all operators are equal.
- Editable metadata (journal name, event metadata, etc.) uses **last-writer-wins**:
  whoever's change has the latest timestamp wins once all clients have
  pulled/reconciled.
- Written via the generalized fragment convention (see above) — every edit
  is a new `metadata.{uuid}.json` fragment, reduced by LWW. This makes
  changes inherently recoverable (prior fragments are never deleted) without
  any dedicated backup step.
- Journal-level metadata is mutable only for `name` — everything else
  (UUID, storage location/config) is fixed at creation.

### Identity & contacts

- No privilege levels — identity is deliberately loose, not a security
  boundary by itself.
- A brand-new journal starts with **zero contacts**. Before the first
  event can be created, the user is prompted to either import an existing
  `contacts.json`-style export or create the first contact (themselves).
- Contact fields: **name, uuid, email**, plus a **secret credential** used
  for backend write authentication (see Onboarding & auth, below).
- Contacts are dynamic — people can be added directly via contact
  management, or implicitly as they're referenced (e.g. as an event
  creator).
- Written using the generalized fragment convention: `contact.{uuid}.json`
  per contact, per create/edit — **not** a single mutable `contacts.json`
  (see Generalized write convention, above).

### Onboarding & auth

- **Auth model:** effectively "possession of a credential for the storage
  backend," plus (new, this round) a **per-contact secret** for
  finer-grained write auth against the default backend specifically.
- **Invite mechanism:**
  - Each contact gets a unique **invite URL**, generated from the contact
    management page, encoding the contact's uuid + the journal's uuid.
  - **Bulk invite**: paste a list of emails → creates a contact per email,
    optionally sends each an invite email containing their personal invite
    URL.
  - Per-contact UI actions: **copy invite URL** (link icon) and **send
    invite email** (icon).
  - **Multi-device use is expected and fine**: the same contact can reuse
    the same invite URL/secret across multiple devices (e.g. phone +
    laptop) simultaneously — no per-device credential needed. Entries
    written from either device are still just ordinary UUID-keyed writes,
    so there's no collision risk from the same secret being active in two
    places at once.
  - **Shared-device identity switching is already covered by this same
    mechanism** — no new feature needed. On a shared ops-room workstation,
    switching "who's currently posting" is just: go to contact management,
    open the next person's invite URL. A little clunky for rapid handoffs,
    but workable and consistent with everything else.
  - Email delivery is configured via a **server-wide INI config** for the
    whole install (same pattern as the `simplewebauth` project) — not
    per-journal config.
- **Per-contact secret / lockout** (new, this round): contacts get a real
  secret credential, with a **"regenerate key"** action per contact that
  invalidates the old secret — the old holder is locked out on their next
  API call. Primary purpose is **being able to lock someone out**, not
  preventing impersonation (impersonation is explicitly not a major
  concern given the no-privilege-levels model).
  - **Resolved:** only a **hash** of the secret is stored in the synced
    `contact.{uuid}.json` record. The plaintext secret is only ever handed
    to the contact themselves, embedded in their invite URL/email. The
    default backend validates incoming write requests by hashing the
    presented secret and comparing to the stored hash. "Regenerate key"
    replaces the stored hash, invalidating the old plaintext secret
    everywhere immediately (locks out the old holder on their next API
    call) without ever having exposed the new or old plaintext value to
    other clients.

### Attachments

- Recorded in an event's metadata, but **not synced/pulled to every client
  automatically** — only fetched on-demand when a client actually accesses
  them, directly from the storage location.
- Exception: full **export** of a journal or event bundles attachments too,
  serialized as blobs within the export JSON, for portability/backup
  purposes.
- **Schema (this round):** an entry references its attachments by storing,
  per attachment, the **original filename** and the **storage filename**
  as JSON attributes.
  - **Storage filename is uuid-only** (e.g. `attachment.{uuid}.png`,
    extension kept for content-type hints) — the original filename is
    never used to construct a filesystem path, since it's user-controlled
    text that could contain unsafe characters (spaces, unicode, path
    traversal sequences like `../`). The original filename is stored
    purely as a string attribute, shown in the UI and restored on
    download/export, but never trusted as part of a path.
  - **Stored in a dedicated subfolder** (`events/{event-uuid}/attachments/`),
    not mixed into the same folder as entry/metadata fragments — since
    attachments are deliberately excluded from the auto-sync/list-and-pull
    flow, this keeps the JSON-fragment listing endpoint from ever having
    to filter binary files out of its results.

### Entry content & formatting *(resolved this round)*

- Entries remain **plain text in storage and in the input box** — no new
  schema, no rich-text editor component, Enter-to-post UX unchanged.
- **Display** renders that plain text through a small, well-known
  **Markdown library**, in its safe/sanitized mode (required — entries
  from one contact are displayed in every other contact's browser, so
  unsanitized rendering would be an XSS risk). Gets bold/italic/lists/links
  from simple, optional syntax, plus **auto-linking of bare URLs** with
  zero syntax needed. Text with no special syntax just displays as plain
  text, as always — nobody has to learn Markdown to use the app.
- **Unicode/emoji fully supported**, effectively for free — JSON strings
  and browser text inputs are Unicode-native. Only requirement is
  consistent **UTF-8** end-to-end (client storage, server file writes,
  HTTP response headers/content-types).
- **Caveat for later:** many emoji are multiple Unicode codepoints
  stitched together (skin tones, flags, ZWJ sequences). If any future
  feature truncates/previews entry text, it must truncate by grapheme
  cluster ("visual character"), not by naive string-length slicing, or it
  can cut an emoji in half and render something wrong. No truncation
  feature exists yet — just flagging so it's not a surprise later.

### Export & import *(resolved this round)*

- **Journal-creation-time UUID collision errors out.** Importing into a
  target that already has a journal at that UUID is rejected — no merge
  attempted at the journal-creation level. (No missed use case here: this
  only blocks re-creating an already-existing journal, not restoring one
  onto a fresh/empty target, which works fine since there's nothing to
  collide with.)
- **Fragment-level replay is always safe, no special "merge mode" needed.**
  Because every fragment write is uuid-named, immutable, and already
  idempotent (re-`PUT`ing something that already exists just 409s
  harmlessly), an export's fragments can always be safely replayed against
  *any* target — new or already-existing — through the ordinary write
  endpoint. This covers **partial recovery** (an old export has fragments
  a live journal is currently missing, e.g. after storage damage) for
  free, with zero dedicated import-merge logic.
- **Clone via "Import as new UUID"**: a checkbox on import that mints a
  fresh journal UUID and replays all fragments under it. Event/entry UUIDs
  inside the clone stay identical to the original — confirmed safe, since
  nothing in the API ever compares UUIDs across journal boundaries; two
  journals with internally-duplicate event/entry UUIDs never collide.
  - **Resolved:** cloning does **not** carry over original secret hashes.
    Every imported contact gets a **freshly auto-regenerated secret** as
    part of the clone operation itself (run under the bootstrap secret,
    same authority as any new journal's initial setup) — not cleared to
    nothing. This avoids a bootstrapping gap (clearing to nothing would
    leave literally nobody, including the importer, with working write
    access until someone regenerates a key — which is itself a write).
    With auto-regeneration, every contact already has a valid secret the
    moment the clone finishes; remaining manual work after cloning is pure
    **distribution** (copy/send invite per contact), not regeneration.
    None of the original journal's secrets grant any access to the clone.

### Contacts: short name + display name derivation *(resolved 2026-07-31)*

- New contact field: **short name** (e.g. "MattH"), alongside existing
  name/email/uuid/secret.
- **Display name derivation order** (used whenever a field is null — most
  common right after a bulk email-list invite, where only email is
  known): (1) short name, (2) first whitespace-delimited word of the full
  name, (3) text before the `@` in the email address.
  - **Computed live at display time** from the current LWW-reduced contact
    record, never stored/cached — only the fields themselves are stored,
    so once someone sets a short name it updates everywhere it's
    displayed on the next client sync, automatically.
  - **No-email edge case**: manually creating a contact with only a name
    (no email) is fine — tier 2 (first word of name) already covers it, so
    there's no unreachable case in practice as long as contact creation
    requires at least a name or an email.
- **Accepted, not fixed**: none of these fields are unique — two contacts
  could both pick "MattH." Consistent with the rest of the design's loose
  identity model; not treated as a bug.

### Contact chips (@-mentions in entries) *(resolved 2026-07-31)*

- **Storage**: a mention is just a Markdown-link-shaped token embedded in
  the plain-text entry — `[@ShortName](contact:{uuid})` — no schema
  change, entries stay 100% plain text.
- **Display**: extends the existing sanitized-Markdown render pass with
  one additional rule — links using the `contact:` scheme render as a
  hoverable **"contact chip"** (with actions) instead of a plain
  hyperlink, rather than a new rendering system.
- **Resolution is UUID-based, not name-based** — the token captures the
  selected contact's UUID at selection time, not just their typed
  shortname, so a mention stays unambiguous even though shortnames aren't
  unique.
- **Input (v1 approach, chosen for now)**: plain `<textarea>` stays
  unchanged (no new editor dependency) — `@` triggers a filtered
  autocomplete dropdown (positioned via the standard caret-position-
  measuring technique for textareas), and selecting a contact splices the
  bracket-token into the text. Tradeoff, accepted: while composing, the
  raw `[@ShortName](contact:{uuid})` syntax is visible as literal text,
  becoming a chip only once rendered — consistent with how a bare URL
  already behaves in this design.
  - **Autocomplete dropdown shows name and email alongside short name**,
    specifically to help disambiguate when short names collide — doubles
    as a soft nudge encouraging people to pick unique short names.
- **Explicitly deferred, not v1**: a richer `contenteditable`-based
  editor giving true live chip-rendering while typing (closer to the
  Slack/ServiceNow feel) — noted as worth "auditioning" later once there's
  real experience with the plain-textarea version, not a commitment now.

### Tags & completion percentage *(resolved 2026-07-31)*

- **General parsing rule**: scanning entry text for whitespace-delimited
  words containing an embedded colon (`word:value`). If the word before
  the colon matches a known special trigger ("tag", "update" — extensible
  later), it gets special treatment instead of being left as plain text.
  - Trigger word matching is **case-insensitive** (`Tag:`/`TAG:` work too).
  - **Multiple occurrences per entry are fine** — an entry can contain
    several `tag:` words (e.g. `tag:rush tag:critical`), each becomes its
    own chip.
  - Trailing punctuation attached to the value gets stripped (e.g.
    `tag:rush,` in a sentence doesn't capture the comma).
- **`tag:sometext`** — free-text tag word, rendered as an inline **chip**
  in place of the raw `tag:sometext` text (same rendering mechanism as
  contact mentions — reuses the existing Markdown-render extension point,
  not a new system).
  - **Colors/behavior are config-driven**, one section per tag word:
    ```ini
    [tag:critical]
    fg = #ffffff
    bg = #cc0000
    highlight_row = true

    [tag:decision]
    fg = #000000
    bg = #ffe0b3
    highlight_row = false
    ```
    `highlight_row` (default: false/chip-only) controls whether the
    *entire entry row* gets tinted, not just the chip.
  - **A handful of well-known tags ship as code defaults** (rush,
    critical, action, decision) — **overridable and extensible** via the
    same server-wide INI config used elsewhere (email, bootstrap/recovery
    secrets).
  - **`[tag:default]`** is a reserved section in the same mechanism —
    the fallback style for any tag word that isn't otherwise recognized
    (built-in or INI-configured). Example default: pale blue background,
    black text. Not a special-cased hardcoded fallback — just an ordinary
    tag definition matched when nothing else matches, so it gets
    `fg`/`bg`/`highlight_row` like any other tag. By convention it's
    placed last in the config (see ordering, below), though nothing
    enforces that.
  - **Declaration order in the config file is the precedence order** —
    matters specifically for resolving **row-highlight conflicts**: if an
    entry contains multiple tags that each set `highlight_row = true` with
    different colors, whichever is declared first in the config wins the
    row's color. Each tag's own **chip** always keeps its own individual
    color regardless — precedence only arbitrates the single shared
    row-color slot.
  - **Implementation note**: whatever INI-parsing approach is used must
    explicitly preserve declaration order — some config-parsing libraries
    silently don't (e.g. alphabetize keys), which would quietly break
    precedence without anyone noticing until two conflicting tags appear
    in the same entry.
- **`update:N` or `update:N%`** (the `%` is optional, both mean the same
  thing) — tracks a **0–100% completion level for the current event**.
  - Rendered as a chip too, but with a **fixed neutral color** (e.g. light
    brown), *not* part of the configurable tag-color system — it's
    functionally different from `tag:` (drives a numeric gauge, not a
    categorical label), so it doesn't need config-driven styling.
  - **Value resolution**: the event's displayed percentage is whichever
    `update:` value comes from the entry with the **latest effective
    timestamp** among *all* entries in the event containing one — not
    "highest value seen" or "most recent entry overall." The percentage
    can legitimately move backward over time as a situation is
    reassessed. Uses the same effective-timestamp rule that governs LWW
    everywhere else, so an edited entry's value re-orders naturally.
  - **Malformed/out-of-range values**: clamped to 0–100 for display;
    anything that doesn't parse as a number is silently ignored.
  - **If no entry in an event ever contains `update:`, the app stays
    silent about it** — no percentage shown at all. Not every event has
    (or needs) a completion measure.
  - Once at least one valid `update:` exists anywhere in the event, the
    percentage is shown **prominently on the top status bar**, scoped to
    the currently-selected event (consistent with how start time/duration
    are already shown per-selected-event, not per-journal).

### Entry deletion semantics

- Deleting ("trashing") an entry is a **soft delete / hide only** — the
  record stays in the JSON permanently, just hidden from the default view.
- A global "show all entries, including deleted" toggle reveals them again.

### UI/UX

- **Single-page app.**
- **Top menu bar**: journal selector (dropdown) and event selector
  (dropdown); each dropdown has a "New" option that pops open a modal for
  entering metadata and triggers the backend create operation.
  - Top bar also shows: event start time, and a **live running duration**
    computed from the start timestamp (ticks while the event is open).
  - Top bar also has a **dark/light mode toggle icon** (dark is default).
  - **Sync status icon, right side of the top bar** *(new this round)*:
    **synced** (no pending local writes, server reachable), **syncing**
    (actively pushing/pulling), or **offline** (server unreachable — may
    have entries queued locally waiting to go out). Direct visibility into
    "is my data actually safe on the server yet," given the whole premise
    is intermittent connectivity.
- **Entry creation area** (shown once inside an event): a simple text
  entry box at top, a small attachment drop/click-to-browse area, and a
  "Post" button. Hitting **Enter** in the text area also posts. Design
  intent: creating an entry should be as fast/frictionless as possible.
  - When the event is closed, this whole entry-creation area is
    grayed out/disabled.
- **Entries table**, below the entry-creation area, most-recent-first:
  - Left column: date/time (localized for display, stored as UTC).
  - **Author column** — who wrote the entry (contact name). *(Added this
    round — was missing from the original table spec despite being
    essential for a multi-operator journal.)*
  - Center column: entry text + icons representing any attachments.
  - Right column: small action icons — **edit** (pencil) and **delete**
    (trashcan, soft-delete only — see above).
  - **No "edited" indicator** — an edited entry displays identically to
    one that was never touched. Edit history remains forensic/file-level
    only, by design (see Conflict vs. collision).
- **Edit icon** (pencil) sits next to the currently-selected journal and
  event in the top-bar pulldowns — one shared entry point for editing
  metadata on whichever is selected.
  - **Reuses the same modal/form** as "new journal" / "new event"
    respectively, pre-filled with current values, submitting as an update
    rather than a create.
- **Global operations** live at the very bottom of the page: import/export,
  and the "show all entries including deleted" toggle.
- **Contact management page**: per-contact copy-invite-link icon,
  send-invite-email icon, and regenerate-key icon (lockout); bulk invite
  via pasted email list.

### Read/merge (de-duplication) strategy

- Multiple clients can concurrently write new event/entry JSON files, or
  files could get duplicated at the storage layer.
- Reconciliation approach: **read all JSON files in, and deduplicate into a
  single in-memory structure using last-written timestamp** as the
  tiebreaker for any given record.
- This needs to be a **generalized function** — one reconciliation/reducer
  that works uniformly across journal metadata, event metadata, contacts,
  and entries, not bespoke logic per object type. This exact same function
  is reused in three places: client cold-load, client post-sync refresh,
  and server-side compaction.

### Client-side caching strategy *(resolved this round)*

- **"Full reduce, then cache the result"** — not true incremental/delta
  merging. Whenever new fragments arrive (sync pull or local write), the
  client reruns the **same full reducer** over the complete fragment set
  and persists the output locally as a cache; reads in between (e.g.
  re-rendering the same screen) just load the cached result rather than
  recomputing.
- Deliberately **not** building a separate incremental delta-merge code
  path that patches just the changed records into a long-lived view — that
  would be a second implementation that has to stay perfectly semantically
  identical to the full reducer forever, a real drift/correctness risk for
  no real benefit, since data volume is assumed small enough that a full
  reduce is cheap to redo whenever fragments actually change.
- Same-device, multi-tab redundant reprocessing is an accepted edge case —
  no cross-tab coordination logic planned. Browser storage is shared
  same-origin, so nothing actually breaks, just possibly some duplicated
  work.

### Server-side (default HTTPS backend) — responsibility map

The default backend service has four jobs: serve the app, act as the
storage backend, send invite emails, and run compaction. Breaking that
down further:

**Serve the app**
- Static hosting for the PWA bundle (HTML/JS/CSS/icons/manifest).
- Needs careful cache headers on the service worker file itself (must not
  be aggressively cached, or installed clients never see updates) plus a
  versioning signal so an offline-installed client can tell a new app
  version exists.
- **Update UX (resolved):** never auto-applied. New version detection
  surfaces as a prominent, dismissible **"New version available"** button
  on the top bar — user-initiated refresh only, never a forced/silent
  reload. Deliberately conservative given the app may be in active use
  mid-incident.

**Storage backend (JSON fragment sync API)**
- **List/changes endpoint** — given a journal (and optionally an event),
  return what fragment files exist, so a client can diff against what it
  already has without re-downloading. This is the core sync protocol
  contract and still needs real design (not detailed yet).
- **Get fragment** — fetch one file's contents.
- **Create fragment** — write a new `{type}.{uuid}.json`. Server must
  enforce, not just trust the client: reject if a file already exists at
  that exact name (no overwrite), reject malformed paths/names, no delete
  endpoint exists at all.
- **Create journal** — bootstrap endpoint: **one atomic call** that
  allocates the journal-uuid folder, writes the first metadata fragment,
  and writes the initial contact set with already-working secrets. Gated
  by the bootstrap secret (see Auth bootstrap, below).

**Attachments**
- Separate from the JSON fragment sync path — own upload/download
  endpoints, uuid-keyed like everything else. Wants basic size/type limits
  since it's the one place arbitrary-size blobs enter the system.

**Auth bootstrap** *(reworked — was flagged "clunky," revised 2026-07-30)*
- A **global secret**, set in the same server-wide INI config used for
  email settings, gates **journal creation**. This is what bootstraps
  trust before any per-contact secret exists to check against.
- **One atomic operation, not two.** The original design had "create
  journal" and "create the first contact" as separate steps, leaving a
  dangling in-between state (a journal that exists with zero working
  contacts, indefinitely writable by the bootstrap secret until someone
  finishes setup — an orphan-prone limbo state with fuzzy-scoped bootstrap
  authority). Reworked: a **single bootstrap-secret-authenticated request**
  creates the journal folder, writes its metadata, *and* writes its
  initial contact set with already-working (non-null) secrets, atomically
  — "just the creator" for a fresh journal, or the full auto-regenerated
  imported list for a clone/import (see Export & import). By the time the
  call returns success, at least one distributable working secret already
  exists. No in-between state, no orphaned partial journals.
- **Bootstrap secret scope is now trivial to state precisely**: it
  authorizes exactly one thing — minting a brand-new journal via that one
  atomic call — and has zero authority over anything, including that same
  journal, a moment later.
- **UX: a shareable link, not a raw secret** — same pattern as contact
  invites. An admin generates a "create new journal" link once (embedding
  the bootstrap secret, same as a contact invite URL) and hands it to
  whoever's allowed to start new incidents. End users click/bookmark a
  link; nobody manually copies a secret out of server config.
  - **Intended workflow, confirmed**: the link mints a **brand-new
    journal every time it's used** — unlike a contact invite link,
    there's no coordination between separate clicks. The intended usage
    is exactly **one person creates the journal, then invites everyone
    else in** via ordinary contact invite links — not everyone
    independently clicking "create" for what they think is the same
    incident (that would produce separate, disconnected journals).
- Once a journal exists, its contacts' per-contact secrets (hash-checked,
  see Onboarding & auth) govern all further writes to that journal.
- "Regenerate key" replaces a contact's stored hash — still open whether
  this requires anything beyond generic write access to the journal, given
  the no-privilege-levels model (leaning toward: no extra gate needed,
  consistent with "everyone equal").
- **Accepted as good-for-now, not final** — flagged as a candidate for a
  better mechanism later if one presents itself; not blocking further
  design work.

**Break-glass recovery** *(new this round)*
- Closes a real gap: if a journal's contacts are all locked out (most
  realistically, its sole contact loses their device/secret), there was no
  path back in at all — the create-journal bootstrap secret's authority is
  already spent and never covers existing journals.
- The **non-solo case needs no new mechanism** — any other contact who
  still has working access can already regenerate + resend the locked-out
  person's invite via ordinary contact management.
- For **total lockout**, a **second, distinct secret** in the server-wide
  INI config (separate from the journal-creation secret — different risk
  levels: journal-creation only ever produces new empty journals, this one
  can reach into any existing journal's data, so it's meant to be held
  more tightly, e.g. only the server operator).
- A narrow, single-purpose, atomic action: given a journal UUID, add one
  new emergency contact with a working secret. Does **not** restore or
  reactivate any of the old dead secrets — those stay dead, same as
  ordinary regeneration. Just seeds one working foothold; the recovered
  contact then uses normal contact-management tools to bring everyone else
  back in.
- **Deliberately not given the nice shareable-link UX** that
  journal-creation got — meant to be rare, high-trust, manually operated.
  Friction here is intentional, consistent with how compaction/attic
  recovery is already treated as manual/out-of-band rather than polished
  in-app UX.
- Not a new risk category — same "possession of a secret grants access"
  pattern already accepted everywhere else in this design (identity here
  was never meant to be a strong security boundary).

**Invite emails**
- SMTP config via the server-wide INI (matches `simplewebauth` convention).
- Send-single and send-bulk endpoints; builds the invite URL and email.

**Compaction** *(resolved this round)*
- Reduce-and-archive job reusing the same reducer logic as clients.
- **No scheduler.** Triggered opportunistically by any server-side write
  operation that notices a folder's fragment count has crossed a threshold
  — the next write after the threshold is crossed kicks off compaction.
  Must be safe to trigger concurrently/redundantly (see Protocol design
  decisions).

**Archive event** *(resolved)*
- A dedicated privileged endpoint, same category as compaction: physically
  moves an entire event's fragments into an attic-style location. Not
  achievable via the ordinary add-only fragment write path — regular
  clients can't move/delete files, so this has to be real server-side
  logic triggered by a request.
- One-way, no restore endpoint (see Event archive, above).

**Cross-cutting**
- Combined reachability + freshness check endpoint (see Sync trigger
  mechanism, above): returns the timestamp of the journal's most recent
  write. Doubles as both the "is the endpoint reachable" check and the
  "is there anything new" check in one cheap call — no separate plain
  ping endpoint needed.
- Basic abuse limits (max upload size, maybe rate limiting) — placeholder,
  low priority for now.

### Parked for later (not designing yet, just noting)

- Compaction's threshold trigger value (how many fragments is "too many")
  is not chosen yet.

## 2026-07-31

### Live entries table updates *(resolved)*

- New synced entries just **appear live** in the entries table as they
  arrive — no special scroll-preservation/"N new entries" handling for
  now. Deliberately deferred: revisit once there's real UX experience to
  judge whether it's actually disruptive in practice, rather than
  designing for a problem that might not materialize.

### Config placeholders (server INI) — one confirmed, one flagged

- **Max attachment upload size**: proposed 5GB — flagged for
  double-checking (not a typo for 5MB?), since it's a notable outlier
  against the "not a lot of data" assumption running through the rest of
  the design. Not yet finalized.
- **Compaction fragment-count trigger**: proposed 10 — flagged as likely
  too aggressive; at that threshold, compaction would fire almost
  continuously during a busy incident rather than functioning as the
  occasional/long-term-sprawl cleanup it was designed as. Not yet
  finalized.

### Technology stack & deployment model *(resolved 2026-07-31)*

- **Backend: plain PHP, no framework.** Matches the established pattern
  from sibling projects (`hamdatweb`, `simplewebauth`) — procedural style,
  no Composer dependencies, no heavy framework. Journ's backend job (list/
  get/put JSON fragments, hash-check secrets, send email, run compaction/
  archive) doesn't need one. Static PWA assets (HTML/JS/CSS/manifest/
  service worker) are served directly by Apache/Nginx as plain files; PHP
  only handles the actual API endpoints.
- **Frontend: Svelte** (with Vite's PWA plugin), chosen over vanilla JS.
  This is the one place the stack diverges from the sibling projects'
  no-build-step convention — a deliberate tradeoff, not an oversight.
  Reasoning: journ's frontend genuinely has to be a client-side app
  (offline-first rules out server-rendered pages), and the hardest,
  easiest-to-get-wrong parts of this whole design are exactly where a
  framework earns its keep — mature, well-tested PWA tooling handles
  service-worker generation/precaching/update-detection largely
  automatically rather than hand-rolled from scratch, and Svelte's
  reactive model suits the genuinely custom UI (contact-chip autocomplete,
  live-updating entries table, tag/chip rendering) better than manual DOM
  manipulation. Svelte specifically (over React/Vue) because it compiles
  away to a small vanilla-JS-like bundle rather than shipping a framework
  runtime, keeping it closer in spirit to "no unnecessary weight."
- **Deployment model** — same shape as `hamdatweb`, adapted:
  ```
  /var/www/html/                 ← docroot
  ├── journ/                     ← shallow clone of this repo
  │   ├── (PHP API + static PWA assets)
  │   └── journ-config.ini.example
  ├── journ-config.ini           ← live config (sibling, NOT in the repo,
  │                                 survives git pull)
  └── ...
  ```
  Config lives one level above the shallow-cloned repo, outside git's
  purview, exactly like `hamdatweb-config.php` — so updates (`git pull`)
  never clobber server-specific settings.
- **Journal data storage location**: a configured absolute filesystem
  path (same pattern as `HAMDAT_DB` in `hamdatweb-config.php`), **default
  `/var/local/journ/`**, overridable in `journ-config.ini`. Deliberately
  **outside the web-servable docroot** — all access to journal data goes
  through the controlled PHP API; the web server never has a path from
  which it could accidentally serve raw journal JSON fragments or
  attachments as static files.

## Open questions / remaining decisions

None of these block starting implementation — all are either accepted
risks or tunable values with a reasonable placeholder already in place.

- Clock-skew risk on entry-level LWW is an accepted risk, not a blocking
  question (see Time handling).
- Max attachment upload size (proposed 5GB) and compaction fragment-count
  trigger (proposed 10) — both flagged as possibly miscalibrated, not yet
  finalized (see 2026-07-31 config placeholders section). Pure config
  values, easy to change later.
- Whether "regenerate key" requires anything beyond generic write access
  to the journal (see Auth bootstrap) — leaning toward no extra gate,
  not finalized.
- Exact schema-version field name/shape (see Format/schema versioning) —
  deliberately deferred to implementation time.
