<?php
declare(strict_types=1);

// Builds the shareable links used for onboarding — see
// docs/spec/identity-and-security.md § Onboarding / invite mechanism
// and § Bootstrap.
//
// NOTE: secrets are carried in the query string here for simplicity.
// docs/brain-dump.md flags that URL fragments (#...) would be a stronger
// choice long-term, since fragments are never sent to the server or
// logged in access logs — left as a follow-up hardening item, not yet
// implemented.

require_once __DIR__ . '/config.php';

function journ_invite_url(string $journalUuid, string $contactUuid, string $secret): string
{
    $base = rtrim((string) (journ_config()['base_url'] ?? ''), '/');
    return "{$base}/invite?journal={$journalUuid}&contact={$contactUuid}&secret={$secret}";
}
