# Deployment

Status: draft, first pass. Self-contained — written so this document
alone is enough to provision a journ instance (e.g. to write an Ansible
role from), without needing to read the rest of `docs/spec/`. Background
and rationale for these decisions live in `docs/spec/operations.md` and
`docs/brain-dump.md` if something here needs justifying.

## What's being deployed

- **PHP backend** (`api/`) — no framework, no Composer dependencies,
  deployed as source (no build step).
- **Static frontend** (`webroot/`) — a built Svelte/Vite PWA. **Committed
  to git** — the server never runs `npm`/Node at all. See § Updating,
  below, for what that implies operationally.
- **No database.** All journal data is JSON files on disk under a
  configured data directory; all install-wide config is one INI file.

## Prerequisites

| Requirement | Notes |
|---|---|
| Linux host with a web server | Nginx or Apache; sample configs for both are in `docs/deploy/` |
| PHP 8.0+ with PHP-FPM | No extensions beyond stock PHP required (no GD, no Composer/`vendor/`) |
| TLS certificate for the domain | **Required, not optional** — PWAs only register a service worker in a secure context. `localhost` is exempt for local dev; nothing else is. Any ACME client (certbot, etc.) works; the sample configs assume Let's Encrypt/certbot's default cert paths |
| `git` | For the shallow clone and later `git pull` updates |
| Node.js | **Only needed on whichever machine builds the frontend** — never on the production server itself. See § Updating |

## Directory layout

```
/var/www/html/                       <- docroot parent (path is arbitrary; this is just the example used throughout)
├── journ/                           <- shallow git clone of the repo
│   ├── webroot/                     <- built frontend (committed to git) — Nginx/Apache DocumentRoot points HERE, not at journ/
│   ├── api/                         <- PHP backend — aliased at /api, see docs/deploy/*.conf.example
│   ├── src/, package.json, vite.config.js, ...   <- frontend source; present on disk but never served
│   └── journ-config.ini.example
├── journ-config.ini                 <- LIVE config — sibling to the repo, NOT inside it (see § Configuration)
└── ...

/var/local/journ/                    <- journal data root (path is configurable — see § Configuration)
├── {journal-uuid}/
│   ├── metadata.*.json, contact.*.json
│   ├── events/{event-uuid}/...
│   └── attic/                       <- compacted/archived fragments; server-managed only
└── ...
```

Two things about this layout are load-bearing, not stylistic:

1. **`journ-config.ini` lives outside the git repo**, one level above the
   clone. This means `git pull` (or a fresh shallow re-clone during an
   update) never touches server-specific secrets/settings.
2. **The web server's DocumentRoot is `journ/webroot/`, not `journ/`
   itself.** `api/`, `src/`, `node_modules/`, `.git/`,
   `journ-config.ini.example` etc. are siblings of `webroot/`, not
   descendants of it — with DocumentRoot set correctly, they're
   unreachable through the web server by construction, no explicit `deny`
   rules needed for them. The PHP backend is reachable *only* through the
   separate `/api` alias described below.

## Provisioning steps

### 1. Install PHP and the web server

Standard distro packages — e.g. on Debian/Ubuntu: `php-fpm`, plus
`nginx` or `apache2` (`libapache2-mod-proxy-fcgi` if using Apache's
`mod_proxy_fcgi`, as the sample config does).

### 2. Clone the repo

```bash
git clone --depth 1 <repo-url> /var/www/html/journ
```

Shallow (`--depth 1`) — matches the update workflow in § Updating.

### 3. Generate the config file

```bash
cp /var/www/html/journ/journ-config.ini.example /var/www/html/journ-config.ini
```

Then edit it and fill in, at minimum:

