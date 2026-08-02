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
    // Named distinctly from compaction's attic (which now lives INSIDE
    // the event dir itself, at events/{uuid}/attic/ — see
    // journ_archive_originals() in compaction.php) purely for
    // readability when poking around by hand; there's no collision risk
    // either way since compaction never touches the journal-level attic/
    // at all for event-scoped fragments anymore. A rename() here also
    // carries along that colocated attic/ subfolder automatically, if
    // this event had ever been compacted before being fully archived.
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
