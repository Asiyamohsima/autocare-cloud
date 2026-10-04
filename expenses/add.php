<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/expense_helpers.php';

$pageTitle  = 'Add Expense';
$activePage = 'expenses';
$db = require_db('expenses');

$vehicles = list_vehicles_for_select($db);
if (!$vehicles) {
    set_flash('warning', 'Add a vehicle first, then you can record expenses for it.');
    redirect('/vehicles/add.php');
}

$old    = expense_defaults();
$errors = [];

// Pre-select a vehicle when arriving from a vehicle page: add.php?vehicle_id=3
$preselect = valid_id($_GET['vehicle_id'] ?? null);
if ($preselect !== null) {
    $old['vehicle_id'] = (string)$preselect;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_expense_post();

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_expense($old, $db);

        if (!$errors) {
            try {
                insert_expense($db, $data);
                set_flash('success', 'Expense added.');
                redirect('/expenses/?vehicle_id=' . $data['vehicle_id']);
            } catch (Throwable $ex) {
                error_log('[AutoCare] Add expense failed: ' . $ex->getMessage());
                $errors['form'] = 'Could not save the expense. Please try again.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Add Expense</h1>
    <p>Record a vehicle expense. Fields marked * are required.</p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php
        $submitLabel = 'Save Expense';
        $cancelUrl   = url('/expenses/');
        require __DIR__ . '/../includes/expense_form.php';
        ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
