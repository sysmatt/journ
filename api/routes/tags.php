<?php
declare(strict_types=1);

// GET /tags — exposes the server-wide [tag:*] color/behavior config (see
// docs/spec/ui-ux.md § Tags & completion tracking) to the client, which
// otherwise has no way to reach journ-config.ini (deliberately outside
// the docroot). Install-wide, not journal-scoped — matches how the
// config itself is defined. No auth: this is styling info, not data.

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/response.php';

function journ_route_get_tags(): void
{
    // journ_config()['tags'] preserves declaration order — that order
    // IS the precedence rule (see docs/spec/ui-ux.md) — so the client
    // must render/consume this array in the order given, never re-sort it.
    $tags = [];
    foreach (journ_config()['tags'] as $name => $def) {
        $tags[] = ['name' => $name, 'fg' => $def['fg'], 'bg' => $def['bg'], 'highlight_row' => $def['highlight_row']];
    }
    journ_json(['tags' => $tags]);
}
