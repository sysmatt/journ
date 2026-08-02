<?php
declare(strict_types=1);

// GET /journal/{uuid}/events/{uuid}/dashboard — public, read-only,
// secret-gated (not contact-gated) event view. See
// docs/spec/identity-and-security.md § Public dashboard secret and
// docs/spec/payload-shapes.md § GET .../dashboard for the full
// reasoning on why this is a separate endpoint from the ordinary
// (always-open) fragment sync path, and exactly what's deliberately
// excluded from the response below.

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/fragments.php';
require_once __DIR__ . '/../lib/reducer.php';
require_once __DIR__ . '/../lib/response.php';

/**
 * GET /journal/{uuid}/events/{uuid}/dashboard/freshness — cheap,
 * secret-gated, stat()-only. Lets PublicDashboard.svelte poll frequently
 * without paying for a full reduce on every tick — same "check a
 * timestamp first" shape as the authenticated app's own freshness
 * endpoint, just event-scoped and secret-gated instead of journal-wide
 * and open. See docs/spec/ui-ux.md § Public dashboard.
 */
function journ_route_get_dashboard_freshness(string $journalUuid, string $eventUuid): void
{
    journ_require_dashboard_secret($journalUuid, $eventUuid);
    journ_json(['updated_at' => journ_event_freshness($journalUuid, $eventUuid)]);
}

function journ_route_get_dashboard(string $journalUuid, string $eventUuid): void
{
    // Also 404s here (not_found, never revealing whether the event
    // exists to a bad secret) if the event has no metadata, or public
    // sharing isn't currently enabled for it.
    $event = journ_require_dashboard_secret($journalUuid, $eventUuid);

    $journalMeta = journ_reduce_journal_metadata($journalUuid);

    $contacts = [];
    foreach (journ_reduce_contacts($journalUuid) as $contactUuid => $c) {
        // name/short_name ONLY — no email, no secret_hash, no
        // deleted/deleted_at/deleted_by. This is purely to resolve
        // @mention chip labels and entry authorship for an anonymous
        // viewer, never a full contact directory.
        $contacts[$contactUuid] = [
            'name' => $c['name'] ?? null,
            'short_name' => $c['short_name'] ?? null,
        ];
    }

    $entries = array_map(static function (array $e): array {
        return [
            'uuid' => $e['uuid'] ?? null,
            'author' => $e['author'] ?? null,
            'created_at' => $e['created_at'] ?? null,
            'updated_at' => $e['updated_at'] ?? null,
            'text' => $e['text'] ?? '',
            'trashed' => (bool) ($e['trashed'] ?? false),
            // attachments deliberately omitted entirely — never even an
            // empty array's worth of filenames reaches this response.
        ];
    }, journ_reduce_entries($journalUuid, $eventUuid));

    journ_json([
        'journal_name' => $journalMeta['name'] ?? '',
        'event' => [
            'description' => $event['description'] ?? '',
            'start_at' => $event['start_at'] ?? null,
            'closed' => (bool) ($event['closed'] ?? false),
            'closed_at' => $event['closed_at'] ?? null,
        ],
        'contacts' => $contacts,
        'entries' => $entries,
    ]);
}
