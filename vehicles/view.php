<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vehicle_helpers.php';

$pageTitle  = 'Vehicle Details';
$activePage = 'vehicles';
$db = require_db('vehicles');

// ---- Validate the ID ----
$id = valid_id($_GET['id'] ?? null);
if ($id === null) {
    render_error_page('Invalid vehicle', 'The vehicle ID is not valid.', 400);
}

// ---- Load everything this page needs ----
try {
    $vehicle = find_vehicle($db, $id);
    if ($vehicle === null) {
        render_error_page('Vehicle not found', 'This vehicle does not exist or was deleted.', 404);
    }

    $stats = vehicle_stats($db, $id);

    $stmt = $db->prepare(
        'SELECT service_date, service_type, odometer_reading, total_cost
           FROM services WHERE vehicle_id = ?
          ORDER BY service_date DESC, id DESC LIMIT 5'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $recentServices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $db->prepare(
        'SELECT provider, policy_number, expiry_date
           FROM insurance WHERE vehicle_id = ?
          ORDER BY expiry_date DESC LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $insurance = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $ex) {
    error_log('[AutoCare] Vehicle view failed: ' . $ex->getMessage());
    render_error_page('Error', 'Something went wrong while loading this vehicle.', 500);
}

$title = trim($vehicle['make'] . ' ' . $vehicle['model'] . ' ' . ($vehicle['variant'] ?? ''));
$pageTitle = $title;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
    <div>
        <h1><?= e($title) ?></h1>
        <p>
            <span class="badge text-bg-dark me-1"><?= e($vehicle['registration_number']) ?></span>
            <span class="badge text-bg-light border me-1"><?= e($vehicle['vehicle_type']) ?></span>
            <span class="badge text-bg-light border"><?= e($vehicle['fuel_type']) ?></span>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('/vehicles/edit.php?id=' . $id) ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <a href="<?= url('/vehicles/') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>All Vehicles
        </a>
    </div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-speedometer"></i></div>
            <div><div class="stat-label">Odometer</div>
                 <div class="stat-value fs-5"><?= format_number($vehicle['current_odometer']) ?> km</div></div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-tools"></i></div>
            <div><div class="stat-label">Services</div>
                 <div class="stat-value fs-5"><?= (int)$stats['service_count'] ?></div>
                 <div class="small text-muted"><?= format_money($stats['service_cost']) ?></div></div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-cash-coin"></i></div>
            <div><div class="stat-label">Expenses</div>
                 <div class="stat-value fs-5"><?= format_money($stats['expense_total']) ?></div></div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-fuel-pump"></i></div>
            <div><div class="stat-label">Fuel Cost</div>
                 <div class="stat-value fs-5"><?= format_money($stats['fuel_cost']) ?></div></div>
        </div></div>
    </div>
</div>

<!-- Sections -->
<div class="card mb-4">
    <div class="card-header">Sections</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php foreach ([['services','Service History','bi-tools'],['expenses','Expenses','bi-cash-coin'],['fuel','Fuel Records','bi-fuel-pump'],['documents','Documents','bi-folder2-open'],['insurance','Insurance','bi-shield-check'],['registration','Registration','bi-card-checklist'],['warranty','Warranty','bi-patch-check']] as [$mod, $label, $icon]): ?>
            <a class="btn btn-light border" href="<?= url('/' . $mod . '/?vehicle_id=' . $id) ?>"><i class="bi <?= $icon ?> me-1"></i><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Quick actions -->
<div class="card mb-4">
    <div class="card-header">Quick Actions</div>
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <a href="<?= url('/services/add.php?vehicle_id=' . $id) ?>" class="btn btn-outline-primary"><i class="bi bi-tools me-1"></i>Add Service</a>
        <a href="<?= url('/expenses/add.php?vehicle_id=' . $id) ?>" class="btn btn-outline-primary"><i class="bi bi-cash-coin me-1"></i>Add Expense</a>
        <a href="<?= url('/fuel/add.php?vehicle_id=' . $id) ?>" class="btn btn-outline-primary"><i class="bi bi-fuel-pump me-1"></i>Add Fuel Record</a>
        <a href="<?= url('/documents/upload.php?vehicle_id=' . $id) ?>" class="btn btn-outline-primary"><i class="bi bi-upload me-1"></i>Upload Document</a>
    </div>
</div>

<div class="row g-3">
    <!-- Details -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Vehicle Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Registration</dt>
                    <dd class="col-sm-7"><?= e($vehicle['registration_number']) ?></dd>

                    <dt class="col-sm-5">Make / Model</dt>
                    <dd class="col-sm-7"><?= e($vehicle['make'] . ' ' . $vehicle['model']) ?></dd>

                    <dt class="col-sm-5">Variant</dt>
                    <dd class="col-sm-7"><?= e($vehicle['variant'] ?? '-') ?></dd>

                    <dt class="col-sm-5">Manufacturing Year</dt>
                    <dd class="col-sm-7"><?= e($vehicle['manufacturing_year'] ?? '-') ?></dd>

                    <dt class="col-sm-5">Color</dt>
                    <dd class="col-sm-7"><?= e($vehicle['color'] ?? '-') ?></dd>

                    <dt class="col-sm-5">Purchase Date</dt>
                    <dd class="col-sm-7"><?= format_date($vehicle['purchase_date']) ?></dd>

                    <dt class="col-sm-5">Purchase Price</dt>
                    <dd class="col-sm-7"><?= $vehicle['purchase_price'] !== null ? format_money($vehicle['purchase_price']) : '-' ?></dd>

                    <dt class="col-sm-5">Added On</dt>
                    <dd class="col-sm-7"><?= format_date($vehicle['created_at']) ?></dd>

                    <dt class="col-sm-5">Notes</dt>
                    <dd class="col-sm-7 mb-0"><?= $vehicle['notes'] !== null ? nl2br(e($vehicle['notes'])) : '-' ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Insurance + documents -->
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Insurance</div>
            <div class="card-body">
                <?php if ($insurance): $status = expiry_status($insurance['expiry_date']); ?>
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold"><?= e($insurance['provider']) ?></div>
                            <div class="small text-muted">Policy <?= e($insurance['policy_number']) ?></div>
                            <div class="small">Expires <?= format_date($insurance['expiry_date']) ?>
                                 (<?= e($status['text']) ?>)</div>
                        </div>
                        <span class="badge text-bg-<?= e($status['class']) ?>"><?= e($status['label']) ?></span>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No insurance recorded yet. <a href="<?= url('/insurance/add.php?vehicle_id=' . $id) ?>">Add a policy</a>.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Documents</div>
            <div class="card-body">
                <p class="mb-0">
                    <strong><?= (int)$stats['document_count'] ?></strong> document(s) stored.
                    <a href="<?= url('/documents/?vehicle_id=' . $id) ?>">View documents</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Recent services -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">Recent Services</div>
            <?php if ($recentServices): ?>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>Date</th><th>Type</th><th class="text-end">Odometer</th><th class="text-end">Cost</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentServices as $s): ?>
                            <tr>
                                <td><?= format_date($s['service_date']) ?></td>
                                <td><?= e($s['service_type']) ?></td>
                                <td class="text-end"><?= format_number($s['odometer_reading']) ?> km</td>
                                <td class="text-end"><?= format_money($s['total_cost']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="card-body text-muted">No service records yet. Service tracking is built in Phase 7.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
