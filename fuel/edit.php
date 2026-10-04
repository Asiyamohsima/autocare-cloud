<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fuel_helpers.php';

$pageTitle  = 'Edit Fuel Record';
$activePage = 'fuel';
$db = require_db('fuel');

$id = valid_id($_GET['id'] ?? null);
if ($id === null) {
    render_error_page('Invalid fuel record', 'The fuel record ID is not valid.', 400, '/fuel/', 'fuel');
}

try {
    $log = find_fuel_log($db, $id);
} catch (Throwable $ex) {
    error_log('[AutoCare] Edit fuel load failed: ' . $ex->getMessage());
    render_error_page('Error', 'Something went wrong while loading this record.', 500, '/fuel/', 'fuel');
}
if ($log === null) {
    render_error_page('Fuel record not found', 'This fuel record does not exist or was deleted.', 404, '/fuel/', 'fuel');
}

$lockedVehicle = [
    'id'                  => (int)$log['vehicle_id'],
    'registration_number' => $log['registration_number'],
    'make'                => $log['make'],
    'model'               => $log['model'],
];

$old    = fuel_to_form($log);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_fuel_post();
    $old['vehicle_id'] = (string)$log['vehicle_id'];

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_fuel($old, $db, (int)$log['vehicle_id']);

        if (!$errors) {
            try {
                update_fuel($db, $id, $data);
                set_flash('success', 'Fuel record updated.');
                redirect('/fuel/?vehicle_id=' . (int)$log['vehicle_id']);
            } catch (Throwable $ex) {
                error_log('[AutoCare] Update fuel failed: ' . $ex->getMessage());
                $errors['form'] = 'Could not update the fuel record. Please try again.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Edit Fuel Record</h1>
    <p><?= e($log['registration_number']) ?> &middot; <?= e(format_date($log['fuel_date'])) ?></p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php
        $vehicles    = [];
        $submitLabel = 'Update Fuel Record';
        $cancelUrl   = url('/fuel/');
        require __DIR__ . '/../includes/fuel_form.php';
        ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
