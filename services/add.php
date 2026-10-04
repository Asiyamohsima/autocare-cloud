<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_helpers.php';

$pageTitle  = 'Add Service';
$activePage = 'services';
$db = require_db('services');

$vehicles = list_vehicles_for_select($db);
if (!$vehicles) {
    set_flash('warning', 'Add a vehicle first, then you can record services for it.');
    redirect('/vehicles/add.php');
}

$old    = service_defaults();
$errors = [];

// Pre-select a vehicle when arriving from a vehicle page: add.php?vehicle_id=3
$preselect = valid_id($_GET['vehicle_id'] ?? null);
if ($preselect !== null) {
    $old['vehicle_id'] = (string)$preselect;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_service_post();

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_service($old, $db);

        if (!$errors) {
            try {
                insert_service($db, $data);
                set_flash('success', 'Service record added.');
                redirect('/services/?vehicle_id=' . $data['vehicle_id']);
            } catch (Throwable $ex) {
                error_log('[AutoCare] Add service failed: ' . $ex->getMessage());
                $errors['form'] = 'Could not save the service record. Please try again.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Add Service</h1>
    <p>Record a service or repair. Fields marked * are required.</p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php
        $lockedVehicle = null;
        $submitLabel   = 'Save Service';
        $cancelUrl     = url('/services/');
        require __DIR__ . '/../includes/service_form.php';
        ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
