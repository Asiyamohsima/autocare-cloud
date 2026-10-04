<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_helpers.php';

$pageTitle  = 'Services';
$activePage = 'services';
$db = require_db('services');

// ---- Read and validate filters ----
$fVehicle = valid_id($_GET['vehicle_id'] ?? null);
$fType    = (isset($_GET['service_type']) && is_string($_GET['service_type'])
             && in_array($_GET['service_type'], service_types(), true)) ? $_GET['service_type'] : '';
$fFrom    = (isset($_GET['date_from']) && is_string($_GET['date_from']) && is_valid_date($_GET['date_from'])) ? $_GET['date_from'] : '';
$fTo      = (isset($_GET['date_to'])   && is_string($_GET['date_to'])   && is_valid_date($_GET['date_to']))   ? $_GET['date_to']   : '';
$filtersOn = ($fVehicle !== null || $fType !== '' || $fFrom !== '' || $fTo !== '');

$where = []; $types = ''; $params = [];
if ($fVehicle !== null) { $where[] = 's.vehicle_id = ?';   $types .= 'i'; $params[] = $fVehicle; }
if ($fType !== '')      { $where[] = 's.service_type = ?'; $types .= 's'; $params[] = $fType; }
if ($fFrom !== '')      { $where[] = 's.service_date >= ?'; $types .= 's'; $params[] = $fFrom; }
if ($fTo !== '')        { $where[] = 's.service_date <= ?'; $types .= 's'; $params[] = $fTo; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$vehicles = []; $services = []; $summary = ['c' => 0, 't' => 0]; $byVehicle = []; $reminders = [];
$loadError = false;

try {
    $run = function (string $sql) use ($db, $types, $params): array {
        $stmt = $db->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    };

    $vehicles = list_vehicles_for_select($db);

    $summary = $run('SELECT COUNT(*) AS c, COALESCE(SUM(s.total_cost), 0) AS t FROM services s' . $whereSql)[0];

    $services = $run(
        'SELECT s.*, v.registration_number, v.make, v.model, v.current_odometer
           FROM services s JOIN vehicles v ON v.id = s.vehicle_id'
        . $whereSql . ' ORDER BY s.service_date DESC, s.id DESC LIMIT 200'
    );

    $byVehicle = $db->query(
        'SELECT v.id, v.registration_number, v.make, v.model,
                COUNT(s.id) AS cnt, COALESCE(SUM(s.total_cost), 0) AS total
           FROM vehicles v LEFT JOIN services s ON s.vehicle_id = v.id
          GROUP BY v.id, v.registration_number, v.make, v.model
          ORDER BY total DESC'
    )->fetch_all(MYSQLI_ASSOC);

    $reminders = fetch_reminders($db, $fVehicle);
} catch (Throwable $ex) {
    error_log('[AutoCare] Service list failed: ' . $ex->getMessage());
    $loadError = true;
}

// JavaScript for the "View" popup. The data is escaped and inserted with textContent (safe).
$extraScripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('serviceModal');
    if (!modal) { return; }
    modal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) { return; }
        var rows = JSON.parse(button.getAttribute('data-details'));
        var body = document.getElementById('serviceModalBody');
        body.textContent = '';
        var dl = document.createElement('dl');
        dl.className = 'row mb-0';
        rows.forEach(function (pair) {
            var dt = document.createElement('dt');
            var dd = document.createElement('dd');
            dt.className = 'col-sm-5';
            dd.className = 'col-sm-7';
            dd.style.whiteSpace = 'pre-line';
            dt.textContent = pair[0];
            dd.textContent = pair[1];
            dl.appendChild(dt);
            dl.appendChild(dd);
        });
        body.appendChild(dl);
    });
});
</script>
JS;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1>Services</h1>
        <p>Service history and maintenance reminders.</p>
    </div>
    <a href="<?= url('/services/add.php' . ($fVehicle ? '?vehicle_id=' . $fVehicle : '')) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add Service
    </a>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger">Could not load services right now. Please try again later.</div>
