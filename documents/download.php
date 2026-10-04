<?php
/**
 * Sends a document from the PRIVATE S3 bucket to the browser.
 * 1. Find the record in MySQL  2. Read its S3 key  3. Fetch the object  4. Stream it as a download.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document_helpers.php';

$id = valid_id($_GET['id'] ?? null);
if ($id === null) {
    render_error_page('Invalid document', 'The document ID is not valid.', 400, '/documents/', 'documents');
}

$db = require_db('documents');

try {
    $doc = find_document($db, $id);
} catch (Throwable $ex) {
    error_log('[AutoCare] Download lookup failed: ' . $ex->getMessage());
    render_error_page('Error', 'Something went wrong while looking up this document.', 500, '/documents/', 'documents');
}
if ($doc === null) {
    render_error_page('Document not found', 'This document does not exist or was deleted.', 404, '/documents/', 'documents');
}

// Fetch from S3 BEFORE sending any output, so errors can still show a friendly page.
try {
    $object = s3_get_object($doc['s3_key']);
} catch (S3OperationException $ex) {
    $notFound = ($ex->getCode() === 404);
    render_error_page($notFound ? 'File not found' : 'Download failed', $ex->getMessage(),
                      $notFound ? 404 : 502, '/documents/', 'documents');
} catch (Throwable $ex) {
    error_log('[AutoCare] Download failed for document ' . $id . ': ' . $ex->getMessage());
    render_error_page('Download failed', 'The file could not be retrieved. Please try again later.', 500, '/documents/', 'documents');
}

$ext      = document_extension($doc['s3_key']);
$mime     = document_allowed_types()[$ext][0] ?? 'application/octet-stream';
$filename = document_download_name($doc['document_name'], $ext);
$fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename);

session_write_close();                       // do not hold the session lock while streaming
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if ($object['size'] > 0) {
    header('Content-Length: ' . $object['size']);
}

$body = $object['body'];
while (!$body->eof()) {
    echo $body->read(8192);
    flush();
}
exit;
