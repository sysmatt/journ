<?php
declare(strict_types=1);

// Invite emails — see docs/spec/payload-shapes.md § Invite emails.
// The server never stores a contact's plaintext secret (only its hash),
// so /invite requires the caller to supply the plaintext it's currently
// holding; "copy invite URL" in the UI needs no server call at all.

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/fragments.php';
require_once __DIR__ . '/../lib/reducer.php';
require_once __DIR__ . '/../lib/compaction.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/email.php';
require_once __DIR__ . '/../lib/links.php';

function journ_invite_email_body(string $journalName, string $inviteUrl): string
{
    return "You've been invited to join the journ journal \"{$journalName}\".\n\n"
        . "Open this link to get started:\n{$inviteUrl}\n\n"
        . "This link contains a personal access secret — don't share it.\n";
}

function journ_route_invite_contact(string $journalUuid, string $contactUuid): void
{
    journ_require_contact_secret($journalUuid);

    $body = journ_request_json();
    $secret = (string) ($body['secret'] ?? '');
    if ($secret === '') {
        journ_error('invalid_request', 'secret is required (the server never stores plaintext secrets).');
    }

    $contact = journ_reduce_contact($journalUuid, $contactUuid);
    if ($contact === null || empty($contact['email'])) {
        journ_error('not_found', 'Contact not found or has no email on file.');
    }

    $journal = journ_reduce_journal_metadata($journalUuid);
    $journalName = $journal['name'] ?? 'journ';
    $url = journ_invite_url($journalUuid, $contactUuid, $secret);

    $sent = journ_send_email($contact['email'], "You're invited to \"{$journalName}\"", journ_invite_email_body($journalName, $url));
    journ_json(['sent' => $sent]);
}

function journ_route_bulk_contacts(string $journalUuid): void
{
    journ_require_contact_secret($journalUuid);

    $body = journ_request_json();
    $emails = $body['emails'] ?? [];
    $sendInvites = (bool) ($body['send_invites'] ?? false);
    if (!is_array($emails) || empty($emails)) {
        journ_error('invalid_request', 'emails must be a non-empty array.');
    }

    $journal = journ_reduce_journal_metadata($journalUuid);
    $journalName = $journal['name'] ?? 'journ';
    $now = journ_now();
    $created = [];

    foreach ($emails as $email) {
        $email = trim((string) $email);
        if ($email === '') {
            continue;
        }
        $contactUuid = journ_uuidv4();
        $secret = journ_generate_secret();
        $fragment = [
            'v' => 1,
            'journal' => $journalUuid,
            'contact' => $contactUuid,
            'updated_at' => $now,
            'updated_by' => $contactUuid,
            'name' => null,
            'short_name' => null,
            'email' => $email,
            'secret_hash' => journ_hash_secret($secret),
        ];
        journ_write_fragment(journ_journal_dir($journalUuid), 'contact.' . journ_uuidv4() . '.json', json_encode($fragment, JSON_UNESCAPED_SLASHES));

        $invited = false;
        if ($sendInvites) {
            $url = journ_invite_url($journalUuid, $contactUuid, $secret);
            $invited = journ_send_email($email, "You're invited to \"{$journalName}\"", journ_invite_email_body($journalName, $url));
        }

        $created[] = ['contact' => $contactUuid, 'email' => $email, 'secret' => $secret, 'invited' => $invited];
    }

    journ_maybe_compact_contacts($journalUuid);
    journ_json(['created' => $created], 201);
}