<?php else: ?>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-tools"></i></div>
            <div><div class="stat-label"><?= $filtersOn ? 'Services (filtered)' : 'Total Services' ?></div>
                 <div class="stat-value"><?= (int)$summary['c'] ?></div></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-currency-rupee"></i></div>
            <div><div class="stat-label"><?= $filtersOn ? 'Service Cost (filtered)' : 'Total Service Cost' ?></div>
                 <div class="stat-value"><?= format_money($summary['t']) ?></div></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header py-2">Service cost by vehicle</div>
            <ul class="list-group list-group-flush small">
                <?php if (!$byVehicle): ?>
                    <li class="list-group-item text-muted">No vehicles yet.</li>
                <?php endif; ?>
                <?php foreach (array_slice($byVehicle, 0, 4) as $bv): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= e($bv['registration_number']) ?> <span class="text-muted">(<?= (int)$bv['cnt'] ?>)</span></span>
                        <strong><?= format_money($bv['total']) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<!-- Maintenance reminders -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bell me-1"></i>Maintenance Reminders</span>
        <span class="badge text-bg-secondary"><?= count($reminders) ?></span>
    </div>
    <?php if (!$reminders): ?>
        <div class="card-body text-muted">No active reminders. Set a &ldquo;Next service&rdquo; date or odometer when you add a service.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th>Vehicle</th><th>Service</th><th>Due</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php foreach ($reminders as $r): $st = $r['status']; ?>
                    <tr>
                        <td><a class="text-decoration-none fw-semibold" href="<?= url('/vehicles/view.php?id=' . (int)$r['vehicle_id']) ?>"><?= e($r['registration_number']) ?></a>
                            <div class="small text-muted"><?= e($r['make'] . ' ' . $r['model']) ?></div></td>
                        <td><?= e($r['service_type']) ?>
                            <div class="small text-muted">last done <?= format_date($r['service_date']) ?></div></td>
                        <td>
                            <?= e($st['text']) ?>
                            <?php if ($r['next_service_date']): ?><div class="small text-muted">on <?= format_date($r['next_service_date']) ?></div><?php endif; ?>
                            <?php if ($r['next_service_odometer'] !== null): ?><div class="small text-muted">at <?= format_number($r['next_service_odometer']) ?> km</div><?php endif; ?>
                        </td>
                        <td><span class="badge text-bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
                        <td class="text-end">
                            <form method="post" action="<?= url('/services/complete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="done" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check2 me-1"></i>Mark done</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="f_vehicle">Vehicle</label>
                <select class="form-select" id="f_vehicle" name="vehicle_id">
                    <option value="">All vehicles</option>
                    <?php foreach ($vehicles as $veh): ?>
                        <option value="<?= (int)$veh['id'] ?>" <?= $fVehicle === (int)$veh['id'] ? 'selected' : '' ?>>
                            <?= e($veh['registration_number'] . ' - ' . $veh['make'] . ' ' . $veh['model']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="f_type">Service type</label>
                <select class="form-select" id="f_type" name="service_type">
                    <option value="">All types</option>
                    <?php foreach (service_types() as $t): ?>
                        <option value="<?= e($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="f_from">From</label>
                <input type="date" class="form-control" id="f_from" name="date_from" value="<?= e($fFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="f_to">To</label>
                <input type="date" class="form-control" id="f_to" name="date_to" value="<?= e($fTo) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Filter</button>
                <?php if ($filtersOn): ?><a href="<?= url('/services/') ?>" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Service history -->
<?php if (!$services): ?>
    <div class="card"><div class="card-body text-center py-5">
        <i class="bi bi-tools display-4 text-primary"></i>
        <h5 class="mt-3"><?= $filtersOn ? 'No services match these filters' : 'No services yet' ?></h5>
        <p class="text-muted mb-0"><?= $filtersOn ? 'Try changing or clearing the filters.' : 'Add your first service record to start building history.' ?></p>
    </div></div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Service History</span>
            <span class="badge text-bg-secondary"><?= count($services) ?><?= count($services) < (int)$summary['c'] ? ' of ' . (int)$summary['c'] : '' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Date</th><th>Vehicle</th><th>Service</th><th class="text-end">Odometer</th>
                        <th class="text-end">Cost</th><th>Reminder</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($services as $s):
                    $st = reminder_status($s);
                    $details = [
                        ['Vehicle',       $s['registration_number'] . ' - ' . $s['make'] . ' ' . $s['model']],
                        ['Service date',  format_date($s['service_date'])],
                        ['Service type',  $s['service_type']],
                        ['Odometer',      format_number($s['odometer_reading']) . ' km'],
                        ['Service center', $s['service_center'] ?? '-'],
                        ['Description',   $s['description'] ?? '-'],
                        ['Labor cost',    format_money($s['labor_cost'])],
                        ['Parts cost',    format_money($s['parts_cost'])],
                        ['Total cost',    format_money($s['total_cost'])],
                        ['Next service date',     format_date($s['next_service_date'])],
                        ['Next service odometer', $s['next_service_odometer'] !== null ? format_number($s['next_service_odometer']) . ' km' : '-'],
                        ['Reminder',      $st ? $st['label'] . ' (' . $st['text'] . ')' : 'None'],
                        ['Notes',         $s['notes'] ?? '-'],
                    ];
                ?>
                    <tr>
                        <td class="text-nowrap"><?= format_date($s['service_date']) ?></td>
                        <td><a class="text-decoration-none" href="<?= url('/vehicles/view.php?id=' . (int)$s['vehicle_id']) ?>"><?= e($s['registration_number']) ?></a>
                            <div class="small text-muted"><?= e($s['make'] . ' ' . $s['model']) ?></div></td>
                        <td><?= e($s['service_type']) ?>
                            <?php if ($s['service_center']): ?><div class="small text-muted"><?= e($s['service_center']) ?></div><?php endif; ?></td>
                        <td class="text-end text-nowrap"><?= format_number($s['odometer_reading']) ?> km</td>
                        <td class="text-end text-nowrap"><strong><?= format_money($s['total_cost']) ?></strong>
                            <div class="small text-muted">L <?= format_money($s['labor_cost']) ?> + P <?= format_money($s['parts_cost']) ?></div></td>
                        <td><?php if ($st): ?>
                                <span class="badge text-bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" title="View"
                                    data-bs-toggle="modal" data-bs-target="#serviceModal"
                                    data-details="<?= e(json_encode($details, JSON_UNESCAPED_UNICODE)) ?>">
                                <i class="bi bi-eye"></i></button>
                            <a href="<?= url('/services/edit.php?id=' . (int)$s['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url('/services/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        data-confirm="Delete this <?= e($s['service_type']) ?> record from <?= e(format_date($s['service_date'])) ?>? This cannot be undone.">
                                    <i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- View popup -->
<div class="modal fade" id="serviceModal" tabindex="-1" aria-labelledby="serviceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceModalLabel">Service Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="serviceModalBody"></div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
