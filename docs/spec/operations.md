# Deployment, Tech Stack & Server Operations

Status: draft spec, promoted from `docs/brain-dump.md`.

> For the actual step-by-step provisioning walkthrough (web server
> configs, TLS, file permissions, upload-size tuning, verification) see
> **[../deployment.md](../deployment.md)** — that document is
> self-contained and is what should be handed to something writing
> Ansible/automation. This doc covers the architecture and *why*.

## Technology stack

- **Backend: plain PHP, no framework.** Matches the established pattern
  from sibling projects (`hamdatweb`, `simplewebauth`) — procedural
  style, no Composer dependencies, no heavy framework. The backend's job
  (list/get/put JSON fragments, hash-check secrets, send email, run
  compaction/archive) doesn't need one.
- **Frontend: Svelte**, with Vite's PWA plugin, chosen over vanilla JS.
  This is the one place the stack diverges from the sibling projects'
  no-build-step convention — a deliberate tradeoff. Journ's frontend has
  to be a genuine client-side app (offline-first rules out
  server-rendered pages), and the hardest, easiest-to-get-wrong parts of
  this design are exactly where a framework earns its keep: mature PWA
  tooling handles service-worker generation/precaching/update-detection
  largely automatically, and Svelte's reactive model suits the custom UI
  (contact-chip autocomplete, live-updating entries table, tag/chip
  rendering) better than manual DOM manipulation. Svelte specifically
  (over React/Vue) because it compiles away to a small vanilla-JS-like
  bundle rather than shipping a framework runtime.
- **No database.** All data is JSON files; all install-wide config is
  INI. Maximizes the ability to understand/manipulate data on the backend
  with other tools, and a DB isn't needed at the assumed data scale.

## Deployment model

Same config-outside-the-repo shape as `hamdatweb`, adapted for having a
separate build step and two things (API, built frontend) that need to be
web-reachable:

```
/var/www/html/                 ← docroot
├── journ/                     ← shallow clone of this repo
│   ├── webroot/                 ← BUILT frontend (committed — see below);
│   │                              web server's DocumentRoot points here
│   ├── api/                     ← PHP backend, aliased at /api
│   ├── src/, package.json, vite.config.js, ...  (frontend source, not served)
│   └── journ-config.ini.example
├── journ-config.ini           ← live config (sibling, NOT in the repo,
│                                 survives git pull)
└── ...
```

- The repo is shallow-cloned directly into a subdirectory of the web
  server's docroot, same as before.
- **Two web-reachable pieces, one deliberately not at the repo root.**
  Initially the plan was to have the built frontend sit directly at the
  repo root alongside `api/`. Revised: Vite refuses to build when its
  output directory is the project root (a safety mechanism against a
  build silently wiping out source files), and mixing build output with
  source files at the same top level is messy regardless. Instead:
  - **`webroot/`** is Vite's build output — `index.html`, hashed JS/CSS
    bundles, manifest, service worker. **Committed to git** (see
    Technology stack, below, and the dev workflow this implies) — a
    shallow clone deploys immediately, no Node needed on the server.
  - **`api/`** is the PHP backend, deployed as-is (no build step).
  - The web server's **DocumentRoot points at `journ/webroot/`**, with a
    separate alias/rule so requests to `/api/...` map to `journ/api/...`.
    Concrete Nginx/Apache config examples: TBD, to be generated when we
    get to actual deployment.
- The live config file (`journ-config.ini`) lives **one level above** the
  cloned repo, outside git's purview entirely — so `git pull` / a fresh
  shallow re-clone never overwrites server-specific settings. Exactly the
  `hamdatweb-config.php` pattern.

### Frontend build workflow

Since `webroot/` is committed rather than built on the server: after
changing anything under `src/`, run the build locally and commit the
result before pushing/deploying —

```bash
.node/bin/npm run build   # regenerates webroot/
git add webroot/
git commit
```

