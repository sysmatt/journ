<?php
declare(strict_types=1);

// Front controller / router. No framework — a small regex dispatch
// table, consistent with the "plain PHP, no framework" stance in
// docs/spec/operations.md.
//
// Deployment serves this behind an `/api` alias (see
// docs/deployment.md) — Nginx's config strips that prefix before the
// request reaches here, but Apache's mod_proxy_fcgi does NOT rewrite
// REQUEST_URI as cleanly (a well-known gotcha), so relying on every web
// server to strip it consistently is fragile. Instead: strip it
// defensively right here, so the router works the same regardless of
// exactly how a given server passes the path through. For local dev, run:
//   php -S localhost:8080 -t api api/index.php
// so requests arrive as /journal/... directly (no prefix to strip).

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/fragments.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/reducer.php';
require_once __DIR__ . '/lib/compaction.php';
require_once __DIR__ . '/lib/email.php';
require_once __DIR__ . '/lib/links.php';

require_once __DIR__ . '/routes/journal.php';
require_once __DIR__ . '/routes/fragments.php';
require_once __DIR__ . '/routes/events.php';
require_once __DIR__ . '/routes/attachments.php';
require_once __DIR__ . '/routes/contacts.php';
require_once __DIR__ . '/routes/recovery.php';
require_once __DIR__ . '/routes/tags.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (str_starts_with($path, '/api/')) {
    $path = substr($path, 4); // '/api/journal/...' -> '/journal/...'
} elseif ($path === '/api') {
    $path = '/';
}

// Building patterns with sprintf (not "$U" string interpolation, which
// only works for variables, not constants) — [method, pattern, handler],
// checked in order, most specific first.
$u = JOURN_UUID_RE;
$routes = [
    ['POST', "#^/journal$#", 'journ_route_create_journal'],
    ['GET',  "#^/tags$#", 'journ_route_get_tags'],

    ['GET',  sprintf('#^/journal/(%s)/freshness$#', $u), 'journ_route_freshness'],
    ['GET',  sprintf('#^/journal/(%s)/list$#', $u), 'journ_route_list_journal'],

    ['GET',  sprintf('#^/journal/(%s)/events/(%s)/list$#', $u, $u), 'journ_route_list_event'],
    ['POST', sprintf('#^/journal/(%s)/events/(%s)/archive$#', $u, $u), 'journ_route_archive_event'],
    ['POST', sprintf('#^/journal/(%s)/events/(%s)/attachments$#', $u, $u), 'journ_route_upload_attachment'],
    ['GET',  sprintf('#^/journal/(%s)/events/(%s)/attachments/([^/]+)$#', $u, $u), 'journ_route_download_attachment'],

    ['POST', sprintf('#^/journal/(%s)/contacts/(%s)/invite$#', $u, $u), 'journ_route_invite_contact'],
    ['POST', sprintf('#^/journal/(%s)/contacts/bulk$#', $u), 'journ_route_bulk_contacts'],

    ['POST', sprintf('#^/journal/(%s)/recover$#', $u), 'journ_route_recover'],

    ['GET',  sprintf('#^/journal/(%s)/events/(%s)/([^/]+\.json)$#', $u, $u), 'journ_route_get_event_fragment'],
    ['PUT',  sprintf('#^/journal/(%s)/events/(%s)/([^/]+\.json)$#', $u, $u), 'journ_route_put_event_fragment'],

    ['GET',  sprintf('#^/journal/(%s)/([^/]+\.json)$#', $u), 'journ_route_get_fragment'],
    ['PUT',  sprintf('#^/journal/(%s)/([^/]+\.json)$#', $u), 'journ_route_put_fragment'],
];

foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if ($method !== $routeMethod) {
        continue;
    }
    if (preg_match($pattern, $path, $m)) {
        $args = array_slice($m, 1);
        $handler(...$args);
        exit;
    }
}

journ_error('not_found', 'No matching route.');
