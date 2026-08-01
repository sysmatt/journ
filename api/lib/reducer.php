<?php
declare(strict_types=1);

// The ONE generalized LWW reducer (docs/spec/data-model.md § Read/merge
// strategy) — reused here, and conceptually identical to the client-side
// reducer and to compaction. Only the per-type "flatten to records" step
// differs; the reduction algorithm itself is shared.

require_once __DIR__ . '/fragments.php';

/**
 * Given a flat list of ['id' => ..., 'updated_at' => ..., 'data' => ...]
 * records, keeps the latest (by updated_at) record per id. This is the
 * entire LWW algorithm — everything else in this file just adapts
 * fragment shapes into this common form.
 */
function journ_reduce_by_latest(array $records): array
{
    $winners = []; // id => record
    foreach ($records as $record) {
        $id = $record['id'];
        $existing = $winners[$id] ?? null;
        if ($existing === null || strtotime((string) $record['updated_at']) >= strtotime((string) $existing['updated_at'])) {
            $winners[$id] = $record;
        }
    }
    return $winners;
}

/** Best-effort JSON decode — malformed fragments are skipped, not fatal (external tools may touch these files). */
function journ_decode_fragment(string $raw): ?array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function journ_glob_decode(string $pattern): array
{
    $out = [];
    foreach (glob($pattern) ?: [] as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $decoded = journ_decode_fragment($raw);
        if ($decoded !== null) {
            $out[] = $decoded;
        } else {
            error_log("journ: skipping malformed fragment $path");
        }
    }
    return $out;
}

/** Reduces journal metadata fragments to the single current record, or null if the journal has none yet. */
function journ_reduce_journal_metadata(string $journalUuid): ?array
{
    $fragments = journ_glob_decode(journ_journal_dir($journalUuid) . '/metadata.*.json');
    $records = array_map(fn($f) => ['id' => $f['journal'] ?? $journalUuid, 'updated_at' => $f['updated_at'] ?? '', 'data' => $f], $fragments);
    $winners = journ_reduce_by_latest($records);
    $winner = reset($winners);
    return $winner === false ? null : $winner['data'];
}

/** Reduces all contact fragments to one current record per contact UUID. Returns [contact_uuid => data, ...]. */
function journ_reduce_contacts(string $journalUuid): array
{
    $fragments = journ_glob_decode(journ_journal_dir($journalUuid) . '/contact.*.json');
    $records = array_map(fn($f) => ['id' => $f['contact'] ?? null, 'updated_at' => $f['updated_at'] ?? '', 'data' => $f], $fragments);
    $records = array_filter($records, fn($r) => $r['id'] !== null);
    $winners = journ_reduce_by_latest($records);
    $result = [];
    foreach ($winners as $id => $winner) {
        $result[$id] = $winner['data'];
    }
    return $result;
}

function journ_reduce_contact(string $journalUuid, string $contactUuid): ?array
{
    return journ_reduce_contacts($journalUuid)[$contactUuid] ?? null;
}

/** Reduces event metadata fragments to the single current record, or null. */
function journ_reduce_event_metadata(string $journalUuid, string $eventUuid): ?array
{
    $fragments = journ_glob_decode(journ_event_dir($journalUuid, $eventUuid) . '/metadata.*.json');
    $records = array_map(fn($f) => ['id' => $f['event'] ?? $eventUuid, 'updated_at' => $f['updated_at'] ?? '', 'data' => $f], $fragments);
    $winners = journ_reduce_by_latest($records);
    $winner = reset($winners);
    return $winner === false ? null : $winner['data'];
}

/**
 * Reduces all entry.*.json fragments (each holding an array of one-or-
 * more entries) to one current record per entry UUID. Returns a list of
 * entry objects, most-recent-first (see docs/spec/ui-ux.md).
 */
function journ_reduce_entries(string $journalUuid, string $eventUuid): array
{
    $fragments = journ_glob_decode(journ_event_dir($journalUuid, $eventUuid) . '/entry.*.json');
    $records = [];
    foreach ($fragments as $fragment) {
        foreach (($fragment['entries'] ?? []) as $entry) {
            if (!isset($entry['uuid'])) {
                continue;
            }
            $records[] = ['id' => $entry['uuid'], 'updated_at' => $entry['updated_at'] ?? ($entry['created_at'] ?? ''), 'data' => $entry];
        }
    }
    $winners = journ_reduce_by_latest($records);
    $entries = array_map(fn($w) => $w['data'], array_values($winners));
    usort($entries, fn($a, $b) => strtotime((string) ($b['created_at'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? '')));
    return $entries;
}
