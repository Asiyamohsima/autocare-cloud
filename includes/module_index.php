<?php
/** Shared list page for Insurance, Warranty and Registration. Expects $module. */
$cfg = module_config($module);
if ($cfg === null) {
    http_response_code(404);
    exit;
}

$pageTitle  = $cfg['title'];
$activePage = $module;
$base       = '/' . $module . '/';
$db         = require_db($module);
$exp        = $cfg['expiry_field'];

$fVehicle      = valid_id($_GET['vehicle_id'] ?? null);
$statusOptions = ['valid' => $cfg['valid_label'], 'soon' => 'Expiring Soon', 'expired' => 'Expired', 'none' => 'No expiry date'];
$fStatus       = (isset($_GET['status']) && is_string($_GET['status']) && isset($statusOptions[$_GET['status']])) ? $_GET['status'] : '';
$filtersOn     = ($fVehicle !== null || $fStatus !== '');

$vehicles = []; $rows = []; $docCounts = []; $loadError = false;
$counts = ['total' => 0, 'soon' => 0, 'expired' => 0];

try {
    $vehicles  = list_vehicles_for_select($db);
    $docCounts = module_doc_counts($db, $cfg['doc_type']);

    $sql = 'SELECT t.*, v.registration_number AS veh_reg, v.make, v.model
              FROM `' . $cfg['table'] . '` t JOIN vehicles v ON v.id = t.vehicle_id'
         . ($fVehicle !== null ? ' WHERE t.vehicle_id = ?' : '')
         . ' ORDER BY t.`' . $exp . '` IS NULL, t.`' . $exp . '` DESC, t.id DESC LIMIT 500';
    $stmt = $db->prepare($sql);
    if ($fVehicle !== null) {
        $stmt->bind_param('i', $fVehicle);
    }
    $stmt->execute();
    $all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $seen = [];
    foreach ($all as $r) {
        $vid     = (int)$r['vehicle_id'];
        $current = !$cfg['history'] || !isset($seen[$vid]);
        $seen[$vid]    = true;
        $r['_current'] = $current;
        $r['_status']  = $current
            ? module_status($cfg, $r[$exp])
            : ['label' => 'Previous', 'class' => 'secondary', 'key' => 'previous', 'text' => 'Replaced by a newer record'];

        $counts['total']++;
        if ($current && $r['_status']['key'] === 'soon')    { $counts['soon']++; }
        if ($current && $r['_status']['key'] === 'expired') { $counts['expired']++; }

        if ($fStatus !== '' && (!$current || $r['_status']['key'] !== $fStatus)) {
            continue;
        }
        $rows[] = $r;
    }
} catch (Throwable $ex) {
    error_log('[AutoCare] ' . $module . ' list failed: ' . $ex->getMessage());
    $loadError = true;
}

require __DIR__ . '/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1><?= e($cfg['title']) ?></h1>
        <p>Track dates and keep the paperwork in Amazon S3.</p>
    </div>
    <a href="<?= url($base . 'add.php' . ($fVehicle ? '?vehicle_id=' . $fVehicle : '')) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add <?= e(strtolower($cfg['singular'])) ?>
    </a>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger">Could not load records right now. Please try again later.</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle text-primary"><i class="bi <?= e($cfg['icon']) ?>"></i></div>
        <div><div class="stat-label">Records</div><div class="stat-value"><?= (int)$counts['total'] ?></div></div>
    </div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="stat-label">Expiring in 30 days</div><div class="stat-value"><?= (int)$counts['soon'] ?></div></div>
    </div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-x-octagon"></i></div>
        <div><div class="stat-label">Expired</div><div class="stat-value"><?= (int)$counts['expired'] ?></div></div>
    </div></div></div>
</div>

<div class="card mb-3"><div class="card-body">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small mb-1" for="f_vehicle">Vehicle</label>
            <select class="form-select" id="f_vehicle" name="vehicle_id">
                <option value="">All vehicles</option>
                <?php foreach ($vehicles as $veh): ?>
                    <option value="<?= (int)$veh['id'] ?>" <?= $fVehicle === (int)$veh['id'] ? 'selected' : '' ?>>
                        <?= e($veh['registration_number'] . ' - ' . $veh['make'] . ' ' . $veh['model']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1" for="f_status">Status</label>
            <select class="form-select" id="f_status" name="status">
                <option value="">Any status</option>
                <?php foreach ($statusOptions as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $fStatus === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill">Filter</button>
            <?php if ($filtersOn): ?><a href="<?= url($base) ?>" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
    </form>
</div></div>

<?php if (!$rows): ?>
    <div class="card"><div class="card-body text-center py-5">
        <i class="bi <?= e($cfg['icon']) ?> display-4 text-primary"></i>
        <h5 class="mt-3"><?= $filtersOn ? 'No records match these filters' : 'No records yet' ?></h5>
        <p class="text-muted mb-0"><?= $filtersOn ? 'Try changing or clearing the filters.' : 'Add your first record to start tracking.' ?></p>
    </div></div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><?= e($cfg['title']) ?> records</span><span class="badge text-bg-secondary"><?= count($rows) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Vehicle</th>
                    <?php foreach ($cfg['columns'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
                    <th>Expiry</th><th>Documents</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $st = $r['_status'];
                    $dc = $docCounts[(int)$r['vehicle_id']] ?? 0;
                    $docUrl    = url('/documents/?vehicle_id=' . (int)$r['vehicle_id'] . '&document_type=' . rawurlencode($cfg['doc_type']));
                    $uploadUrl = url('/documents/upload.php?vehicle_id=' . (int)$r['vehicle_id'] . '&type=' . rawurlencode($cfg['doc_type']));
                ?>
                    <tr<?= $r['_current'] ? '' : ' class="text-muted"' ?>>
                        <td><a class="text-decoration-none fw-semibold" href="<?= url('/vehicles/view.php?id=' . (int)$r['vehicle_id']) ?>"><?= e($r['veh_reg']) ?></a>
                            <div class="small text-muted"><?= e($r['make'] . ' ' . $r['model']) ?></div>
                            <?php if ($cfg['history'] && $r['_current']): ?><span class="badge text-bg-primary">Current</span><?php endif; ?></td>
                        <?php foreach ($cfg['columns'] as $name => $label): ?>
                            <td><?= module_format_value($cfg['fields'][$name], $r[$name]) ?></td>
                        <?php endforeach; ?>
                        <td class="text-nowrap">
                            <?= $r[$exp] ? e(format_date($r[$exp])) : '-' ?>
                            <div><span class="badge text-bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                                 <span class="small text-muted"><?= e($st['text']) ?></span></div>
                        </td>
                        <td class="text-nowrap">
                            <a class="text-decoration-none" href="<?= e($docUrl) ?>"><?= (int)$dc ?> file(s)</a>
                            <div><a class="btn btn-sm btn-outline-primary mt-1" href="<?= e($uploadUrl) ?>"><i class="bi bi-upload me-1"></i>Upload</a></div>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="<?= url($base . 'edit.php?id=' . (int)$r['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url($base . 'delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        data-confirm="Delete this record? Uploaded documents are not deleted. This cannot be undone.">
                                    <i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($cfg['history']): ?>
        <p class="small text-muted mt-2">For each vehicle the record with the latest expiry date is <strong>Current</strong>. Older records are kept as history and shown as <em>Previous</em>.</p>
    <?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
