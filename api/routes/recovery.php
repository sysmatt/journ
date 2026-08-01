<?php
declare(strict_types=1);

// POST /journal/{uuid}/recover — break-glass recovery, see
// docs/spec/identity-and-security.md § Break-glass recovery.
// Narrow and single-purpose: seeds exactly one new working contact into
// an existing journal. Does not touch/restore any other contact's secret.

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/fragments.php';
require_once __DIR__ . '/../lib/response.php';

function journ_route_recover(string $journalUuid): void
{
    if (!journ_journal_exists($journalUuid)) {
        journ_error('not_found', 'Journal not found.');
    }
    journ_require_recovery_secret();

    $body = journ_request_json();
    $contactUuid = journ_uuidv4();
    $secret = journ_generate_secret();
    $now = journ_now();

    $fragment = [
        'v' => 1,
        'journal' => $journalUuid,
        'contact' => $contactUuid,
        'updated_at' => $now,
        'updated_by' => $contactUuid,
        'name' => $body['name'] ?? null,
        'short_name' => $body['short_name'] ?? null,
        'email' => $body['email'] ?? null,
        'secret_hash' => journ_hash_secret($secret),
    ];
    journ_write_fragment(journ_journal_dir($journalUuid), 'contact.' . journ_uuidv4() . '.json', json_encode($fragment, JSON_UNESCAPED_SLASHES));

    journ_json(['contact' => $contactUuid, 'secret' => $secret], 201);
}
