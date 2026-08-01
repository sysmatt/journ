<?php
declare(strict_types=1);

// Attachment upload/download — deliberately separate from the JSON
// fragment sync path. See docs/spec/data-model.md § Attachments and
// docs/spec/payload-shapes.md § Attachments.

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/fragments.php';
require_once __DIR__ . '/../lib/response.php';

/** Extracts a safe, short extension from a user-supplied filename — never trusts it for anything beyond a content-type hint. */
function journ_safe_extension(string $originalFilename): string
{
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
    $ext = substr($ext, 0, 10);
    return $ext === '' ? 'bin' : $ext;
}

function journ_route_upload_attachment(string $journalUuid, string $eventUuid): void
{
    if (!journ_event_exists($journalUuid, $eventUuid)) {
        journ_error('not_found', 'Event not found.');
    }
    journ_require_contact_secret($journalUuid);

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $tooLarge = ($_FILES['file']['error'] ?? null) === UPLOAD_ERR_INI_SIZE;
        journ_error($tooLarge ? 'payload_too_large' : 'invalid_request', 'Expected a multipart/form-data upload in the "file" field.');
    }

    $file = $_FILES['file'];
    $maxBytes = journ_config()['max_upload_bytes'];
    if ($file['size'] > $maxBytes) {
        journ_error('payload_too_large', "Attachment exceeds the configured max of {$maxBytes} bytes.");
    }

    $attachmentUuid = journ_uuidv4();
    $ext = journ_safe_extension((string) $file['name']);
    $storageFilename = "attachment.{$attachmentUuid}.{$ext}";

    $dir = journ_attachments_dir($journalUuid, $eventUuid);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $dest = $dir . '/' . $storageFilename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        journ_error('invalid_request', 'Could not store the uploaded file.', 500);
    }

    $contentType = mime_content_type($dest) ?: 'application/octet-stream';

    journ_json([
        'uuid' => $attachmentUuid,
        'storage_filename' => $storageFilename,
        'content_type' => $contentType,
        'size' => filesize($dest),
    ], 201);
}

function journ_route_download_attachment(string $journalUuid, string $eventUuid, string $storageFilename): void
{
    if (!journ_event_exists($journalUuid, $eventUuid)) {
        journ_error('not_found', 'Not found.');
    }
    if (!preg_match('/^attachment\.' . JOURN_UUID_RE . '\.[a-z0-9]{1,10}$/', $storageFilename)) {
        journ_error('invalid_request', 'Malformed attachment filename.');
    }

    $path = journ_attachments_dir($journalUuid, $eventUuid) . '/' . $storageFilename;
    if (!is_file($path)) {
        journ_error('not_found', 'Attachment not found.');
    }

    header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=31536000, immutable'); // fragments/attachments are immutable once written
    readfile($path);
    exit;
}
