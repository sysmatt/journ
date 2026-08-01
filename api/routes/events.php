<?php
declare(strict_types=1);

// POST /journal/{uuid}/events/{event_uuid}/archive — see
// docs/spec/data-model.md § Event archive and docs/spec/payload-shapes.md.

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/fragments.php';
require_once __DIR__ . '/../lib/response.php';

function journ_route_archive_event(string $journalUuid, string $eventUuid): void
{
    if (!journ_event_exists($journalUuid, $eventUuid)) {
        journ_error('not_found', 'Event not found.');
    }
    journ_require_contact_secret($journalUuid);

    $eventDir = journ_event_dir($journalUuid, $eventUuid);
    // Deliberately a DIFFERENT attic subpath than the one compaction uses
    // (attic/events/{uuid}/ — see compaction.php) — compaction archives
    // individual fragments out of a still-live event into that path, so
    // reusing it here would make an ordinarily-compacted (but still very
    // much live) event look like it had already been whole-archived.
    $atticEventDir = journ_attic_dir($journalUuid) . '/archived-events/' . $eventUuid;

    if (!is_dir(dirname($atticEventDir))) {
        mkdir(dirname($atticEventDir), 0775, true);
    }

    // One-way: physically relocates the whole event folder out of the
    // list/get API surface entirely — see docs/spec/data-model.md.
    if (is_dir($atticEventDir)) {
        journ_error('conflict', 'Event has already been archived.');
    }
    if (!@rename($eventDir, $atticEventDir)) {
        journ_error('invalid_request', 'Could not archive event.', 500);
    }

    journ_json(['ok' => true, 'archived_at' => journ_now()]);
}
