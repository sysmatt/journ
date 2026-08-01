<?php
declare(strict_types=1);

// JSON responses + the standard error shape from docs/spec/payload-shapes.md § Error shape.

function journ_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

const JOURN_ERROR_STATUS = [
    'conflict'          => 409,
    'invalid_request'   => 400,
    'unauthorized'       => 401,
    'not_found'          => 404,
    'payload_too_large'  => 413,
];

function journ_error(string $code, string $message, ?int $status = null): void
{
    $status ??= JOURN_ERROR_STATUS[$code] ?? 400;
    journ_json(['error' => $code, 'message' => $message], $status);
}

/** Reads + JSON-decodes the raw request body; 400s on invalid JSON. */
function journ_request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        journ_error('invalid_request', 'Request body must be a JSON object.');
    }
    return $decoded;
}
