# Payload Shapes

Status: draft spec, promoted from `docs/brain-dump.md` discussion +
2026-07-31 detailing pass. Concrete JSON schemas for fragments, API
request/response bodies, and the export bundle format. See
`data-model.md` for the conceptual shape and `api-and-sync.md` for
endpoint behavior — this doc is the literal wire format.

**Conventions used throughout:**
- All JSON field names are `snake_case` — deliberate choice independent
  of either implementation language (PHP backend, Svelte/JS frontend),
  and keeps fragments easy to read/process with arbitrary external
  tooling (`jq`, Python, etc.), matching the "JSON files, human/tool
  manipulable" goal from `data-model.md`.
- All timestamps are **ISO 8601, UTC, with `Z` suffix** (e.g.
  `"2026-07-31T14:47:00Z"`) — matches the UTC-internally rule in
  `data-model.md` § Time handling.
- Every fragment (not the array-of-entries wrapper) carries a top-level
  `"v": 1` schema-version field, per `data-model.md` § Format/schema
  versioning.

## Auth headers

Two custom headers carry per-contact write auth on every authenticated
endpoint:

```
X-Journ-Contact: {contact-uuid}
X-Journ-Secret:  {plaintext secret}
```

Install-level secrets (see `identity-and-security.md`) use their own
dedicated headers, since they aren't tied to a contact:

```
X-Journ-Bootstrap-Secret: {secret}   # journal creation only
X-Journ-Recovery-Secret:  {secret}   # break-glass recovery only
```

Reads are never gated — no auth header required on any `GET`.

## Error shape

Consistent across every endpoint:

```json
{
  "error": "conflict",
  "message": "A fragment already exists at this filename."
}
```

| `error` code | HTTP status | Meaning |
|---|---|---|
| `conflict` | 409 | Fragment already exists at that filename (safe to treat as "already succeeded" on retry — see `data-model.md` § Entry write model) |
| `invalid_request` | 400 | Malformed body, bad filename/path shape |
| `unauthorized` | 401 | Missing/invalid contact or install-level secret |
| `not_found` | 404 | Journal/event/fragment doesn't exist |
| `payload_too_large` | 413 | Attachment exceeds configured max size |

---

## Fragment schemas

### Journal metadata — `metadata.{uuid}.json` (journal root)

```json
{
  "v": 1,
  "journal": "9f1e2b3c-...-uuid",
  "updated_at": "2026-07-31T14:02:00Z",
  "updated_by": "contact-uuid-of-editor",
  "name": "Substation 7 — Feeder Outage",
  "storage": {
    "type": "https",
    "base_url": "https://journ.example.org/journal"
  }
}
```

- `journal` — the journal's own immutable UUID (repeated here for
  self-description even though it's also the containing folder name).
- `name` — the only field that changes across edits.
- `storage` — immutable after creation; copied forward unchanged in every
  subsequent edit fragment, since each fragment is a full snapshot, not a
  diff (see `data-model.md` § Generalized write convention).

### Contact — `contact.{uuid}.json`

```json
{
  "v": 1,
  "journal": "9f1e2b3c-...-uuid",
  "contact": "contact-uuid",
  "updated_at": "2026-07-31T09:14:00Z",
  "updated_by": "contact-uuid-of-editor",
  "name": "Matt Hoskins",
  "short_name": "MattH",
  "email": "matthoskins@gmail.com",
  "secret_hash": {
    "algo": "sha256",
    "hash": "..."
  }
}
```

- `name`, `short_name`, `email` are all nullable individually; contact
  creation requires at least one of `name`/`email` (see
  `data-model.md` § Display name derivation).
- `secret_hash.algo` lets the hashing scheme change later without
  breaking old records — exact algorithm TBD at implementation time, this
  documents the shape, not the final choice.

### Event metadata — `metadata.{uuid}.json` (under `events/{event-uuid}/`)

```json
{
  "v": 1,
  "journal": "9f1e2b3c-...-uuid",
  "event": "event-uuid",
  "updated_at": "2026-07-31T14:30:00Z",
  "updated_by": "contact-uuid-of-editor",
  "start_at": "2026-07-31T14:02:00Z",
  "creator": "contact-uuid",
  "description": "Transformer fault on Feeder 12, T-2 thermal event.",
  "closed": false,
  "closed_at": null,
  "closed_by": null
}
```

- `closed_at`/`closed_by` are set when `closed: true`, cleared (`null`)
  on re-open — re-opening is itself just another edit fragment.
- The open/closed transition is also separately recorded as an entry
  (see `data-model.md` § Event lifecycle) — `closed`/`closed_at` here is
  for fast/direct reads without scanning entries.

