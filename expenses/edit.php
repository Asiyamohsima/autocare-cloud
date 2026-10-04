<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/expense_helpers.php';

$pageTitle  = 'Edit Expense';
$activePage = 'expenses';
$db = require_db('expenses');

$id = valid_id($_GET['id'] ?? null);
if ($id === null) {
    render_error_page('Invalid expense', 'The expense ID is not valid.', 400, '/expenses/', 'expenses');
}

try {
    $expense = find_expense($db, $id);
} catch (Throwable $ex) {
    error_log('[AutoCare] Edit expense load failed: ' . $ex->getMessage());
    render_error_page('Error', 'Something went wrong while loading this record.', 500, '/expenses/', 'expenses');
}
if ($expense === null) {
    render_error_page('Expense not found', 'This expense does not exist or was deleted.', 404, '/expenses/', 'expenses');
}

$vehicles = list_vehicles_for_select($db);
$old      = expense_to_form($expense);
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_expense_post();

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_expense($old, $db);

        if (!$errors) {
            try {
                update_expense($db, $id, $data);
                set_flash('success', 'Expense updated.');
                redirect('/expenses/?vehicle_id=' . $data['vehicle_id']);
            } catch (Throwable $ex) {
                error_log('[AutoCare] Update expense failed: ' . $ex->getMessage());
                $errors['form'] = 'Could not update the expense. Please try again.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Edit Expense</h1>
    <p><?= e($expense['registration_number']) ?> &middot; <?= e($expense['category']) ?> on <?= e(format_date($expense['expense_date'])) ?></p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php
        $submitLabel = 'Update Expense';
        $cancelUrl   = url('/expenses/');
        require __DIR__ . '/../includes/expense_form.php';
        ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
