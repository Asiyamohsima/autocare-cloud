<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/expense_helpers.php';

$pageTitle  = 'Expenses';
$activePage = 'expenses';
$db = require_db('expenses');

// ---- Read and validate filters ----
$fVehicle = valid_id($_GET['vehicle_id'] ?? null);
$fCat     = (isset($_GET['category']) && is_string($_GET['category'])
             && in_array($_GET['category'], expense_categories(), true)) ? $_GET['category'] : '';
$fFrom    = (isset($_GET['date_from']) && is_string($_GET['date_from']) && is_valid_date($_GET['date_from'])) ? $_GET['date_from'] : '';
$fTo      = (isset($_GET['date_to'])   && is_string($_GET['date_to'])   && is_valid_date($_GET['date_to']))   ? $_GET['date_to']   : '';
$filtersOn = ($fVehicle !== null || $fCat !== '' || $fFrom !== '' || $fTo !== '');

/** Builds the WHERE part. Returns [sql, types, params]. */
$build = function (bool $withDates, ?string $monthStart = null) use ($fVehicle, $fCat, $fFrom, $fTo): array {
    $where = []; $types = ''; $params = [];
    if ($fVehicle !== null) { $where[] = 'e.vehicle_id = ?';   $types .= 'i'; $params[] = $fVehicle; }
    if ($fCat !== '')       { $where[] = 'e.category = ?';     $types .= 's'; $params[] = $fCat; }
    if ($withDates) {
        if ($fFrom !== '')  { $where[] = 'e.expense_date >= ?'; $types .= 's'; $params[] = $fFrom; }
        if ($fTo !== '')    { $where[] = 'e.expense_date <= ?'; $types .= 's'; $params[] = $fTo; }
    }
    if ($monthStart !== null) { $where[] = 'e.expense_date >= ?'; $types .= 's'; $params[] = $monthStart; }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $types, $params];
};

/** Runs a prepared query: $select + WHERE + $tail. */
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

$vehicles = []; $expenses = []; $byCategory = []; $byVehicle = []; $monthly = [];
$summary = ['c' => 0, 't' => 0]; $monthTotal = 0;
$loadError = false;

try {
    $vehicles   = list_vehicles_for_select($db);
    $summary    = $run('SELECT COUNT(*) AS c, COALESCE(SUM(e.amount), 0) AS t FROM expenses e', $build(true))[0];
    $monthTotal = $run('SELECT COALESCE(SUM(e.amount), 0) AS t FROM expenses e', $build(false, date('Y-m-01')))[0]['t'];

    $byCategory = $run('SELECT e.category, COUNT(*) AS c, SUM(e.amount) AS t FROM expenses e',
                       $build(true), ' GROUP BY e.category ORDER BY t DESC');

    $byVehicle = $run('SELECT v.registration_number, v.make, v.model, COUNT(*) AS c, SUM(e.amount) AS t
                         FROM expenses e JOIN vehicles v ON v.id = e.vehicle_id',
                      $build(true), ' GROUP BY v.id, v.registration_number, v.make, v.model ORDER BY t DESC');

    $monthly = $run('SELECT DATE_FORMAT(e.expense_date, \'%Y-%m\') AS ym, SUM(e.amount) AS t FROM expenses e',
                    $build(true), ' GROUP BY ym ORDER BY ym DESC LIMIT 12');

    $expenses = $run('SELECT e.*, v.registration_number, v.make, v.model
                        FROM expenses e JOIN vehicles v ON v.id = e.vehicle_id',
                     $build(true), ' ORDER BY e.expense_date DESC, e.id DESC LIMIT 200');
} catch (Throwable $ex) {
    error_log('[AutoCare] Expense list failed: ' . $ex->getMessage());
    $loadError = true;
}

$grand = (float)$summary['t'];
$pct   = fn($amount): int => $grand > 0 ? (int)round(((float)$amount / $grand) * 100) : 0;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1>Expenses</h1>
        <p>Parking, tolls, accessories and other vehicle costs.</p>
    </div>
    <a href="<?= url('/expenses/add.php' . ($fVehicle ? '?vehicle_id=' . $fVehicle : '')) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add Expense
    </a>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger">Could not load expenses right now. Please try again later.</div>
