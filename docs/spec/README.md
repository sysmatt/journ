# journ — Spec Index

Status: **draft spec**, promoted from the raw design discussion in
`../brain-dump.md`. This is now the primary reference for what journ is
and how it behaves; the brain dump remains as the historical
decision-log/rationale trail (why each choice was made, what alternatives
were considered) and is worth reading if a "why" here isn't clear.

> See the top-level `README.md` for the project caution notice — this is
> experimental, pre-alpha, not ready for use.

## What journ is

A browser-based, offline-first incident journaling tool. Multiple people
record timestamped entries about an event from wherever they are, with
only intermittent network access, and reconcile automatically whenever
they happen to be online at the same time as reachable storage.

## Spec documents

| Document | Covers |
|---|---|
| [`data-model.md`](data-model.md) | Journal / Event / Entry / Contact objects, fields, mutability, on-disk layout, write/versioning conventions, time handling, conflict vs. collision |
| [`api-and-sync.md`](api-and-sync.md) | Sync protocol & endpoints, sync trigger mechanism, client-side caching, read/merge reduction, compaction, export/import |
| [`identity-and-security.md`](identity-and-security.md) | Contacts, secrets, onboarding/invites, journal-creation bootstrap, break-glass recovery |
| [`ui-ux.md`](ui-ux.md) | Screen layout, entries table, contact chips, tags & completion tracking, contact management |
| [`operations.md`](operations.md) | Tech stack, deployment model, server responsibilities, INI config reference |
| [`payload-shapes.md`](payload-shapes.md) | Concrete JSON schemas for every fragment type and API request/response body, auth headers, error shape, export bundle format |

## Implementation status

- **`api/`** — PHP backend, every endpoint in `payload-shapes.md`
  implemented and smoke-tested against a live `php -S` instance.
- **`src/`** — Svelte frontend implemented (sync engine, IndexedDB cache,
  reducer, Markdown/chip rendering, all main screens). Builds cleanly via
  `npm run build`; not yet exercised in an actual browser (no browser
  available in the environment that built it).
- **[`../deployment.md`](../deployment.md)** + **[`deploy/`](deploy/)** —
  step-by-step provisioning walkthrough and sample Nginx/Apache configs.
  Self-contained; this is what to hand to something writing
  Ansible/automation.

## Explicit non-goals / scope boundaries

Called out here because they were deliberate decisions, not omissions —
worth knowing before proposing something that reopens them:

- **No sneakernet / peer-to-peer sync.** The server is the one mandatory
  rendezvous point. Export/import is for backup/portability, not a sync
  bridge between clients that never both reach the same server.
- **Not designed for low-quality/slow links.** Connectivity is treated as
  binary (present or absent), not a spectrum to optimize bandwidth for.
- **No database.** All data is JSON files; all config is INI.
- **No privilege levels or permissions.** Every contact in a journal is
  equal; identity is loose, not a strong security boundary.
- **No rich-text editor.** Entries are plain text, Markdown-rendered on
  display only.
- **No incremental/delta merge logic.** The client always does a full
  reduce over all fragments when data changes, then caches the result —
  never a separate delta-patching code path.
- **No entry-edit version history UI.** Edits are forensic/file-level
  recoverable only; the UI always shows just the latest version, with no
  "edited" indicator.
- **No client delete of anything, ever.** The default backend is add-only;
  "deletion" (entry trash, event archive, compaction) is either a
  visibility flag or a privileged server-side move into `attic/` —
  never a true delete.

## Open / not yet finalized

See `operations.md` § Open / not yet finalized and
`identity-and-security.md` for the couple of small items (config default
values, one auth detail) still open. None block starting implementation.

## Mockup

[`mockup.html`](mockup.html) is a static, interactive HTML mockup of the
main screen (journal view, entry composer with `@`-mention autocomplete,
tag/update chips, contact management) and the three-mode time display
(UTC / Local / T-relative). Open it directly in a browser — it's
self-contained, no build step. Reflects the UI decisions in `ui-ux.md` as
of 2026-07-31; treat `ui-ux.md` as authoritative if the two ever drift.

## Not yet done

- **Import/export UI** — the backend concept is spec'd (`api-and-sync.md`
  § Export & Import) but has no UI or client-side implementation yet.
- **Archive-event UI** — the endpoint exists and is tested
  (`payload-shapes.md` § `POST .../archive`); nothing in the frontend
  calls it yet.
- Real-browser verification of the frontend (see Implementation status,
  above).
