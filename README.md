# journ

> **CAUTION: Experimental / Pre-Alpha**
> `journ` is a new, unreleased project under active design. There is no
> working software yet, no stability guarantees, and no security review has
> been performed. **Do not use this for real incidents.** Expect breaking
> changes to the design and code without notice.

An experimental, browser-based incident journaling tool designed for
distributed, disconnected, and intermittently-connected use.

## What is this?

During an incident or event, multiple people need to record observations,
actions, and timestamps — often from different locations, and often without
reliable network access. `journ` aims to be a browser-based journal that:

- Lets multiple operators log entries independently, in real time.
- Works fully offline, and reconciles/syncs opportunistically when
  connectivity returns.
- Produces a coherent, mergeable incident timeline once entries are
  reconciled.

## Status

Design is spec'd out; implementation is just getting started.

- **[docs/spec/](docs/spec/)** — the structured design spec (data model,
  sync protocol, identity/security, UI/UX, deployment/operations). This is
  the primary reference for what journ is and how it's meant to behave.
- **[docs/brain-dump.md](docs/brain-dump.md)** — the raw, chronological
  design discussion the spec was promoted from. Useful for the *why*
  behind a decision, or history on alternatives considered.
- **`api/`** — the PHP backend. Every endpoint in
  [docs/spec/payload-shapes.md](docs/spec/payload-shapes.md) is implemented
  (fragment sync, bootstrap/clone, compaction, event archive, break-glass
  recovery, attachments, invite emails) and smoke-tested end to end.
- **`src/`** — the Svelte frontend. Currently a minimal placeholder, not
  the real UI yet — see [docs/spec/mockup.html](docs/spec/mockup.html)
  for the validated design this will be built out to match.

## Development environment

Two runtimes are involved: **PHP** (the backend — no framework, no
Composer dependencies) and **Node.js** (build tooling for the Svelte
frontend only; nothing at runtime depends on Node — see
[docs/spec/operations.md](docs/spec/operations.md)).

- **PHP 8+** — install normally via your system package manager. Nothing
  else needed; the backend has zero external dependencies.
- **Node.js** — rather than installing a system-wide version, this repo
  bootstraps its own self-contained copy into a gitignored `.node/`
  folder at the repo root, the same idea as a Python `.venv`:

  ```bash
  ./scripts/setup-node.sh
  export PATH="$(pwd)/.node/bin:$PATH"   # for the current shell
  ```

  Re-running the script is safe/idempotent — it skips the download if the
  pinned version is already present, and re-fetches if you bump the
  version pinned at the top of the script. No `sudo`, no system-wide
  install, nothing outside this repo touched.

## Running locally

**Frontend** (from repo root, after the Node setup above):
```bash
npm install
npm run dev      # dev server with hot reload
npm run build    # regenerates webroot/ — see docs/spec/operations.md
```

**Backend** — needs a `journ-config.ini` and a data directory; nothing is
hardcoded to `/var/local/journ` or a real docroot for local dev:
```bash
cp journ-config.ini.example /tmp/journ-config.ini
mkdir -p /tmp/journ-data
# edit /tmp/journ-config.ini: set data_root = "/tmp/journ-data",
# and set bootstrap_secret / recovery_secret to anything for local testing

JOURN_CONFIG_PATH=/tmp/journ-config.ini php -S localhost:8080 -t api api/index.php
```
Requests then arrive as `/journal/...` directly (no `/api` prefix) — see
`docs/spec/operations.md` § Deployment model for how that prefix gets
introduced by the web server in a real deployment.

## License

GPLv3 — see [LICENSE](LICENSE).
