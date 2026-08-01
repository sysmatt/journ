<?php
declare(strict_types=1);

// Filesystem-as-database — see docs/spec/api-and-sync.md § Listing/discovery
// mechanism. No index, no database: everything here is glob/opendir against
// the journal_data_root directly, matching the "dumb pipe" design.

require_once __DIR__ . '/config.php';

const JOURN_UUID_RE = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

function journ_is_valid_uuid(string $s): bool
{
    return (bool) preg_match('/^' . JOURN_UUID_RE . '$/', $s);
}

function journ_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function journ_uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function journ_journal_dir(string $journalUuid): string
{
    return journ_config()['data_root'] . '/' . $journalUuid;
}

function journ_events_dir(string $journalUuid): string
{
    return journ_journal_dir($journalUuid) . '/events';
}

function journ_event_dir(string $journalUuid, string $eventUuid): string
{
    return journ_events_dir($journalUuid) . '/' . $eventUuid;
}

function journ_attachments_dir(string $journalUuid, string $eventUuid): string
{
    return journ_event_dir($journalUuid, $eventUuid) . '/attachments';
}

function journ_attic_dir(string $journalUuid): string
{
    return journ_journal_dir($journalUuid) . '/attic';
}

/** Validates a fragment filename against the {type}.{uuid}.json convention. */
function journ_is_valid_fragment_filename(string $filename, array $allowedTypes): bool
{
    $types = implode('|', array_map('preg_quote', $allowedTypes));
    return (bool) preg_match('/^(' . $types . ')\.' . JOURN_UUID_RE . '\.json$/', $filename);
}

function journ_journal_exists(string $journalUuid): bool
{
    return journ_is_valid_uuid($journalUuid) && is_dir(journ_journal_dir($journalUuid));
}

function journ_event_exists(string $journalUuid, string $eventUuid): bool
{
    return journ_is_valid_uuid($eventUuid) && is_dir(journ_event_dir($journalUuid, $eventUuid));
}

/**
 * Journal-root listing: flat fragment files (metadata.*.json,
 * contact.*.json) plus event discovery — opendir on events/, keeping
 * only subdirectories that actually have a metadata fragment, guarding
 * against a stray/half-initialized directory ever being reported as a
 * real event. See docs/spec/api-and-sync.md.
 */
function journ_list_journal_root(string $journalUuid): array
{
    $dir = journ_journal_dir($journalUuid);
    $files = [];
    foreach (['metadata', 'contact'] as $type) {
        foreach (glob($dir . '/' . $type . '.*.json') ?: [] as $path) {
            $files[] = basename($path);
        }
    }
    sort($files);

    $events = [];
    $eventsDir = journ_events_dir($journalUuid);
    if (is_dir($eventsDir)) {
        $handle = opendir($eventsDir);
        if ($handle !== false) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!journ_is_valid_uuid($entry)) {
                    continue;
                }
                if (!is_dir($eventsDir . '/' . $entry)) {
                    continue;
                }
                $hasMetadata = glob($eventsDir . '/' . $entry . '/metadata.*.json');
                if (!empty($hasMetadata)) {
                    $events[] = $entry;
                }
            }
            closedir($handle);
        }
        sort($events);
    }

    return ['files' => $files, 'events' => $events];
}

/**
 * Per-event listing: non-recursive glob at events/{uuid}/*.json —
 * attachments/ is automatically excluded, since a non-recursive glob
 * never descends into it.
 */
function journ_list_event(string $journalUuid, string $eventUuid): array
{
    $dir = journ_event_dir($journalUuid, $eventUuid);
    $files = [];
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $files[] = basename($path);
    }
    sort($files);
    return ['files' => $files];
}

function journ_read_fragment(string $dir, string $filename): ?string
{
    $path = $dir . '/' . $filename;
    if (!is_file($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    return $contents === false ? null : $contents;
}

/**
 * Creates a new fragment file. Enforces immutability at the syscall
 * level via O_CREAT|O_EXCL (PHP's 'x' fopen mode) — atomic, race-free
 * "write only if it doesn't already exist," no separate exists-check +
 * write race window. Returns true if written, false if it already
 * existed (caller should respond 409 — safe to treat as "already
 * succeeded" per docs/spec/data-model.md § Entry write model).
 */
function journ_write_fragment(string $dir, string $filename, string $rawJsonBody): bool
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir . '/' . $filename;
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        return false; // already exists (or genuine I/O error — same client-facing outcome)
    }
    fwrite($handle, $rawJsonBody);
    fclose($handle);
    return true;
}

/**
 * Timestamp of the most recent write anywhere under a journal — powers
 * the freshness/reachability check (docs/spec/api-and-sync.md § Sync
 * trigger mechanism). Filesystem mtimes, scanned recursively, excluding
 * attic/ (invisible to clients) and attachments/ (not part of the JSON
 * sync path, uploads shouldn't register as "new data to sync").
 */
function journ_freshness(string $journalUuid): ?string
{
    $root = journ_journal_dir($journalUuid);
    if (!is_dir($root)) {
        return null;
    }

    $latest = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        $path = $fileInfo->getPathname();
        if (str_contains($path, '/attic/') || str_contains($path, '/attachments/')) {
            continue;
        }
        if ($fileInfo->isFile() && str_ends_with($path, '.json')) {
            $mtime = $fileInfo->getMTime();
            if ($mtime > $latest) {
                $latest = $mtime;
            }
        }
    }

    return $latest > 0 ? gmdate('Y-m-d\TH:i:s\Z', $latest) : null;
}
