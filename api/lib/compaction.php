<?php
declare(strict_types=1);

// Threshold-triggered, no-scheduler compaction — see docs/spec/api-and-sync.md
// § Compaction. Safe to run concurrently/redundantly: the new consolidated
// fragment is always written BEFORE originals are archived, so a crash
// mid-compaction never leaves data reachable only from attic/; and
// archiving copies-then-removes each original individually, tolerating
// another process having already relocated it.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fragments.php';
require_once __DIR__ . '/reducer.php';

/** Copies each original fragment into attic/ (mirroring its relative path) then removes the live copy. Idempotent under concurrent compaction. */
function journ_archive_originals(string $journalUuid, array $paths): void
{
    $journalDir = journ_journal_dir($journalUuid);
    $atticDir = journ_attic_dir($journalUuid);
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue; // already archived by a concurrent compaction run
        }
        $relative = ltrim(substr($path, strlen($journalDir)), '/');
        $atticPath = $atticDir . '/' . $relative;
        $atticSubdir = dirname($atticPath);
        if (!is_dir($atticSubdir)) {
            mkdir($atticSubdir, 0775, true);
        }
        if (!is_file($atticPath)) {
            @copy($path, $atticPath);
        }
        @unlink($path);
    }
}

function journ_glob_decode_with_paths(string $pattern): array
{
    $out = [];
    foreach (glob($pattern) ?: [] as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $decoded = journ_decode_fragment($raw);
        if ($decoded !== null) {
            $out[] = ['path' => $path, 'data' => $decoded];
        }
    }
    return $out;
}

function journ_maybe_compact_journal_metadata(string $journalUuid): void
{
    $pattern = journ_journal_dir($journalUuid) . '/metadata.*.json';
    $found = journ_glob_decode_with_paths($pattern);
    $threshold = journ_config()['compaction_threshold'];
    if (count($found) <= $threshold) {
        return;
    }

    $records = array_map(fn($f) => ['id' => $f['data']['journal'] ?? $journalUuid, 'updated_at' => $f['data']['updated_at'] ?? '', 'data' => $f['data']], $found);
    $winners = journ_reduce_by_latest($records);
    $winner = reset($winners);
    if ($winner === false) {
        return;
    }

    $newFilename = 'metadata.' . journ_uuidv4() . '.json';
    journ_write_fragment(journ_journal_dir($journalUuid), $newFilename, json_encode($winner['data'], JSON_UNESCAPED_SLASHES));
    journ_archive_originals($journalUuid, array_column($found, 'path'));
}

function journ_maybe_compact_contacts(string $journalUuid): void
{
    $pattern = journ_journal_dir($journalUuid) . '/contact.*.json';
    $found = journ_glob_decode_with_paths($pattern);
    $threshold = journ_config()['compaction_threshold'];
    if (count($found) <= $threshold) {
        return;
    }

    $records = array_filter(array_map(fn($f) => isset($f['data']['contact']) ? ['id' => $f['data']['contact'], 'updated_at' => $f['data']['updated_at'] ?? '', 'data' => $f['data']] : null, $found));
    $winners = journ_reduce_by_latest($records);

    foreach ($winners as $winner) {
        $newFilename = 'contact.' . journ_uuidv4() . '.json';
        journ_write_fragment(journ_journal_dir($journalUuid), $newFilename, json_encode($winner['data'], JSON_UNESCAPED_SLASHES));
    }
    journ_archive_originals($journalUuid, array_column($found, 'path'));
}

function journ_maybe_compact_event_metadata(string $journalUuid, string $eventUuid): void
{
    $pattern = journ_event_dir($journalUuid, $eventUuid) . '/metadata.*.json';
    $found = journ_glob_decode_with_paths($pattern);
    $threshold = journ_config()['compaction_threshold'];
    if (count($found) <= $threshold) {
        return;
    }

    $records = array_map(fn($f) => ['id' => $f['data']['event'] ?? $eventUuid, 'updated_at' => $f['data']['updated_at'] ?? '', 'data' => $f['data']], $found);
    $winners = journ_reduce_by_latest($records);
    $winner = reset($winners);
    if ($winner === false) {
        return;
    }

    $newFilename = 'metadata.' . journ_uuidv4() . '.json';
    journ_write_fragment(journ_event_dir($journalUuid, $eventUuid), $newFilename, json_encode($winner['data'], JSON_UNESCAPED_SLASHES));
    journ_archive_originals($journalUuid, array_column($found, 'path'));
}

function journ_maybe_compact_entries(string $journalUuid, string $eventUuid): void
{
    $pattern = journ_event_dir($journalUuid, $eventUuid) . '/entry.*.json';
    $found = journ_glob_decode_with_paths($pattern);
    $threshold = journ_config()['compaction_threshold'];
    if (count($found) <= $threshold) {
        return;
    }

    $records = [];
    foreach ($found as $fragment) {
        foreach (($fragment['data']['entries'] ?? []) as $entry) {
            if (!isset($entry['uuid'])) {
                continue;
            }
            $records[] = ['id' => $entry['uuid'], 'updated_at' => $entry['updated_at'] ?? ($entry['created_at'] ?? ''), 'data' => $entry];
        }
    }
    $winners = journ_reduce_by_latest($records);
    $entries = array_values(array_map(fn($w) => $w['data'], $winners));
    if (empty($entries)) {
        return;
    }
    usort($entries, fn($a, $b) => strtotime((string) ($a['created_at'] ?? '')) <=> strtotime((string) ($b['created_at'] ?? '')));

    // Filename = uuid of the first entry, same convention as any normal write.
    $newFilename = 'entry.' . $entries[0]['uuid'] . '.json';
    journ_write_fragment(journ_event_dir($journalUuid, $eventUuid), $newFilename, json_encode(['v' => 1, 'entries' => $entries], JSON_UNESCAPED_SLASHES));
    journ_archive_originals($journalUuid, array_column($found, 'path'));
}
