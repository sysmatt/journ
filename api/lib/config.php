<?php
declare(strict_types=1);

// Loads journ-config.ini per docs/spec/operations.md § Deployment model:
// the live config lives ONE LEVEL ABOVE the shallow-cloned repo, outside
// git's purview, so `git pull` never overwrites server-specific settings.
//
// For local dev/testing, set JOURN_CONFIG_PATH to point at an arbitrary
// ini file instead of relying on the docroot-parent convention.

// NOTE: dirname() does NOT resolve ".." lexically the way a shell or
// realpath() would — dirname('/a/b/..') is '/a/b', not '/a'. Concatenating
// '/..' onto __DIR__ and dirname()-ing the result (the original, buggy
// version of this) silently computed one directory too shallow. Use
// dirname(__DIR__) — an actual "go up one real level" — for each step
// instead.
define('JOURN_API_DIR', dirname(__DIR__));  // api/lib -> api
define('JOURN_ROOT', dirname(JOURN_API_DIR)); // api -> repo root

/**
 * Provisional defaults — the actual values are explicitly NOT finalized
 * yet (see docs/spec/operations.md § Config reference). These exist so
 * the app runs sanely out of the box; override them in journ-config.ini.
 */
const JOURN_DEFAULT_MAX_UPLOAD_BYTES = 25 * 1024 * 1024;   // 25MB, provisional
const JOURN_DEFAULT_COMPACTION_THRESHOLD = 10;                // provisional
const JOURN_DEFAULT_DATA_ROOT = '/var/local/journ';
const JOURN_DEFAULT_PRETTY_JSON = false;

function journ_config_path(): string
{
    $override = getenv('JOURN_CONFIG_PATH');
    if ($override !== false && $override !== '') {
        return $override;
    }
    return dirname(JOURN_ROOT) . '/journ-config.ini';
}

/**
 * Loads + caches journ-config.ini. Returns a structured array — never
 * throws for a missing file (falls back to defaults where reasonable),
 * but bootstrap/recovery secrets are required for those endpoints to
 * function (checked at the point of use, not here).
 */
function journ_config(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $path = journ_config_path();
    // parse_ini_file preserves declaration order — PHP arrays are
    // ordered maps — which is load-bearing for [tag:*] precedence
    // (see docs/spec/ui-ux.md § Tags & completion tracking).
    $raw = is_readable($path) ? parse_ini_file($path, true, INI_SCANNER_TYPED) : [];
    if ($raw === false) {
        $raw = [];
    }

    $general = $raw['general'] ?? $raw; // allow a [general] section or bare top-level keys

    $tags = [];
    foreach ($raw as $section => $values) {
        if (is_array($values) && str_starts_with((string) $section, 'tag:')) {
            $tagName = substr((string) $section, 4);
            $tags[$tagName] = [
                'fg' => $values['fg'] ?? null,
                'bg' => $values['bg'] ?? null,
                'highlight_row' => (bool) ($values['highlight_row'] ?? false),
            ];
        }
    }

    $cached = [
        'bootstrap_secret'     => $general['bootstrap_secret'] ?? null,
        'recovery_secret'      => $general['recovery_secret'] ?? null,
        'data_root'            => rtrim((string) ($general['data_root'] ?? JOURN_DEFAULT_DATA_ROOT), '/'),
        'max_upload_bytes'     => (int) ($general['max_upload_bytes'] ?? JOURN_DEFAULT_MAX_UPLOAD_BYTES),
        'compaction_threshold' => (int) ($general['compaction_threshold'] ?? JOURN_DEFAULT_COMPACTION_THRESHOLD),
        'pretty_json'          => (bool) ($general['pretty_json'] ?? JOURN_DEFAULT_PRETTY_JSON),
        'base_url'             => $general['base_url'] ?? null,
        'smtp' => [
            // 'sendmail' (default): hand off to the local MTA via PHP's
            // mail() — zero config here, relies on php.ini's
            // sendmail_path already being set up correctly (e.g. msmtp
            // installed as a sendmail drop-in). 'smtp': the raw-socket
            // client, talks directly to a relay using the fields below.
            // A future 'api' driver (some dedicated transactional-email
            // HTTP endpoint) is anticipated but not implemented — see
            // docs/spec/operations.md § Email delivery.
            'driver'     => $raw['smtp']['driver'] ?? 'sendmail',
            'host'       => $raw['smtp']['host'] ?? null,
            'port'       => (int) ($raw['smtp']['port'] ?? 587),
            'username'   => $raw['smtp']['username'] ?? null,
            'password'   => $raw['smtp']['password'] ?? null,
            'encryption' => $raw['smtp']['encryption'] ?? 'tls', // 'tls' | 'ssl' | 'none'
            'from_email' => $raw['smtp']['from_email'] ?? null,
            'from_name'  => $raw['smtp']['from_name'] ?? 'journ',
        ],
        // Declaration order preserved — this IS the tag precedence order.
        'tags' => $tags,
    ];

    return $cached;
}
