<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dashboard_helpers.php';

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$db = require_db('dashboard');

$loadError = false;
$totalVehicles = 0; $totalServices = 0;
$cat = []; $months = []; $maintByMonth = []; $fuelByMonth = [];
$reminders = []; $upcoming = []; $expiring = []; $expiredCount = 0;
$recentServices = []; $recentCosts = []; $vehicles = [];
$spend = []; $svcCount = []; $insExpiry = []; $nextDue = [];

try {
    $totalVehicles = (int)$db->query('SELECT COUNT(*) AS c FROM vehicles')->fetch_assoc()['c'];
    $totalServices = (int)$db->query('SELECT COUNT(*) AS c FROM services')->fetch_assoc()['c'];

    // Totals by category (all time)
    foreach ($db->query('SELECT c.category, SUM(c.amount) AS t FROM (' . cost_ledger_sql() . ') c GROUP BY c.category') as $r) {
        $cat[$r['category']] = (float)$r['t'];
    }
    arsort($cat);

    // Last 12 months
    $first = new DateTimeImmutable('first day of this month');
    for ($i = 11; $i >= 0; $i--) {
        $months[] = $first->modify('-' . $i . ' month')->format('Y-m');
    }
    $maintByMonth = array_fill_keys($months, 0.0);
    $fuelByMonth  = array_fill_keys($months, 0.0);
    $start = $months[0] . '-01';

    $stmt = $db->prepare("SELECT DATE_FORMAT(c.cost_date, '%Y-%m') AS ym, c.category, SUM(c.amount) AS t
                            FROM (" . cost_ledger_sql() . ") c WHERE c.cost_date >= ? GROUP BY ym, c.category");
    $stmt->bind_param('s', $start);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) {
        if (!isset($maintByMonth[$r['ym']])) { continue; }
        if ($r['category'] === 'Maintenance' || $r['category'] === 'Repairs') { $maintByMonth[$r['ym']] += (float)$r['t']; }
        if ($r['category'] === 'Fuel') { $fuelByMonth[$r['ym']] += (float)$r['t']; }
    }
    $stmt->close();

    // Reminders and expiring items
    $reminders = fetch_reminders($db);
    $upcoming  = array_values(array_filter($reminders, fn($r) => $r['status']['priority'] >= 2));
    foreach ($reminders as $r) {
        if (!isset($nextDue[$r['vehicle_id']])) { $nextDue[$r['vehicle_id']] = $r; }
    }
    $expiring = fetch_expiring_items($db, 30);
    foreach ($expiring as $x) {
        if ($x['status']['label'] === 'Expired') { $expiredCount++; }
    }

    // Recent records
    $recentServices = $db->query(
        'SELECT s.service_date, s.service_type, s.odometer_reading, s.total_cost, v.registration_number
           FROM services s JOIN vehicles v ON v.id = s.vehicle_id
          ORDER BY s.service_date DESC, s.id DESC LIMIT 5')->fetch_all(MYSQLI_ASSOC);
    $recentCosts = $db->query(
        'SELECT c.cost_date, c.category, c.amount, c.source, v.registration_number
           FROM (' . cost_ledger_sql() . ') c JOIN vehicles v ON v.id = c.vehicle_id
          ORDER BY c.cost_date DESC, c.amount DESC LIMIT 6')->fetch_all(MYSQLI_ASSOC);

    // Vehicle overview
    $vehicles = $db->query('SELECT id, registration_number, make, model, vehicle_type, current_odometer
                              FROM vehicles ORDER BY registration_number')->fetch_all(MYSQLI_ASSOC);
    foreach ($db->query('SELECT c.vehicle_id, SUM(c.amount) AS t FROM (' . cost_ledger_sql() . ') c GROUP BY c.vehicle_id') as $r) {
        $spend[(int)$r['vehicle_id']] = (float)$r['t'];
    }
    foreach ($db->query('SELECT vehicle_id, COUNT(*) AS c FROM services GROUP BY vehicle_id') as $r) {
        $svcCount[(int)$r['vehicle_id']] = (int)$r['c'];
    }
    foreach ($db->query('SELECT vehicle_id, MAX(expiry_date) AS e FROM insurance GROUP BY vehicle_id') as $r) {
        $insExpiry[(int)$r['vehicle_id']] = $r['e'];
    }
} catch (Throwable $ex) {
    error_log('[AutoCare] Dashboard failed: ' . $ex->getMessage());
    $loadError = true;
}

$maintTotal = ($cat['Maintenance'] ?? 0) + ($cat['Repairs'] ?? 0);
$fuelTotal  = $cat['Fuel'] ?? 0;
$upSub      = $upcoming ? $upcoming[0]['registration_number'] . ': ' . $upcoming[0]['status']['text'] : 'Nothing due soon';

$monthLabels = array_map(fn($m) => date('M y', strtotime($m . '-01')), $months);
$json = json_encode([
    'months'      => $monthLabels,
    'maintenance' => array_map(fn($v) => round($v, 2), array_values($maintByMonth)),
    'fuel'        => array_map(fn($v) => round($v, 2), array_values($fuelByMonth)),
    'catLabels'   => array_keys($cat),
    'catValues'   => array_map(fn($v) => round($v, 2), array_values($cat)),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>'
              . '<script>var CHART_DATA = ' . $json . ';</script>'
              . '<script src="' . e(url('/assets/js/charts.js')) . '"></script>';

$kindLink = ['Document' => '/documents/', 'Insurance' => '/insurance/', 'Registration' => '/registration/', 'Warranty' => '/warranty/'];

require __DIR__ . '/includes/header.php';

$card = function (string $icon, string $tone, string $label, string $value, string $sub = ''): void {
    echo '<div class="col-sm-6 col-xl-4"><div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">'
       . '<div class="stat-icon bg-' . e($tone) . '-subtle text-' . e($tone) . '"><i class="bi ' . e($icon) . '"></i></div>'
       . '<div><div class="stat-label">' . e($label) . '</div><div class="stat-value fs-4">' . e($value) . '</div>'
       . ($sub !== '' ? '<div class="small text-muted">' . e($sub) . '</div>' : '')
       . '</div></div></div></div>';
};
?>

<div class="page-header mb-4">
    <h1>Dashboard</h1>
    <p>Your vehicles, costs and upcoming dates at a glance.</p>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger">Could not load the dashboard data right now. Please try again later.</div>
<?php else: ?>

<?php if ($expiredCount > 0): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div><strong><?= (int)$expiredCount ?></strong> item(s) have <strong>expired</strong>. See &ldquo;Expiring Documents&rdquo; below.</div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php
    $card('bi-car-front',    'primary', 'Total Vehicles',          (string)$totalVehicles);
    $card('bi-tools',        'success', 'Total Services',          (string)$totalServices);
    $card('bi-wrench',       'warning', 'Maintenance & Repairs',   format_money($maintTotal));
    $card('bi-fuel-pump',    'info',    'Fuel Cost',               format_money($fuelTotal));
    $card('bi-bell',         $upcoming ? 'danger' : 'secondary', 'Upcoming Services', (string)count($upcoming), $upSub);
    $card('bi-hourglass-split', $expiring ? 'danger' : 'secondary', 'Expiring Documents', (string)count($expiring),
          $expiredCount ? $expiredCount . ' already expired' : 'Next 30 days');
    ?>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-4"><div class="card h-100"><div class="card-header">Monthly maintenance &amp; repairs</div>
        <div class="card-body"><div style="height:240px"><canvas id="chartMaintenance"></canvas></div></div></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header">Monthly fuel expense</div>
        <div class="card-body"><div style="height:240px"><canvas id="chartFuel"></canvas></div></div></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header">Expense distribution</div>
        <div class="card-body">
            <?php if ($cat): ?><div style="height:240px"><canvas id="chartCategory"></canvas></div>
            <?php else: ?><p class="text-muted mb-0">No expenses recorded yet.</p><?php endif; ?>
        </div></div></div>
</div>

<div class="row g-3 mb-4">
    <!-- Upcoming maintenance -->
    <div class="col-xl-6"><div class="card h-100">
        <div class="card-header d-flex justify-content-between"><span>Upcoming Maintenance</span>
            <a class="small text-decoration-none" href="<?= url('/services/') ?>">All services</a></div>
        <?php if (!$reminders): ?>
            <div class="card-body text-muted">No active reminders. Set a &ldquo;next service&rdquo; date or odometer on a service record.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="table mb-0 align-middle">
            <thead><tr><th>Vehicle</th><th>Service</th><th>Due</th><th>Status</th></tr></thead><tbody>
            <?php foreach (array_slice($reminders, 0, 6) as $r): $st = $r['status']; ?>
                <tr>
                    <td><a class="text-decoration-none fw-semibold" href="<?= url('/vehicles/view.php?id=' . (int)$r['vehicle_id']) ?>"><?= e($r['registration_number']) ?></a></td>
                    <td><?= e($r['service_type']) ?></td>
                    <td class="small"><?= e($st['text']) ?></td>
                    <td><span class="badge text-bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div></div>

    <!-- Expiring documents -->
    <div class="col-xl-6"><div class="card h-100 <?= $expiredCount ? 'border-danger' : '' ?>">
        <div class="card-header d-flex justify-content-between"><span>Expiring Documents</span>
            <span class="small text-muted">expired or within 30 days</span></div>
        <?php if (!$expiring): ?>
            <div class="card-body text-muted">Nothing is expired or expiring soon.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="table mb-0 align-middle">
            <thead><tr><th>Item</th><th>Vehicle</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
            <?php foreach (array_slice($expiring, 0, 8) as $x): $st = $x['status']; ?>
                <tr>
                    <td><span class="badge text-bg-light border"><?= e($x['kind']) ?></span>
                        <a class="text-decoration-none" href="<?= url($kindLink[$x['kind']] . '?vehicle_id=' . (int)$x['vehicle_id']) ?>"><?= e($x['name']) ?></a></td>
                    <td><?= e($x['veh_reg']) ?></td>
                    <td class="text-nowrap"><?= format_date($x['expiry']) ?></td>
                    <td><span class="badge text-bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                        <div class="small text-muted"><?= e($st['text']) ?></div></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div></div>
</div>

<div class="row g-3 mb-4">
    <!-- Recent services -->
    <div class="col-xl-6"><div class="card h-100">
        <div class="card-header">Recent Service Records</div>
        <?php if (!$recentServices): ?><div class="card-body text-muted">No services yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="table mb-0 align-middle">
            <thead><tr><th>Date</th><th>Vehicle</th><th>Service</th><th class="text-end">Cost</th></tr></thead><tbody>
            <?php foreach ($recentServices as $s): ?>
                <tr><td class="text-nowrap"><?= format_date($s['service_date']) ?></td><td><?= e($s['registration_number']) ?></td>
                    <td><?= e($s['service_type']) ?><div class="small text-muted"><?= format_number($s['odometer_reading']) ?> km</div></td>
                    <td class="text-end"><?= format_money($s['total_cost']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div></div>

    <!-- Recent expenses -->
    <div class="col-xl-6"><div class="card h-100">
        <div class="card-header">Recent Expenses</div>
        <?php if (!$recentCosts): ?><div class="card-body text-muted">No expenses yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="table mb-0 align-middle">
            <thead><tr><th>Date</th><th>Vehicle</th><th>Category</th><th class="text-end">Amount</th></tr></thead><tbody>
            <?php foreach ($recentCosts as $c): ?>
                <tr><td class="text-nowrap"><?= format_date($c['cost_date']) ?></td><td><?= e($c['registration_number']) ?></td>
                    <td><?= e($c['category']) ?><div class="small text-muted"><?= e($c['source']) ?></div></td>
                    <td class="text-end"><?= format_money($c['amount']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div></div>
</div>

<!-- Vehicle overview -->
<div class="card">
    <div class="card-header d-flex justify-content-between"><span>Vehicle Overview</span>
        <a class="small text-decoration-none" href="<?= url('/vehicles/') ?>">All vehicles</a></div>
    <?php if (!$vehicles): ?>
        <div class="card-body text-muted">No vehicles yet. <a href="<?= url('/vehicles/add.php') ?>">Add your first vehicle</a>.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Vehicle</th><th class="text-end">Odometer</th><th class="text-end">Services</th>
            <th class="text-end">Total spent</th><th>Next service</th><th>Insurance</th></tr></thead><tbody>
        <?php foreach ($vehicles as $v):
            $vid = (int)$v['id'];
            $nd  = $nextDue[$vid] ?? null;
            $ins = isset($insExpiry[$vid]) ? expiry_status($insExpiry[$vid], 30) : null; ?>
            <tr>
                <td><a class="text-decoration-none fw-semibold" href="<?= url('/vehicles/view.php?id=' . $vid) ?>"><?= e($v['registration_number']) ?></a>
                    <div class="small text-muted"><?= e($v['make'] . ' ' . $v['model']) ?> &middot; <?= e($v['vehicle_type']) ?></div></td>
                <td class="text-end text-nowrap"><?= format_number($v['current_odometer']) ?> km</td>
                <td class="text-end"><?= (int)($svcCount[$vid] ?? 0) ?></td>
                <td class="text-end"><?= format_money($spend[$vid] ?? 0) ?></td>
                <td><?php if ($nd): ?><span class="badge text-bg-<?= e($nd['status']['class']) ?>"><?= e($nd['status']['label']) ?></span>
                        <div class="small text-muted"><?= e($nd['status']['text']) ?></div>
                    <?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                <td><?php if ($ins): ?><span class="badge text-bg-<?= e($ins['class']) ?>"><?= e($ins['label']) ?></span>
                        <div class="small text-muted"><?= e($ins['text']) ?></div>
                    <?php else: ?><span class="text-muted">Not recorded</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
