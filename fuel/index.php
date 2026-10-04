<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fuel_helpers.php';

$pageTitle  = 'Fuel Logs';
$activePage = 'fuel';
$db = require_db('fuel');

// ---- Read and validate filters ----
$fVehicle = valid_id($_GET['vehicle_id'] ?? null);
$fFrom    = (isset($_GET['date_from']) && is_string($_GET['date_from']) && is_valid_date($_GET['date_from'])) ? $_GET['date_from'] : '';
$fTo      = (isset($_GET['date_to'])   && is_string($_GET['date_to'])   && is_valid_date($_GET['date_to']))   ? $_GET['date_to']   : '';
$filtersOn = ($fVehicle !== null || $fFrom !== '' || $fTo !== '');

/** Builds the WHERE part. Returns [sql, types, params]. */
$build = function (bool $withDates) use ($fVehicle, $fFrom, $fTo): array {
    $where = []; $types = ''; $params = [];
    if ($fVehicle !== null) { $where[] = 'f.vehicle_id = ?'; $types .= 'i'; $params[] = $fVehicle; }
    if ($withDates) {
        if ($fFrom !== '')  { $where[] = 'f.fuel_date >= ?'; $types .= 's'; $params[] = $fFrom; }
        if ($fTo !== '')    { $where[] = 'f.fuel_date <= ?'; $types .= 's'; $params[] = $fTo; }
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $types, $params];
};

$run = function (string $select, array $b, string $tail = '') use ($db): array {
    [$whereSql, $types, $params] = $b;
    $stmt = $db->prepare($select . $whereSql . $tail);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};

$vehicles = []; $logs = []; $perVehicle = []; $monthly = [];
$effMap = []; $effByVehicle = [];
$loadError = false;

try {
    $vehicles = list_vehicles_for_select($db);

    // Mileage is always calculated from the COMPLETE history, because it needs consecutive fill-ups.
    $all = $run('SELECT f.id, f.vehicle_id, f.odometer_reading, f.fuel_quantity, f.is_full_tank FROM fuel_logs f',
                $build(false), ' ORDER BY f.vehicle_id, f.odometer_reading, f.id');
    $grouped = [];
    foreach ($all as $r) {
        $grouped[(int)$r['vehicle_id']][] = $r;
    }
    foreach ($grouped as $vid => $rows) {
        $res = fuel_efficiency($rows);
        $effByVehicle[$vid] = $res;
        foreach ($res['segments'] as $logId => $seg) {
            $effMap[$logId] = $seg['eff'];
        }
    }

    $perVehicle = $run('SELECT v.id, v.registration_number, v.make, v.model, v.fuel_type,
                               COUNT(f.id) AS c, SUM(f.fuel_quantity) AS q, SUM(f.total_cost) AS t
                          FROM fuel_logs f JOIN vehicles v ON v.id = f.vehicle_id',
                       $build(true),
                       ' GROUP BY v.id, v.registration_number, v.make, v.model, v.fuel_type ORDER BY t DESC');

    $monthly = $run('SELECT DATE_FORMAT(f.fuel_date, \'%Y-%m\') AS ym, SUM(f.total_cost) AS t FROM fuel_logs f',
                    $build(true), ' GROUP BY ym ORDER BY ym DESC LIMIT 12');

    $logs = $run('SELECT f.*, v.registration_number, v.make, v.model, v.fuel_type
                    FROM fuel_logs f JOIN vehicles v ON v.id = f.vehicle_id',
                 $build(true), ' ORDER BY f.fuel_date DESC, f.odometer_reading DESC, f.id DESC LIMIT 200');
} catch (Throwable $ex) {
    error_log('[AutoCare] Fuel list failed: ' . $ex->getMessage());
    $loadError = true;
}

// ---- Summary numbers ----
$totalCost = 0.0; $totalFills = 0; $totalQty = 0.0; $units = [];
foreach ($perVehicle as $pv) {
    $totalCost  += (float)$pv['t'];
    $totalFills += (int)$pv['c'];
    $totalQty   += (float)$pv['q'];
    $units[fuel_unit($pv['fuel_type'])] = true;
}
$singleUnit = (count($units) === 1) ? array_key_first($units) : null;

