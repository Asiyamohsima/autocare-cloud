<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_helpers.php';

$pageTitle  = 'Edit Service';
$activePage = 'services';
$db = require_db('services');

$id = valid_id($_GET['id'] ?? null);
if ($id === null) {
    render_error_page('Invalid service', 'The service ID is not valid.', 400, '/services/', 'services');
}

try {
    $service = find_service($db, $id);
} catch (Throwable $ex) {
    error_log('[AutoCare] Edit service load failed: ' . $ex->getMessage());
    render_error_page('Error', 'Something went wrong while loading this record.', 500, '/services/', 'services');
}
if ($service === null) {
    render_error_page('Service not found', 'This service record does not exist or was deleted.', 404, '/services/', 'services');
}

$lockedVehicle = [
    'id'                  => (int)$service['vehicle_id'],
    'registration_number' => $service['registration_number'],
    'make'                => $service['make'],
    'model'               => $service['model'],
];

$old    = service_to_form($service);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_service_post();
    $old['vehicle_id'] = (string)$service['vehicle_id'];

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_service($old, $db, (int)$service['vehicle_id']);

        if (!$errors) {
            try {
                update_service($db, $id, $data);
                set_flash('success', 'Service record updated.');
                redirect('/services/?vehicle_id=' . (int)$service['vehicle_id']);
            } catch (Throwable $ex) {
                error_log('[AutoCare] Update service failed: ' . $ex->getMessage());
                $errors['form'] = 'Could not update the service record. Please try again.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Edit Service</h1>
    <p><?= e($service['registration_number']) ?> &middot; <?= e($service['service_type']) ?> on <?= e(format_date($service['service_date'])) ?></p>
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
        $submitLabel = 'Update Service';
        $cancelUrl   = url('/services/');
        require __DIR__ . '/../includes/service_form.php';
        ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