### Entries — `entry.{first-entry-uuid}.json` (under `events/{event-uuid}/`)

```json
{
  "v": 1,
  "entries": [
    {
      "uuid": "entry-uuid",
      "journal": "9f1e2b3c-...-uuid",
      "event": "event-uuid",
      "author": "contact-uuid",
      "created_at": "2026-07-31T14:47:00Z",
      "updated_at": "2026-07-31T14:47:00Z",
      "text": "Confirmed thermal fault on T-2, breaker locked out. tag:critical [@MattH](contact:contact-uuid) can you pull the maintenance log for T-2?",
      "trashed": false,
      "attachments": [
        {
          "uuid": "attachment-uuid",
          "original_filename": "transformer_t2.jpg",
          "storage_filename": "attachment.attachment-uuid.jpg",
          "content_type": "image/jpeg",
          "size": 245678
        }
      ]
    }
  ]
}
```

- The array holds **one entry** for an online single-entry write, or
  **several** for a disconnected catch-up batch (see `data-model.md` §
  Entry write model) — same schema either way.
- `created_at` stays fixed across edits; `updated_at` changes on each
  edit and is what LWW compares. `author` reflects whoever wrote the
  *current* (latest) version — editing is not specially attributed,
  consistent with "no edited indicator" (see `ui-ux.md`).
- `text` is the raw plain-text entry, including any embedded
  `[@ShortName](contact:{uuid})` mention tokens or `tag:`/`update:`
  tokens verbatim — parsing/rendering happens client-side on display
  only (see `ui-ux.md`).

---

## API payloads

### `GET /journal/{uuid}/list`

Response — journal-root fragments plus the set of event UUIDs (so the
client knows what to list next):

```json
{
  "files": ["metadata.abc123.json", "contact.def456.json", "contact.789xyz.json"],
  "events": ["event-uuid-1", "event-uuid-2"]
}
```

### `GET /journal/{uuid}/events/{event_uuid}/list`

Response — that event's own fragment filenames only (metadata + entries;
**attachments are never listed here** — they're fetched on demand by
known filename, not discovered):

```json
{
  "files": ["metadata.evmeta1.json", "entry.entA.json", "entry.entB.json"]
}
```

### `GET /journal/{uuid}/{filename}` and `.../events/{event_uuid}/{filename}`

Response: the raw fragment JSON bytes, `Content-Type: application/json`
— no envelope, exactly the fragment shapes above.

### `PUT /journal/{uuid}/{filename}` and `.../events/{event_uuid}/{filename}`

Request: auth headers + the raw fragment JSON as the body. Response:

```json
{ "ok": true }
```

or a `409 conflict` (see Error shape) if the filename already exists —
safe to treat as success on a retry of identical content.

### `GET /journal/{uuid}/freshness`

No auth required. Response:

```json
{ "updated_at": "2026-07-31T14:47:00Z" }
```

### `GET /tags` *(added during frontend implementation, 2026-08-01)*

No auth required — this is styling config, not journal data, and it's
**install-wide, not journal-scoped** (matches how `[tag:*]` config itself
is defined in `journ-config.ini`). Exists because the client has no other
way to reach that file — it deliberately lives outside the docroot (see
`operations.md` § Deployment model). Response array preserves the same
**declaration-order-is-precedence** rule as the source config (see
`ui-ux.md` § Tags & completion tracking) — clients must render/consume it
in the given order, never re-sort it:

```json
{
  "tags": [
    { "name": "critical", "fg": "#ffd9d5", "bg": "#7a2a2a", "highlight_row": true },
    { "name": "decision", "fg": "#ffe4ad", "bg": "#6b4d17", "highlight_row": false },
    { "name": "default",  "fg": "#16222e", "bg": "#cfe0f5", "highlight_row": false }
  ]
}
```

### `POST /journal` — bootstrap create

Auth: `X-Journ-Bootstrap-Secret`. Two request shapes depending on mode:

**Fresh journal:**
```json
{
  "mode": "create",
  "name": "Substation 7 — Feeder Outage",
  "creator": { "name": "Matt Hoskins", "short_name": "MattH", "email": "matthoskins@gmail.com" }
}
```

**Clone/import** — mints a fresh journal UUID and creates the imported
contact list with brand-new, auto-regenerated secrets (see
`api-and-sync.md` § Export & Import). Non-contact fragments (journal
metadata beyond name, events, entries) are **not** part of this call —
they're replayed afterward via ordinary `PUT` calls, authenticated with
one of the freshly-issued contact secrets this call returns:

