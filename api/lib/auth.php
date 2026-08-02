<?php
declare(strict_types=1);

// Secrets: install-level (bootstrap/recovery) and per-contact.
// See docs/spec/identity-and-security.md and docs/spec/payload-shapes.md § Auth headers.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/fragments.php';
require_once __DIR__ . '/reducer.php';

/** Reads a request header portably (works under php-fpm, Apache, and `php -S`). */
function journ_header(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
}

/** 192 bits of entropy, hex-encoded — plaintext form ever only sent once (see docs/spec/identity-and-security.md). */
function journ_generate_secret(): string
{
    return bin2hex(random_bytes(24));
}

function journ_hash_secret(string $plaintext): array
{
    return ['algo' => 'sha256', 'hash' => hash('sha256', $plaintext)];
}

function journ_verify_secret(string $plaintext, ?array $stored): bool
{
    if ($stored === null || !isset($stored['algo'], $stored['hash'])) {
        return false;
    }
    $computed = hash((string) $stored['algo'], $plaintext);
    return hash_equals((string) $stored['hash'], $computed);
}

/** 401s unless X-Journ-Bootstrap-Secret matches config. */
function journ_require_bootstrap_secret(): void
{
    $secret = journ_config()['bootstrap_secret'];
    $given = journ_header('X-Journ-Bootstrap-Secret');
    if ($secret === null || $given === null || !hash_equals((string) $secret, $given)) {
        journ_error('unauthorized', 'Missing or invalid bootstrap secret.');
    }
}

/**
 * 401s unless EITHER the bootstrap secret is supplied, OR the caller
 * proves they're a currently-valid contact of SOME existing journal —
 * X-Journ-Journal names which one, X-Journ-Contact/X-Journ-Secret prove
 * membership in it exactly like journ_require_contact_secret() does.
 * Deliberate widening of who can mint new journals: any already-onboarded
 * contact of any journal on this install can now start another one, not
 * only whoever holds the install-wide bootstrap secret — see
 * docs/spec/identity-and-security.md § Bootstrap.
 */
function journ_require_bootstrap_or_contact_secret(): void
{
    $bootstrapSecret = journ_config()['bootstrap_secret'];
    $givenBootstrap = journ_header('X-Journ-Bootstrap-Secret');
    if ($bootstrapSecret !== null && $givenBootstrap !== null && hash_equals((string) $bootstrapSecret, $givenBootstrap)) {
        return;
    }

    $provingJournal = journ_header('X-Journ-Journal');
    $contactUuid = journ_header('X-Journ-Contact');
    $secret = journ_header('X-Journ-Secret');
    if ($provingJournal !== null && journ_is_valid_uuid($provingJournal) && $contactUuid !== null && $secret !== null) {
        $contact = journ_reduce_contact($provingJournal, $contactUuid);
        // secret_hash is null on a deleted contact (see ContactsView.svelte's
        // deleteContact()), so journ_verify_secret() already rejects them —
        // no separate deleted-check needed here.
        if ($contact !== null && journ_verify_secret($secret, $contact['secret_hash'] ?? null)) {
            return;
        }
    }

    journ_error('unauthorized', 'Missing or invalid bootstrap secret or contact credentials.');
}

/** 401s unless X-Journ-Recovery-Secret matches config. */
function journ_require_recovery_secret(): void
{
    $secret = journ_config()['recovery_secret'];
    $given = journ_header('X-Journ-Recovery-Secret');
    if ($secret === null || $given === null || !hash_equals((string) $secret, $given)) {
        journ_error('unauthorized', 'Missing or invalid recovery secret.');
    }
}

/**
 * 401s unless X-Journ-Contact + X-Journ-Secret identify a contact with a
 * currently-valid secret for this journal (per the LWW-reduced contact
 * record — a "regenerate key" immediately invalidates the old secret,
 * see docs/spec/identity-and-security.md). Returns the reduced contact
 * record on success.
 */
function journ_require_contact_secret(string $journalUuid): array
{
    $contactUuid = journ_header('X-Journ-Contact');
    $secret = journ_header('X-Journ-Secret');
    if ($contactUuid === null || $secret === null || !journ_is_valid_uuid($contactUuid)) {
        journ_error('unauthorized', 'Missing X-Journ-Contact / X-Journ-Secret headers.');
    }

    $contact = journ_reduce_contact($journalUuid, $contactUuid);
    if ($contact === null || !journ_verify_secret($secret, $contact['secret_hash'] ?? null)) {
        journ_error('unauthorized', 'Invalid contact credentials.');
    }

    return $contact;
}