A server doing `git pull` to update always gets an already-built
`webroot/`, never needs Node installed at all. The tradeoff (repo carries
build artifacts, easy to forget to rebuild before committing) was a
deliberate choice — see `docs/brain-dump.md` for the discussion.

### Journal data storage location

A configured absolute filesystem path (same pattern as `HAMDAT_DB` in
`hamdatweb-config.php`), **default `/var/local/journ/`**, overridable in
`journ-config.ini`. Deliberately **outside the web-servable docroot** —
all access to journal data goes through the controlled PHP API; the web
server never has a path from which it could accidentally serve raw
journal JSON fragments or attachments as static files.

## Server responsibilities

The default backend has four jobs:

### 1. Serve the app

Static hosting for the PWA bundle. Needs careful cache headers on the
service worker file itself (must not be aggressively cached, or installed
clients never see updates) plus a versioning signal so an
offline-installed client can tell a new app version exists. See
`ui-ux.md` for the update UX (never auto-applied).

### 2. Storage backend

The JSON fragment sync API — see `api-and-sync.md` for the full endpoint
list and protocol design. Also owns attachment upload/download (separate
from the JSON fragment path, own endpoints, UUID-keyed, subject to the
size/type limits below).

### 3. Invite emails

SMTP-based sending, config via the server-wide INI (see reference below,
matches the `simplewebauth` project's convention). Endpoints for
send-single and send-bulk, building the invite URL and email body — see
`identity-and-security.md`.

### 4. Compaction

Reduce-and-archive job reusing the same reducer logic as clients — see
`api-and-sync.md` § Compaction for the triggering mechanism and
concurrency-safety requirements.

### Also: archive event, break-glass recovery

Both are dedicated privileged endpoints in the same category as
compaction (real server-side file operations, not plain fragment writes).
See `data-model.md` § Event Archive and `identity-and-security.md` §
Break-glass recovery for behavior.

## Config reference (`journ-config.ini`)

All server-wide, install-level settings live here — never per-journal.
**Implementation note**: whatever INI-parsing approach is used must
explicitly preserve **declaration order** for the `[tag:*]` sections —
some config-parsing libraries silently don't (e.g. alphabetize keys),
which would quietly break tag precedence (see `ui-ux.md` § Tags) without
anyone noticing until two conflicting tags appear in the same entry.

| Section / key | Purpose | Status |
|---|---|---|
| Journal-creation (bootstrap) secret | Gates minting a brand-new journal | Locked in |
| Recovery secret | Gates break-glass emergency-contact recovery on an existing journal | Locked in |
| SMTP settings (host/port/credentials/from-address) | Invite email delivery | Locked in, exact keys TBD |
| Journal data storage path | Default `/var/local/journ/` | Locked in |
| Max attachment upload size | Proposed **5GB** — flagged for double-checking (possible typo for 5MB; a notable outlier against the "not a lot of data" assumption elsewhere) | **Not finalized** |
| Compaction fragment-count trigger | Proposed **10** — flagged as likely too aggressive; at that threshold compaction would fire almost continuously during a busy incident rather than functioning as occasional long-term-sprawl cleanup | **Not finalized** |
| `[tag:NAME]` sections (repeatable) | `fg`, `bg`, `highlight_row` per tag word; declaration order = precedence | Locked in, see `ui-ux.md` § Tags |
| `[tag:default]` | Fallback style for unrecognized tag words | Locked in |

### Example `[tag:*]` config shape

```ini
[tag:critical]
fg = #ffffff
bg = #cc0000
highlight_row = true

[tag:decision]
fg = #000000
bg = #ffe0b3
highlight_row = false

[tag:default]
fg = #000000
bg = #cfe0f5
highlight_row = false
```

## Open / not yet finalized

- Max attachment upload size and compaction trigger threshold (see table
  above) — pure config values with reasonable placeholders, easy to
  change later, not blocking implementation.
- Whether "regenerate key" requires anything beyond generic write access
  (see `identity-and-security.md`).
- Basic abuse limits (rate limiting, etc.) beyond the upload size cap —
  placeholder, low priority for now.
