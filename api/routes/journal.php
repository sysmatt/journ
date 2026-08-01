<?php
declare(strict_types=1);

// POST /journal (bootstrap create/clone), GET /journal/{uuid}/list, GET /journal/{uuid}/freshness
// See docs/spec/payload-shapes.md § POST /journal and § GET .../list, .../freshness.

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/fragments.php';
require_once __DIR__ . '/../lib/response.php';

/** POST /journal — one atomic call, gated by the bootstrap secret. See docs/spec/identity-and-security.md § Bootstrap. */
function journ_route_create_journal(): void
{
    journ_require_bootstrap_secret();
    $body = journ_request_json();
    $mode = $body['mode'] ?? 'create';
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        journ_error('invalid_request', 'name is required.');
    }

    $journalUuid = journ_uuidv4();
    $now = journ_now();

    $metadata = [
        'v' => 1,
        'journal' => $journalUuid,
        'updated_at' => $now,
        'updated_by' => null,
        'name' => $name,
        'storage' => [
            'type' => 'https',
            'base_url' => journ_config()['base_url'],
        ],
    ];

    $contactsIn = $mode === 'clone' ? ($body['contacts'] ?? []) : [($body['creator'] ?? [])];
    if (empty($contactsIn)) {
        journ_error('invalid_request', $mode === 'clone' ? 'contacts is required for mode=clone.' : 'creator is required.');
    }

    $issued = [];
    $contactFragments = [];
    foreach ($contactsIn as $c) {
        $contactUuid = journ_uuidv4();
        $secret = journ_generate_secret();
        $contactFragments[] = [
            'filename' => 'contact.' . journ_uuidv4() . '.json',
            'content' => [
                'v' => 1,
                'journal' => $journalUuid,
                'contact' => $contactUuid,
                'updated_at' => $now,
                'updated_by' => $contactUuid,
                'name' => $c['name'] ?? null,
                'short_name' => $c['short_name'] ?? null,
                'email' => $c['email'] ?? null,
                'secret_hash' => journ_hash_secret($secret),
            ],
        ];
        $issued[] = ['contact' => $contactUuid, 'secret' => $secret];
    }
    $metadata['updated_by'] = $issued[0]['contact'];

    // Write everything now that all generation succeeded — avoids a
    // partially-created journal if something above had failed.
    $journalDir = journ_journal_dir($journalUuid);
    journ_write_fragment($journalDir, 'metadata.' . journ_uuidv4() . '.json', json_encode($metadata, journ_json_flags()));
    foreach ($contactFragments as $cf) {
        journ_write_fragment($journalDir, $cf['filename'], json_encode($cf['content'], journ_json_flags()));
    }

    journ_json(['journal' => $journalUuid, 'contacts' => $issued], 201);
}

/** GET /journal/{uuid}/list */
function journ_route_list_journal(string $journalUuid): void
{
    if (!journ_journal_exists($journalUuid)) {
        journ_error('not_found', 'Journal not found.');
    }
    journ_json(journ_list_journal_root($journalUuid));
}

/** GET /journal/{uuid}/freshness — no auth required, reads are never gated. */
function journ_route_freshness(string $journalUuid): void
{
    if (!journ_journal_exists($journalUuid)) {
        journ_error('not_found', 'Journal not found.');
    }
    journ_json(['updated_at' => journ_freshness($journalUuid)]);
}