```json
{
  "mode": "clone",
  "name": "Substation 7 — Feeder Outage (restored)",
  "contacts": [
    { "name": "Matt Hoskins", "short_name": "MattH", "email": "matthoskins@gmail.com" },
    { "name": "Dana Reyes", "short_name": "dana_r", "email": "dana.reyes@utilityco.example" }
  ]
}
```

Response (both modes) — the new journal's UUID and every created
contact's UUID plus **plaintext secret**, the only time it's ever
transmitted:

```json
{
  "journal": "new-journal-uuid",
  "contacts": [
    { "contact": "contact-uuid-1", "secret": "plaintext-secret-1" },
    { "contact": "contact-uuid-2", "secret": "plaintext-secret-2" }
  ]
}
```

### `POST /journal/{uuid}/events/{event_uuid}/archive`

Auth: any valid contact secret for that journal. Request body: `{}`.
Response:

```json
{ "ok": true, "archived_at": "2026-07-31T18:00:00Z" }
```

### `POST /journal/{uuid}/recover` — break-glass recovery

Auth: `X-Journ-Recovery-Secret`. Request:

```json
{ "name": "Matt Hoskins", "short_name": "MattH", "email": "matthoskins@gmail.com" }
```

Response — the one working foothold this action creates:

```json
{ "contact": "new-contact-uuid", "secret": "plaintext-secret" }
```

### Attachments

`POST /journal/{uuid}/events/{event_uuid}/attachments` — `multipart/form-data`
upload, auth headers required. Response:

```json
{
  "uuid": "attachment-uuid",
  "storage_filename": "attachment.attachment-uuid.jpg",
  "content_type": "image/jpeg",
  "size": 245678
}
```

The client embeds `{uuid, original_filename, storage_filename,
content_type, size}` into the entry fragment it subsequently writes (see
Entries schema above).

`GET /journal/{uuid}/events/{event_uuid}/attachments/{storage_filename}`
— raw binary, no auth required (reads are never gated).

### Invite emails

`POST /journal/{uuid}/contacts/{contact_uuid}/invite` — auth: any valid
contact secret. **Request must include the plaintext secret to embed in
the invite link**:

```json
{ "secret": "plaintext-secret-currently-held-by-the-caller" }
```

This isn't optional plumbing — the server **never stores a contact's
plaintext secret**, only its hash (see `identity-and-security.md`), so it
has no way to construct/send an invite link on its own. The client is
always the one currently holding a plaintext value here, either because
it just generated one via an ordinary contact-fragment `PUT` (a
"regenerate key" is just that — see `identity-and-security.md`) or
because a human is re-sending an invite for a secret they already have
displayed. **"Copy invite URL" needs no server call at all** — the client
already holds everything required to build that link locally; this
endpoint exists only because sending actual email requires the server.

Response: `{ "sent": true }`.

`POST /journal/{uuid}/contacts/bulk` — auth: any valid contact secret.
Request:

```json
{
  "emails": ["r.chen@utilityco.example", "k.singh@utilityco.example"],
  "send_invites": true
}
```

Response — one fresh contact + secret per email, since bulk creation
mints new contacts:

```json
{
  "created": [
    { "contact": "contact-uuid-3", "email": "r.chen@utilityco.example", "secret": "plaintext-secret-3", "invited": true },
    { "contact": "contact-uuid-4", "email": "k.singh@utilityco.example", "secret": "plaintext-secret-4", "invited": true }
  ]
}
```

---

## Export bundle format

A single JSON file — the reduced (post-LWW) view of a journal or a
single event, self-contained including attachment blobs (see
`data-model.md` § Attachments and `api-and-sync.md` § Export & Import
for the surrounding rules — journal-UUID collision handling, clone/import
behavior).

```json
{
  "v": 1,
  "export_type": "journal",
  "exported_at": "2026-07-31T19:00:00Z",
  "journal": { "...": "reduced journal metadata object, same shape as the fragment above" },
  "contacts": [ "...reduced contact objects..." ],
  "events": [
    {
      "metadata": { "...": "reduced event metadata object" },
      "entries": [
        {
          "...": "same entry shape as above, except:",
          "attachments": [
            {
              "uuid": "attachment-uuid",
              "original_filename": "transformer_t2.jpg",
              "content_type": "image/jpeg",
              "size": 245678,
              "data": "base64-encoded-bytes..."
            }
          ]
        }
      ]
    }
  ]
}
```

- `export_type` is `"journal"` (all events) or `"event"` (single event —
  same shape, `events` array has exactly one entry).
- The only structural difference from live fragment storage: exported
  attachments carry an inline base64 `data` field instead of living as
  separate files, since the export is meant to be one portable,
  self-contained artifact.