<?php else: ?>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-cash-coin"></i></div>
            <div><div class="stat-label"><?= $filtersOn ? 'Total (filtered)' : 'Total Expenses' ?></div>
                 <div class="stat-value"><?= format_money($summary['t']) ?></div></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-calendar-month"></i></div>
            <div><div class="stat-label">This Month</div>
                 <div class="stat-value"><?= format_money($monthTotal) ?></div></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-receipt"></i></div>
            <div><div class="stat-label">Records</div>
                 <div class="stat-value"><?= (int)$summary['c'] ?></div></div>
        </div></div>
    </div>
</div>

<!-- Breakdowns -->
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">By category</div>
            <div class="card-body">
                <?php if (!$byCategory): ?><p class="text-muted mb-0">No data.</p><?php endif; ?>
                <?php foreach ($byCategory as $row): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span><?= e($row['category']) ?> <span class="text-muted">(<?= (int)$row['c'] ?>)</span></span>
                            <strong><?= format_money($row['t']) ?></strong>
                        </div>
                        <div class="progress" style="height:6px"><div class="progress-bar" style="width:<?= $pct($row['t']) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">By vehicle</div>
            <div class="card-body">
                <?php if (!$byVehicle): ?><p class="text-muted mb-0">No data.</p><?php endif; ?>
                <?php foreach ($byVehicle as $row): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span><?= e($row['registration_number']) ?> <span class="text-muted">(<?= (int)$row['c'] ?>)</span></span>
                            <strong><?= format_money($row['t']) ?></strong>
                        </div>
                        <div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:<?= $pct($row['t']) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Monthly <span class="text-muted fw-normal small">(latest 12)</span></div>
            <div class="card-body">
                <?php if (!$monthly): ?><p class="text-muted mb-0">No data.</p><?php endif; ?>
                <?php foreach ($monthly as $row): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span><?= e(date('M Y', strtotime($row['ym'] . '-01'))) ?></span>
                            <strong><?= format_money($row['t']) ?></strong>
                        </div>
                        <div class="progress" style="height:6px"><div class="progress-bar bg-info" style="width:<?= $pct($row['t']) ?>%"></div></div>
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
                <label class="form-label small mb-1" for="f_cat">Category</label>
                <select class="form-select" id="f_cat" name="category">
                    <option value="">All categories</option>
                    <?php foreach (expense_categories() as $c): ?>
                        <option value="<?= e($c) ?>" <?= $fCat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
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
                <?php if ($filtersOn): ?><a href="<?= url('/expenses/') ?>" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- List -->
<?php if (!$expenses): ?>
    <div class="card"><div class="card-body text-center py-5">
        <i class="bi bi-cash-coin display-4 text-primary"></i>
        <h5 class="mt-3"><?= $filtersOn ? 'No expenses match these filters' : 'No expenses yet' ?></h5>
        <p class="text-muted mb-0"><?= $filtersOn ? 'Try changing or clearing the filters.' : 'Add your first expense to start tracking costs.' ?></p>
    </div></div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Expense Records</span>
            <span class="badge text-bg-secondary"><?= count($expenses) ?><?= count($expenses) < (int)$summary['c'] ? ' of ' . (int)$summary['c'] : '' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Vehicle</th><th>Category</th><th>Description</th>
                    <th>Payment</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($expenses as $x): ?>
                    <tr>
                        <td class="text-nowrap"><?= format_date($x['expense_date']) ?></td>
                        <td><a class="text-decoration-none" href="<?= url('/vehicles/view.php?id=' . (int)$x['vehicle_id']) ?>"><?= e($x['registration_number']) ?></a>
                            <div class="small text-muted"><?= e($x['make'] . ' ' . $x['model']) ?></div></td>
                        <td><span class="badge text-bg-light border"><?= e($x['category']) ?></span></td>
                        <td><?= e($x['description'] ?? '-') ?>
                            <?php if ($x['notes']): ?><div class="small text-muted"><?= e(mb_strimwidth($x['notes'], 0, 60, '...')) ?></div><?php endif; ?></td>
                        <td><?= e($x['payment_method']) ?></td>
                        <td class="text-end text-nowrap"><strong><?= format_money($x['amount']) ?></strong></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= url('/expenses/edit.php?id=' . (int)$x['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url('/expenses/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        data-confirm="Delete this <?= e($x['category']) ?> expense of <?= e(format_money($x['amount'])) ?>? This cannot be undone.">
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