// Average efficiency card: only meaningful for ONE vehicle (a bike and a car should not be averaged together).
$withEff = array_filter($perVehicle, fn($pv) =>
    isset($effByVehicle[(int)$pv['id']]) && $effByVehicle[(int)$pv['id']]['avg'] !== null);
$effCardText = 'Not enough data';
$effCardNote = 'Needs 2 full-tank fills';
if (count($withEff) === 1) {
    $pv = reset($withEff);
    $effCardText = number_format($effByVehicle[(int)$pv['id']]['avg'], 2) . ' km/' . fuel_unit($pv['fuel_type']);
    $effCardNote = $pv['registration_number'];
} elseif (count($withEff) > 1) {
    $effCardText = 'See table below';
    $effCardNote = 'Shown per vehicle';
}

$monthlyMax = 0.0;
foreach ($monthly as $m) { $monthlyMax = max($monthlyMax, (float)$m['t']); }

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1>Fuel Logs</h1>
        <p>Track fill-ups, fuel cost and mileage.</p>
    </div>
    <a href="<?= url('/fuel/add.php' . ($fVehicle ? '?vehicle_id=' . $fVehicle : '')) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add Fuel Record
    </a>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger">Could not load fuel records right now. Please try again later.</div>
<?php else: ?>

<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    <strong>About mileage:</strong> it is calculated between two full-tank fill-ups, so the result is only as accurate as the
    odometer and fuel quantities you enter. A wrong reading or a missed fill-up will give a wrong figure. For the best results,
    fill the tank completely each time and record every fill-up.
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-fuel-pump"></i></div>
            <div><div class="stat-label"><?= $filtersOn ? 'Fuel Cost (filtered)' : 'Total Fuel Cost' ?></div>
                 <div class="stat-value fs-4"><?= format_money($totalCost) ?></div>
                 <div class="small text-muted"><?= $totalFills ?> fill-up(s)</div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-droplet"></i></div>
            <div><div class="stat-label">Fuel Consumed</div>
                 <?php if ($singleUnit !== null): ?>
                     <div class="stat-value fs-4"><?= e(number_format($totalQty, 2)) ?> <?= e($singleUnit) ?></div>
                 <?php elseif ($perVehicle): ?>
                     <div class="stat-value fs-5">Mixed units</div>
                     <div class="small text-muted">See table below</div>
                 <?php else: ?>
                     <div class="stat-value fs-4">0</div>
                 <?php endif; ?></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-speedometer2"></i></div>
            <div><div class="stat-label">Average Efficiency</div>
                 <div class="stat-value fs-5"><?= e($effCardText) ?></div>
                 <div class="small text-muted"><?= e($effCardNote) ?></div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-calendar-month"></i></div>
            <div><div class="stat-label">This Month</div>
                 <?php
                 $thisMonthKey = date('Y-m');
                 $thisMonth = 0.0;
                 foreach ($monthly as $m) { if ($m['ym'] === $thisMonthKey) { $thisMonth = (float)$m['t']; } }
                 ?>
                 <div class="stat-value fs-4"><?= format_money($thisMonth) ?></div></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Per vehicle -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">By vehicle</div>
            <?php if (!$perVehicle): ?>
                <div class="card-body text-muted">No fuel records yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>Vehicle</th><th class="text-end">Fill-ups</th><th class="text-end">Fuel used</th>
                            <th class="text-end">Cost</th><th class="text-end">Avg efficiency</th></tr></thead>
                        <tbody>
                        <?php foreach ($perVehicle as $pv):
                            $unit = fuel_unit($pv['fuel_type']);
                            $eff  = $effByVehicle[(int)$pv['id']] ?? null; ?>
                            <tr>
                                <td><?= e($pv['registration_number']) ?>
                                    <div class="small text-muted"><?= e($pv['make'] . ' ' . $pv['model']) ?> &middot; <?= e($pv['fuel_type']) ?></div></td>
                                <td class="text-end"><?= (int)$pv['c'] ?></td>
                                <td class="text-end"><?= e(number_format((float)$pv['q'], 2)) ?> <?= e($unit) ?></td>
                                <td class="text-end"><?= format_money($pv['t']) ?></td>
                                <td class="text-end">
                                    <?php if ($eff && $eff['avg'] !== null): ?>
                                        <strong><?= e(number_format($eff['avg'], 2)) ?></strong> km/<?= e($unit) ?>
                                        <div class="small text-muted">over <?= format_number($eff['distance']) ?> km</div>
                                    <?php else: ?>
                                        <span class="text-muted small">Needs 2 full-tank fills</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Monthly -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Monthly fuel expense <span class="text-muted fw-normal small">(latest 12)</span></div>
            <div class="card-body">
                <?php if (!$monthly): ?><p class="text-muted mb-0">No data.</p><?php endif; ?>
                <?php foreach ($monthly as $m):
                    $w = $monthlyMax > 0 ? (int)round(((float)$m['t'] / $monthlyMax) * 100) : 0; ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span><?= e(date('M Y', strtotime($m['ym'] . '-01'))) ?></span>
                            <strong><?= format_money($m['t']) ?></strong>
                        </div>
                        <div class="progress" style="height:6px"><div class="progress-bar bg-info" style="width:<?= $w ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
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
            <div class="col-md-2">
                <label class="form-label small mb-1" for="f_from">From</label>
                <input type="date" class="form-control" id="f_from" name="date_from" value="<?= e($fFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="f_to">To</label>
                <input type="date" class="form-control" id="f_to" name="date_to" value="<?= e($fTo) ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Filter</button>
                <?php if ($filtersOn): ?><a href="<?= url('/fuel/') ?>" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Fuel log -->
