<?php
/**
 * Deletes a document. POST only, with a valid CSRF token.
 *   1. Delete the object from S3.
 *   2. Only if that succeeds, delete the MySQL record.
 * If S3 deletion fails, the record is KEPT, so we never lose track of a file that still exists.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/documents/');
}
if (!verify_csrf()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect('/documents/');
}

$id = valid_id($_POST['id'] ?? null);
if ($id === null) {
    set_flash('danger', 'Invalid document ID.');
    redirect('/documents/');
}

$db = require_db('documents');

try {
    $doc = find_document($db, $id);
} catch (Throwable $ex) {
    error_log('[AutoCare] Delete lookup failed: ' . $ex->getMessage());
    set_flash('danger', 'Could not look up the document. Please try again.');
    redirect('/documents/');
}
if ($doc === null) {
    set_flash('warning', 'That document no longer exists.');
    redirect('/documents/');
}

$back = '/documents/?vehicle_id=' . (int)$doc['vehicle_id'];

// ---- Step 1: remove the file from S3 ----
try {
    s3_delete_object($doc['s3_key']);
} catch (Throwable $ex) {
    // s3_delete_object() has already logged the technical reason.
    set_flash('danger', 'The document was NOT deleted, because its file could not be removed from storage. Please try again later.');
    redirect($back);
}

// ---- Step 2: remove the MySQL record (only reached if S3 deletion succeeded) ----
try {
    $stmt = $db->prepare('DELETE FROM documents WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    set_flash('success', 'Document deleted.');
} catch (Throwable $ex) {
    error_log('[AutoCare] Record delete failed after S3 delete for document ' . $id . ': ' . $ex->getMessage());
    set_flash('warning', 'The file was removed from storage, but its record could not be removed. Please press Delete again to finish.');
}

redirect($back);