| Key | How to generate | Notes |
|---|---|---|
| `bootstrap_secret` | `openssl rand -hex 32` | Gates minting brand-new journals. Distribute as a "create journal" link, not the raw value — see `docs/spec/identity-and-security.md` § Bootstrap. Whoever holds this can create journals; keep it as tightly scoped as your use case needs. |
| `recovery_secret` | `openssl rand -hex 32` — **must differ from `bootstrap_secret`** | Break-glass access to *any existing* journal on this install. Higher blast radius than the bootstrap secret — keep this one with the server operator only, never link-shared. See `docs/spec/identity-and-security.md` § Break-glass recovery. |
| `data_root` | — | Absolute path, e.g. `/var/local/journ`. See § Data directory below for permissions. |
| `base_url` | — | The public site URL, e.g. `https://journ.example.org` — **no `/api` suffix**; used to build invite/create-journal links, not API calls. |
| `max_upload_bytes`, `compaction_threshold` | — | Provisional defaults exist in code if omitted; see `docs/spec/operations.md` § Config reference for current values, which are explicitly flagged as not finalized. |
| `[smtp]` section | — | Needed for invite emails to actually send; the app degrades gracefully (logs + returns `sent:false`) if omitted, so it's not strictly blocking for a first deploy. Default driver is `sendmail` (local MTA via PHP's `mail()`) — see § Email delivery below before assuming this "just works." |
| `[tag:*]` sections | — | Optional; code ships with defaults (rush/critical/action/decision). **Declaration order in the file is the precedence rule** — see `docs/spec/ui-ux.md` § Tags. Whatever INI-parsing approach is used must preserve that order (PHP's `parse_ini_file` does, natively — this only matters if the deploy tooling ever re-generates/re-orders this file programmatically). |

### 4. Data directory

```bash
mkdir -p /var/local/journ
chown www-data:www-data /var/local/journ   # the PHP-FPM pool's run-as user
chmod 750 /var/local/journ
```

Must be writable by whatever user PHP-FPM runs as (commonly `www-data`
on Debian/Ubuntu). No other process needs access.

### 5. Web server config

Use `docs/deploy/nginx.conf.example` or `docs/deploy/apache.conf.example`
as the starting point — both implement the same rules:

- DocumentRoot/root = `journ/webroot/`.
- `/api` is aliased/proxied to `journ/api/`, and **every** request under
  it is routed through `api/index.php` specifically — never a generic
  "any `.php` file is executable" handler. This is deliberate: files
  under `api/lib/` and `api/routes/` are include-only, not meant to be
  requested directly, and this routing rule is what prevents that.
- SPA fallback (`try_files ... /index.html` / the Apache
  `RewriteRule ^ index.html`) — needed because paths like `/invite` only
  ever exist client-side (see `docs/spec/identity-and-security.md` §
  Onboarding); the web server has no route for them, the SPA does.
- `sw.js` and `manifest.webmanifest` get `Cache-Control: no-cache` —
  otherwise installed clients never see app updates (see
  `docs/spec/ui-ux.md` § Platform).
- HTTP → HTTPS redirect on port 80.

Adjust the PHP-FPM socket path for your OS/PHP version (the samples use
the common Debian/Ubuntu path, `/run/php/php8.3-fpm.sock`).

### 6. TLS

Any ACME client works. With certbot, e.g.:

```bash
certbot --nginx -d journ.example.org     # or --apache
```

(The sample configs already assume certbot's default cert paths; running
certbot's own `--nginx`/`--apache` mode will typically rewrite them
in-place with the correct paths, which is fine.)

### 7. File upload size — three settings that must agree

Attachments (see `docs/spec/data-model.md` § Attachments) pass through
three independent size limits, in order, before the app's own config
value is ever consulted. **All three must be set to at least the
intended max, or the smallest one silently wins**:

1. **Web server body-size limit** — `client_max_body_size` (Nginx) /
   `LimitRequestBody` (Apache), set in the vhost.
2. **PHP's own limits** — `upload_max_filesize` and `post_max_size` in
   `php.ini` (the latter must be ≥ the former; PHP-FPM will otherwise
   truncate/reject before `api/routes/attachments.php` ever runs).
3. **The app's own `max_upload_bytes`** in `journ-config.ini` — this is
   the only one of the three actually enforced *with a clean error
   response* (`413 payload_too_large`); the other two fail earlier and
   more opaquely (a generic web-server or PHP-level rejection).

### 8. Email delivery

`[smtp] driver` in `journ-config.ini` picks how invite emails go out
(see `docs/spec/operations.md` § Invite emails for the full rationale).
**Default is `sendmail`** — PHP's `mail()`, handed to the local MTA.

- **If using `sendmail`** (the default): this needs `php.ini`'s
  `sendmail_path` to already point at a working sendmail-compatible
  binary — journ does nothing to configure this itself, it's a PHP/OS
  concern, not an app concern. Verify it directly, independent of the
  app:
  ```bash
  php -r 'var_dump(mail("you@example.com", "test", "test body"));'
  ```
  If that returns `false` or the mail never arrives, fix the MTA setup
  (e.g. confirm `msmtp` is installed and `sendmail_path` in `php.ini`
  points at it, e.g. `sendmail_path = /usr/bin/msmtp -t`) before assuming
  anything's wrong with journ itself.
- **If using `driver = "smtp"` instead**: fill in `host`/`port`/
  `username`/`password`/`encryption` in `[smtp]` — no local MTA needed,
  journ talks directly to the relay.

Either way, if `from_email` is unset, the app skips sending entirely
(logs it, returns `sent:false`) rather than erroring — invite emails are
best-effort by design, never block the write they're attached to.

### 9. Verify

```bash
curl -s https://journ.example.org/api/tags
# -> {"tags":[...]}  (empty array is fine on a fresh install with no [tag:*] overrides)

curl -s -o /dev/null -w "%{http_code}\n" https://journ.example.org/
# -> 200 (serves index.html)
```

Then exercise the actual bootstrap flow once, end to end, from a
browser: visit the site, create a journal using the bootstrap-secret link
(see `docs/spec/identity-and-security.md` § Bootstrap for how that link
is meant to be constructed/shared), confirm the PWA installs and a
service worker registers (only works over HTTPS — see Prerequisites).

## Updating

Because `webroot/` is committed rather than built on the server, an
update is just:

```bash
cd /var/www/html/journ
git pull
```

No Node, no build step, no service restart needed for frontend changes
(new static files just get picked up on next request; the "New version
available" prompt in the app itself — see `docs/spec/ui-ux.md` — handles
notifying already-open clients). PHP changes take effect immediately for
new requests (no restart needed unless PHP-FPM's opcache is configured
with a long revalidation interval, in which case reload PHP-FPM).

**The tradeoff this implies**: whoever's building the frontend has to
remember to run `npm run build` and commit the result *before* this
`git pull` step happens on the server — a stale `webroot/` is silently
served otherwise, with nothing to flag that the build is out of date.
This was a deliberate choice (see `docs/spec/operations.md` § Frontend
build workflow) — the alternative (building on the server) would need
Node installed in production and a build step wired into the deploy
process instead.

## Open / not finalized

These don't block a first deployment, but are worth knowing about:

- `max_upload_bytes` and `compaction_threshold` defaults are explicitly
  flagged as provisional in `docs/spec/operations.md` — pick real values
  for your use case rather than trusting the shipped defaults.
- No log rotation / monitoring guidance yet — PHP errors currently go
  wherever PHP-FPM's error log is configured to go (standard PHP-FPM
  behavior, nothing journ-specific configured on top of it).
- No backup guidance yet for `data_root` beyond "it's a directory of
  JSON files and blobs, back it up like any other directory" — no
  journ-specific backup tooling exists.