<?php if (!$logs): ?>
    <div class="card"><div class="card-body text-center py-5">
        <i class="bi bi-fuel-pump display-4 text-primary"></i>
        <h5 class="mt-3"><?= $filtersOn ? 'No fuel records match these filters' : 'No fuel records yet' ?></h5>
        <p class="text-muted mb-0"><?= $filtersOn ? 'Try changing or clearing the filters.' : 'Add your first fill-up to start tracking fuel cost and mileage.' ?></p>
    </div></div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Fuel Records</span>
            <span class="badge text-bg-secondary"><?= count($logs) ?><?= count($logs) < $totalFills ? ' of ' . $totalFills : '' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Vehicle</th><th class="text-end">Odometer</th><th class="text-end">Quantity</th>
                    <th class="text-end">Price</th><th class="text-end">Total</th><th class="text-end">Efficiency</th>
                    <th>Station</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l): $unit = fuel_unit($l['fuel_type']); ?>
                    <tr>
                        <td class="text-nowrap"><?= format_date($l['fuel_date']) ?></td>
                        <td><a class="text-decoration-none" href="<?= url('/vehicles/view.php?id=' . (int)$l['vehicle_id']) ?>"><?= e($l['registration_number']) ?></a>
                            <div class="small text-muted"><?= e($l['make'] . ' ' . $l['model']) ?></div></td>
                        <td class="text-end text-nowrap"><?= format_number($l['odometer_reading']) ?> km</td>
                        <td class="text-end text-nowrap"><?= e(number_format((float)$l['fuel_quantity'], 2)) ?> <?= e($unit) ?>
                            <?php if (!(int)$l['is_full_tank']): ?><div><span class="badge text-bg-secondary">Partial</span></div><?php endif; ?></td>
                        <td class="text-end text-nowrap"><?= format_money($l['price_per_liter']) ?></td>
                        <td class="text-end text-nowrap"><strong><?= format_money($l['total_cost']) ?></strong></td>
                        <td class="text-end text-nowrap">
                            <?php if (isset($effMap[(int)$l['id']])): ?>
                                <?= e(number_format($effMap[(int)$l['id']], 2)) ?> km/<?= e($unit) ?>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <td><?= e($l['fuel_station'] ?? '-') ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= url('/fuel/edit.php?id=' . (int)$l['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url('/fuel/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        data-confirm="Delete the fuel record from <?= e(format_date($l['fuel_date'])) ?>? This cannot be undone.">
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

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
