<?php
declare(strict_types=1);

// GET/PUT fragment endpoints (journal-root and event-scoped), plus the
// per-event list. See docs/spec/payload-shapes.md § Sync API and
// § Fragment-write enforcement.

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/fragments.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/compaction.php';

const JOURN_ROOT_FRAGMENT_TYPES = ['metadata', 'contact'];
const JOURN_EVENT_FRAGMENT_TYPES = ['metadata', 'entry'];

function journ_route_list_event(string $journalUuid, string $eventUuid): void
{
    if (!journ_event_exists($journalUuid, $eventUuid)) {
        journ_error('not_found', 'Event not found.');
    }
    journ_json(journ_list_event($journalUuid, $eventUuid));
}

function journ_route_get_fragment(string $journalUuid, string $filename): void
{
    if (!journ_journal_exists($journalUuid) || !journ_is_valid_fragment_filename($filename, JOURN_ROOT_FRAGMENT_TYPES)) {
        journ_error('not_found', 'Fragment not found.');
    }
    $raw = journ_read_fragment(journ_journal_dir($journalUuid), $filename);
    if ($raw === null) {
        journ_error('not_found', 'Fragment not found.');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $raw;
    exit;
}

function journ_route_get_event_fragment(string $journalUuid, string $eventUuid, string $filename): void
{
    if (!journ_event_exists($journalUuid, $eventUuid) || !journ_is_valid_fragment_filename($filename, JOURN_EVENT_FRAGMENT_TYPES)) {
        journ_error('not_found', 'Fragment not found.');
    }
    $raw = journ_read_fragment(journ_event_dir($journalUuid, $eventUuid), $filename);
    if ($raw === null) {
        journ_error('not_found', 'Fragment not found.');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $raw;
    exit;
}

function journ_route_put_fragment(string $journalUuid, string $filename): void
{
    if (!journ_is_valid_uuid($journalUuid) || !journ_is_valid_fragment_filename($filename, JOURN_ROOT_FRAGMENT_TYPES)) {
        journ_error('invalid_request', 'Malformed journal UUID or filename.');
    }
    journ_require_contact_secret($journalUuid);

    $raw = file_get_contents('php://input');
    if ($raw === false || json_decode($raw) === null) {
        journ_error('invalid_request', 'Body must be valid JSON.');
    }

    $written = journ_write_fragment(journ_journal_dir($journalUuid), $filename, $raw);
    if (!$written) {
        journ_error('conflict', 'A fragment already exists at this filename.');
    }

    if (str_starts_with($filename, 'metadata.')) {
        journ_maybe_compact_journal_metadata($journalUuid);
    } else {
        journ_maybe_compact_contacts($journalUuid);
    }

    journ_json(['ok' => true], 201);
}

function journ_route_put_event_fragment(string $journalUuid, string $eventUuid, string $filename): void
{
    if (!journ_is_valid_uuid($journalUuid) || !journ_is_valid_uuid($eventUuid) || !journ_is_valid_fragment_filename($filename, JOURN_EVENT_FRAGMENT_TYPES)) {
        journ_error('invalid_request', 'Malformed UUID(s) or filename.');
    }
    journ_require_contact_secret($journalUuid);

    $raw = file_get_contents('php://input');
    if ($raw === false || json_decode($raw) === null) {
        journ_error('invalid_request', 'Body must be valid JSON.');
    }

    $written = journ_write_fragment(journ_event_dir($journalUuid, $eventUuid), $filename, $raw);
    if (!$written) {
        journ_error('conflict', 'A fragment already exists at this filename.');
    }

    if (str_starts_with($filename, 'metadata.')) {
        journ_maybe_compact_event_metadata($journalUuid, $eventUuid);
    } else {
        journ_maybe_compact_entries($journalUuid, $eventUuid);
    }

    journ_json(['ok' => true], 201);
}
