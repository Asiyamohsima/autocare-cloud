<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dashboard_helpers.php';

$pageTitle  = 'Analytics';
$activePage = 'analytics';
$db = require_db('analytics');

$fVehicle = valid_id($_GET['vehicle_id'] ?? null);
$fFrom    = (isset($_GET['date_from']) && is_string($_GET['date_from']) && is_valid_date($_GET['date_from'])) ? $_GET['date_from'] : '';
$fTo      = (isset($_GET['date_to'])   && is_string($_GET['date_to'])   && is_valid_date($_GET['date_to']))   ? $_GET['date_to']   : '';
$dateError = '';
if ($fFrom !== '' && $fTo !== '' && $fFrom > $fTo) {
    $dateError = 'The "From" date is after the "To" date, so the date filter was ignored.';
    $fFrom = $fTo = '';
}
$filtersOn = ($fVehicle !== null || $fFrom !== '' || $fTo !== '');

$where = []; $types = ''; $params = [];
if ($fVehicle !== null) { $where[] = 'c.vehicle_id = ?'; $types .= 'i'; $params[] = $fVehicle; }
if ($fFrom !== '')      { $where[] = 'c.cost_date >= ?'; $types .= 's'; $params[] = $fFrom; }
if ($fTo !== '')        { $where[] = 'c.cost_date <= ?'; $types .= 's'; $params[] = $fTo; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$groups  = ['Maintenance', 'Repairs', 'Fuel', 'Insurance', 'Other'];
$groupOf = fn(string $c): string => in_array($c, ['Maintenance', 'Repairs', 'Fuel', 'Insurance'], true) ? $c : 'Other';

$vehicles = []; $matrix = []; $catTotals = []; $monthLabels = []; $monthValues = [];
$loadError = false;

try {
    $vehicles = list_vehicles_for_select($db);
    $ledger   = '(' . cost_ledger_sql() . ') c';

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

    foreach ($run("SELECT c.vehicle_id, c.category, SUM(c.amount) AS t FROM $ledger$whereSql GROUP BY c.vehicle_id, c.category") as $r) {
        $vid = (int)$r['vehicle_id'];
        $g   = $groupOf($r['category']);
        $catTotals[$r['category']] = ($catTotals[$r['category']] ?? 0) + (float)$r['t'];
        $matrix[$vid][$g]          = ($matrix[$vid][$g] ?? 0) + (float)$r['t'];
    }
    arsort($catTotals);

    $monthMap = [];
    foreach ($run("SELECT DATE_FORMAT(c.cost_date, '%Y-%m') AS ym, SUM(c.amount) AS t FROM $ledger$whereSql GROUP BY ym ORDER BY ym") as $r) {
        $monthMap[$r['ym']] = (float)$r['t'];
    }
    if ($monthMap) {
        $keys = array_keys($monthMap);
        sort($keys);
        $cur = new DateTimeImmutable($keys[0] . '-01');
        $end = new DateTimeImmutable(end($keys) . '-01');
        for ($n = 0; $cur <= $end && $n < 120; $n++) {
            $monthLabels[] = date('M Y', $cur->getTimestamp());
            $monthValues[] = round($monthMap[$cur->format('Y-m')] ?? 0, 2);
            $cur = $cur->modify('+1 month');
        }
        if (count($monthLabels) > 36) {
            $monthLabels = array_slice($monthLabels, -36);
            $monthValues = array_slice($monthValues, -36);
        }
    }
} catch (Throwable $ex) {
    error_log('[AutoCare] Analytics failed: ' . $ex->getMessage());
    $loadError = true;
}

$grand = array_sum($catTotals);

// Vehicle comparison chart data (only vehicles that have costs)
$vehLabels = []; $vehSets = array_fill_keys($groups, []);
$vehRows   = [];
foreach ($vehicles as $v) {
    $vid = (int)$v['id'];
    if (!isset($matrix[$vid])) { continue; }
    $vehLabels[] = $v['registration_number'];
    $row = ['v' => $v, 'total' => 0.0];
    foreach ($groups as $g) {
        $val = round($matrix[$vid][$g] ?? 0, 2);
        $vehSets[$g][] = $val;
        $row[$g] = $val;
        $row['total'] += $val;
    }
    $vehRows[] = $row;
}
$vehSetsOut = [];
foreach ($groups as $g) { $vehSetsOut[] = ['label' => $g, 'data' => $vehSets[$g]]; }

$json = json_encode([
    'monthLabels' => $monthLabels,
    'monthValues' => $monthValues,
    'catLabels'   => array_keys($catTotals),
    'catValues'   => array_map(fn($v) => round($v, 2), array_values($catTotals)),
    'vehLabels'   => $vehLabels,
    'vehSets'     => $vehSetsOut,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>'
              . '<script>var CHART_DATA = ' . $json . ';</script>'
              . '<script src="' . e(url('/assets/js/charts.js')) . '"></script>';

require __DIR__ . '/../includes/header.php';

$stat = function (string $icon, string $tone, string $label, $amount): void {
    echo '<div class="col-sm-6 col-xl"><div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">'
       . '<div class="stat-icon bg-' . e($tone) . '-subtle text-' . e($tone) . '"><i class="bi ' . e($icon) . '"></i></div>'
       . '<div><div class="stat-label">' . e($label) . '</div><div class="stat-value fs-5">' . e(format_money($amount)) . '</div></div>'
       . '</div></div></div>';
};
?>

<div class="page-header mb-4">
    <h1>Analytics</h1>
    <p>Where your vehicle money goes, by category, month and vehicle.</p>
</div>

<?php if ($dateError): ?><div class="alert alert-warning"><?= e($dateError) ?></div><?php endif; ?>

<div class="card mb-3"><div class="card-body">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-1" for="f_vehicle">Vehicle</label>
            <select class="form-select" id="f_vehicle" name="vehicle_id">
                <option value="">All vehicles</option>
                <?php foreach ($vehicles as $veh): ?>
                    <option value="<?= (int)$veh['id'] ?>" <?= $fVehicle === (int)$veh['id'] ? 'selected' : '' ?>>
                        <?= e($veh['registration_number'] . ' - ' . $veh['make'] . ' ' . $veh['model']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small mb-1" for="f_from">From</label>
            <input type="date" class="form-control" id="f_from" name="date_from" value="<?= e($fFrom) ?>"></div>
        <div class="col-md-2"><label class="form-label small mb-1" for="f_to">To</label>
            <input type="date" class="form-control" id="f_to" name="date_to" value="<?= e($fTo) ?>"></div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill">Apply</button>
            <?php if ($filtersOn): ?><a href="<?= url('/analytics/') ?>" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
    </form>
</div></div>

<?php if ($loadError): ?>
    <div class="alert alert-danger">Could not load analytics right now. Please try again later.</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <?php
    $stat('bi-wrench',        'primary', 'Maintenance Cost', $catTotals['Maintenance'] ?? 0);
    $stat('bi-tools',         'danger',  'Repair Cost',      $catTotals['Repairs'] ?? 0);
    $stat('bi-fuel-pump',     'info',    'Fuel Cost',        $catTotals['Fuel'] ?? 0);
    $stat('bi-shield-check',  'success', 'Insurance Cost',   $catTotals['Insurance'] ?? 0);
    $stat('bi-cash-stack',    'warning', 'Total Expenses',   $grand);
    ?>
</div>

<?php if ($grand <= 0): ?>
    <div class="card"><div class="card-body text-center py-5">
        <i class="bi bi-bar-chart-line display-4 text-primary"></i>
        <h5 class="mt-3">No cost data<?= $filtersOn ? ' for these filters' : ' yet' ?></h5>
        <p class="text-muted mb-0">Add services, fuel records, expenses or insurance premiums to see charts.</p>
    </div></div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-lg-8"><div class="card h-100"><div class="card-header">Monthly expenses</div>
        <div class="card-body"><div style="height:300px"><canvas id="chartMonthly"></canvas></div></div></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header">Expense categories</div>
        <div class="card-body"><div style="height:300px"><canvas id="chartCategory"></canvas></div></div></div></div>
    <div class="col-12"><div class="card"><div class="card-header">Vehicle comparison</div>
        <div class="card-body"><div style="height:320px"><canvas id="chartVehicles"></canvas></div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-5"><div class="card h-100"><div class="card-header">By category</div>
        <div class="table-responsive"><table class="table mb-0">
            <thead><tr><th>Category</th><th class="text-end">Amount</th><th class="text-end">Share</th></tr></thead><tbody>
            <?php foreach ($catTotals as $name => $amount): ?>
                <tr><td><?= e($name) ?></td><td class="text-end"><?= format_money($amount) ?></td>
                    <td class="text-end"><?= $grand > 0 ? e(number_format(($amount / $grand) * 100, 1)) : '0' ?>%</td></tr>
            <?php endforeach; ?>
            <tr class="fw-bold"><td>Total</td><td class="text-end"><?= format_money($grand) ?></td><td class="text-end">100%</td></tr>
        </tbody></table></div></div></div>
    <div class="col-lg-7"><div class="card h-100"><div class="card-header">By vehicle</div>
        <div class="table-responsive"><table class="table mb-0 align-middle">
            <thead><tr><th>Vehicle</th><th class="text-end">Maint.</th><th class="text-end">Repairs</th><th class="text-end">Fuel</th>
                <th class="text-end">Insurance</th><th class="text-end">Other</th><th class="text-end">Total</th></tr></thead><tbody>
            <?php foreach ($vehRows as $row): ?>
                <tr><td><?= e($row['v']['registration_number']) ?><div class="small text-muted"><?= e($row['v']['make'] . ' ' . $row['v']['model']) ?></div></td>
                    <?php foreach ($groups as $g): ?><td class="text-end"><?= format_money($row[$g]) ?></td><?php endforeach; ?>
                    <td class="text-end fw-bold"><?= format_money($row['total']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div></div></div>
</div>

<p class="small text-muted mt-3">
    <strong>How costs are counted:</strong> service records (maintenance, or repairs for engine/electrical repairs), fuel logs,
    insurance premiums (dated by the policy start date) and expenses by their category. Record each cost in only one place,
    or it will be counted twice.
</p>

<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
