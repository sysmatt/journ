<?php
declare(strict_types=1);

// Email delivery — pluggable driver, config-selected (see
// journ-config.ini.example [smtp] "driver"):
//   - 'sendmail' (default): hands off to the local MTA via PHP's mail(),
//     zero config on our side — relies on php.ini's sendmail_path
//     already pointing at a working sendmail-compatible binary (e.g.
//     msmtp installed as a drop-in, relaying to a real provider).
//   - 'smtp': the original minimal raw-socket client, talks directly to
//     a relay using host/port/username/password below. No Composer
//     dependency either way, matching "plain PHP, no framework."
// A future 'api' driver (some dedicated transactional-email HTTP
// endpoint) is anticipated but not implemented — the dispatch in
// journ_send_email() is exactly where it would slot in.

require_once __DIR__ . '/config.php';

/** Strips CR/LF from a value about to be embedded in an SMTP command or email header — prevents command/header injection via user-controlled input (email addresses, journal names flowing into the subject). */
function journ_header_safe(string $value): string
{
    return str_replace(["\r", "\n"], '', $value);
}

/** Sends an email using whichever driver is configured. Returns false (logged, not thrown) on any failure — invite sending is best-effort, never blocks the write it's attached to. */
function journ_send_email(string $toEmail, string $subject, string $body): bool
{
    $cfg = journ_config()['smtp'];
    if (empty($cfg['from_email'])) {
        error_log('journ: smtp.from_email not configured, skipping email send to ' . $toEmail);
        return false;
    }

    $toEmail = journ_header_safe($toEmail);
    $subject = journ_header_safe($subject);

    return match ($cfg['driver']) {
        'smtp' => journ_send_email_smtp($cfg, $toEmail, $subject, $body),
        default => journ_send_email_sendmail($cfg, $toEmail, $subject, $body),
    };
}

function journ_build_headers(array $cfg): array
{
    return [
        'From: ' . journ_header_safe((string) $cfg['from_name']) . ' <' . journ_header_safe((string) $cfg['from_email']) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Date: ' . date('r'),
    ];
}

// ---- driver: sendmail (local MTA via PHP's mail()) ------------------------

function journ_send_email_sendmail(array $cfg, string $toEmail, string $subject, string $body): bool
{
    $headers = implode("\r\n", journ_build_headers($cfg));
    // -f sets the envelope sender — msmtp and most sendmail-compatible
    // wrappers respect it; helps SPF/bounce handling downstream.
    $envelopeFrom = '-f ' . escapeshellarg((string) $cfg['from_email']);

    $ok = @mail($toEmail, $subject, $body, $headers, $envelopeFrom);
    if (!$ok) {
        error_log("journ: mail() returned false sending to $toEmail — check php.ini's sendmail_path is configured (e.g. pointing at msmtp)");
    }
    return $ok;
}

// ---- driver: smtp (raw-socket relay client) --------------------------------

function journ_smtp_expect($sock): string
{
    $response = '';
    while (($line = fgets($sock, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break; // final line of a (possibly multi-line) SMTP response
        }
    }
    return $response;
}

function journ_smtp_send($sock, string $line): void
{
    fwrite($sock, $line . "\r\n");
}

function journ_send_email_smtp(array $cfg, string $toEmail, string $subject, string $body): bool
{
    if (empty($cfg['host'])) {
        error_log('journ: smtp.driver=smtp but smtp.host is not configured, skipping email send to ' . $toEmail);
        return false;
    }

    $transport = $cfg['encryption'] === 'ssl' ? 'ssl://' . $cfg['host'] : $cfg['host'];
    $sock = @fsockopen($transport, (int) $cfg['port'], $errno, $errstr, 10);
    if ($sock === false) {
        error_log("journ: SMTP connect to {$cfg['host']}:{$cfg['port']} failed: $errstr");
        return false;
    }

    $localHost = gethostname() ?: 'localhost';

    journ_smtp_expect($sock); // greeting
    journ_smtp_send($sock, "EHLO $localHost");
    journ_smtp_expect($sock);

    if ($cfg['encryption'] === 'tls') {
        journ_smtp_send($sock, 'STARTTLS');
        journ_smtp_expect($sock);
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        journ_smtp_send($sock, "EHLO $localHost");
        journ_smtp_expect($sock);
    }

    if (!empty($cfg['username'])) {
        journ_smtp_send($sock, 'AUTH LOGIN');
        journ_smtp_expect($sock);
        journ_smtp_send($sock, base64_encode((string) $cfg['username']));
        journ_smtp_expect($sock);
        journ_smtp_send($sock, base64_encode((string) $cfg['password']));
        $authResp = journ_smtp_expect($sock);
        if (!str_starts_with($authResp, '235')) {
            fclose($sock);
            error_log("journ: SMTP auth failed: $authResp");
            return false;
        }
    }

    journ_smtp_send($sock, 'MAIL FROM:<' . journ_header_safe((string) $cfg['from_email']) . '>');
    journ_smtp_expect($sock);
    journ_smtp_send($sock, "RCPT TO:<$toEmail>");
    $rcptResp = journ_smtp_expect($sock);
    if (!str_starts_with($rcptResp, '250')) {
        journ_smtp_send($sock, 'QUIT');
        fclose($sock);
        error_log("journ: SMTP recipient rejected ($toEmail): $rcptResp");
        return false;
    }

    journ_smtp_send($sock, 'DATA');
    journ_smtp_expect($sock);

    $headers = array_merge(journ_build_headers($cfg), ["To: <$toEmail>", 'Subject: ' . $subject]);
    $escapedBody = preg_replace('/^\./m', '..', $body); // dot-stuffing per RFC 5321
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.\r\n";
    fwrite($sock, $message);
    $dataResp = journ_smtp_expect($sock);

    journ_smtp_send($sock, 'QUIT');
    fclose($sock);

    if (!str_starts_with($dataResp, '250')) {
        error_log("journ: SMTP send failed ($toEmail): $dataResp");
        return false;
    }
    return true;
}
