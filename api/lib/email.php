<?php
declare(strict_types=1);

// Minimal raw-socket SMTP client — deliberately no Composer dependency,
// matching docs/spec/operations.md's "plain PHP, no framework" stance.
// Config: see journ-config.ini.example [smtp].

require_once __DIR__ . '/config.php';

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

/** Sends a plain-text email via the configured SMTP relay. Returns false (logged, not thrown) on any failure — invite sending is best-effort. */
function journ_send_email(string $toEmail, string $subject, string $body): bool
{
    $cfg = journ_config()['smtp'];
    if (empty($cfg['host']) || empty($cfg['from_email'])) {
        error_log('journ: SMTP not configured, skipping email send to ' . $toEmail);
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

    journ_smtp_send($sock, 'MAIL FROM:<' . $cfg['from_email'] . '>');
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

    $headers = [
        'From: ' . $cfg['from_name'] . ' <' . $cfg['from_email'] . '>',
        "To: <$toEmail>",
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Date: ' . date('r'),
    ];
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
