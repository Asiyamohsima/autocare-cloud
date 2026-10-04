<?php
/** Deletes an expense. POST only, with a valid CSRF token. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/expense_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/expenses/');
}
if (!verify_csrf()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect('/expenses/');
}

$id = valid_id($_POST['id'] ?? null);
if ($id === null) {
    set_flash('danger', 'Invalid expense ID.');
    redirect('/expenses/');
}

$db = require_db('expenses');

try {
    if (find_expense($db, $id) === null) {
        set_flash('warning', 'That expense no longer exists.');
        redirect('/expenses/');
    }

    $stmt = $db->prepare('DELETE FROM expenses WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    set_flash('success', 'Expense deleted.');
} catch (Throwable $ex) {
    error_log('[AutoCare] Delete expense failed: ' . $ex->getMessage());
    set_flash('danger', 'Could not delete the expense. Please try again.');
}

redirect('/expenses/');
