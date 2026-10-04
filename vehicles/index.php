<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vehicle_helpers.php';

$pageTitle  = 'My Vehicles';
$activePage = 'vehicles';
$db = require_db('vehicles');

// Search text (registration number, make or model)
$q = (isset($_GET['q']) && is_string($_GET['q'])) ? mb_substr(trim($_GET['q']), 0, 50) : '';

$vehicles  = [];
$loadError = false;

try {
    $sql = 'SELECT v.*,
                   (SELECT COUNT(*) FROM services s WHERE s.vehicle_id = v.id) AS service_count,
                   (SELECT COALESCE(SUM(s.total_cost), 0) FROM services s WHERE s.vehicle_id = v.id) AS service_cost
              FROM vehicles v';

    if ($q !== '') {
        $sql .= ' WHERE v.registration_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?';
    }
    $sql .= ' ORDER BY v.created_at DESC, v.id DESC LIMIT 200';

    $stmt = $db->prepare($sql);

    if ($q !== '') {
        $regPart  = normalize_registration($q);                   // "kl 01" -> "KL01"
        $regLike  = '%' . ($regPart !== '' ? $regPart : addcslashes($q, '%_\\')) . '%';
        $textLike = '%' . addcslashes($q, '%_\\') . '%';
        $stmt->bind_param('sss', $regLike, $textLike, $textLike);
    }

    $stmt->execute();
    $vehicles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $ex) {
    error_log('[AutoCare] Vehicle list failed: ' . $ex->getMessage());
    $loadError = true;
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1>My Vehicles</h1>
        <p>Manage all your vehicles in one place.</p>
    </div>
    <a href="<?= url('/vehicles/add.php') ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add Vehicle
    </a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-8 col-lg-9">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="q" maxlength="50"
                           placeholder="Search by registration number, make or model"
                           value="<?= e($q) ?>">
                </div>
            </div>
            <div class="col-md-4 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Search</button>
                <?php if ($q !== ''): ?>
                    <a href="<?= url('/vehicles/') ?>" class="btn btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger">Could not load vehicles right now. Please try again later.</div>
<?php elseif (!$vehicles): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-car-front display-4 text-primary"></i>
            <?php if ($q !== ''): ?>
                <h5 class="mt-3">No vehicles match &ldquo;<?= e($q) ?>&rdquo;</h5>
                <p class="text-muted">Try a different registration number, make or model.</p>
            <?php else: ?>
                <h5 class="mt-3">No vehicles yet</h5>
                <p class="text-muted">Add your first vehicle to start tracking services, expenses and documents.</p>
                <a href="<?= url('/vehicles/add.php') ?>" class="btn btn-primary">Add Vehicle</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Vehicles</span>
            <span class="badge text-bg-secondary"><?= count($vehicles) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Registration</th>
                        <th>Vehicle</th>
                        <th>Type</th>
                        <th>Fuel</th>
                        <th class="text-end">Odometer</th>
                        <th class="text-end">Services</th>
                        <th class="text-end">Service Cost</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vehicles as $v): ?>
                    <tr>
                        <td>
                            <a class="fw-semibold text-decoration-none"
                               href="<?= url('/vehicles/view.php?id=' . (int)$v['id']) ?>">
                                <?= e($v['registration_number']) ?>
                            </a>
                        </td>
                        <td>
                            <?= e($v['make'] . ' ' . $v['model']) ?>
                            <?php if (!empty($v['variant'])): ?>
                                <div class="small text-muted"><?= e($v['variant']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge text-bg-light border"><?= e($v['vehicle_type']) ?></span></td>
                        <td><?= e($v['fuel_type']) ?></td>
                        <td class="text-end"><?= format_number($v['current_odometer']) ?> km</td>
                        <td class="text-end"><?= (int)$v['service_count'] ?></td>
                        <td class="text-end"><?= format_money($v['service_cost']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= url('/vehicles/view.php?id=' . (int)$v['id']) ?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <a href="<?= url('/vehicles/edit.php?id=' . (int)$v['id']) ?>"
                               class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url('/vehicles/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        data-confirm="Delete <?= e($v['registration_number']) ?>? This also deletes its services, expenses and fuel records. This cannot be undone.">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
