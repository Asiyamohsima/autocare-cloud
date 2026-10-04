<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document_helpers.php';

$pageTitle  = 'Documents';
$activePage = 'documents';
$db = require_db('documents');

// ---- Read and validate filters ----
$fVehicle = valid_id($_GET['vehicle_id'] ?? null);
$fType    = (isset($_GET['document_type']) && is_string($_GET['document_type'])
             && in_array($_GET['document_type'], document_types(), true)) ? $_GET['document_type'] : '';

$statusOptions = ['valid' => 'Valid', 'soon' => 'Expiring Soon', 'expired' => 'Expired', 'none' => 'No expiry date'];
$fStatus  = (isset($_GET['expiry_status']) && is_string($_GET['expiry_status'])
             && isset($statusOptions[$_GET['expiry_status']])) ? $_GET['expiry_status'] : '';

$filtersOn = ($fVehicle !== null || $fType !== '' || $fStatus !== '');

$today    = date('Y-m-d');
$soonDate = (new DateTimeImmutable('today'))->modify('+' . DOC_SOON_DAYS . ' days')->format('Y-m-d');

$where = []; $types = ''; $params = [];
if ($fVehicle !== null) { $where[] = 'd.vehicle_id = ?';    $types .= 'i'; $params[] = $fVehicle; }
if ($fType !== '')      { $where[] = 'd.document_type = ?'; $types .= 's'; $params[] = $fType; }
if ($fStatus === 'expired') {
    $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date < ?';
    $types .= 's'; $params[] = $today;
} elseif ($fStatus === 'soon') {
    $where[] = 'd.expiry_date >= ? AND d.expiry_date <= ?';
    $types .= 'ss'; $params[] = $today; $params[] = $soonDate;
} elseif ($fStatus === 'valid') {
    $where[] = 'd.expiry_date > ?';
    $types .= 's'; $params[] = $soonDate;
} elseif ($fStatus === 'none') {
    $where[] = 'd.expiry_date IS NULL';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$vehicles = []; $docs = [];
$summary  = ['total' => 0, 'bytes' => 0, 'expired' => 0, 'soon' => 0];
$loadError = false;

try {
    $vehicles = list_vehicles_for_select($db);

    // Overall numbers for the cards (not affected by the filters)
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(file_size), 0) AS bytes,
                COALESCE(SUM(expiry_date IS NOT NULL AND expiry_date < ?), 0) AS expired,
                COALESCE(SUM(expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= ?), 0) AS soon
           FROM documents'
    );
    $stmt->bind_param('sss', $today, $today, $soonDate);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // The filtered list
    $stmt = $db->prepare(
        'SELECT d.*, v.registration_number, v.make, v.model
           FROM documents d JOIN vehicles v ON v.id = d.vehicle_id'
        . $whereSql . ' ORDER BY d.uploaded_at DESC, d.id DESC LIMIT 200'
    );
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $ex) {
    error_log('[AutoCare] Document list failed: ' . $ex->getMessage());
    $loadError = true;
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1>Documents</h1>
        <p>Insurance, registration, certificates, bills and photos, stored securely in Amazon S3.</p>
    </div>
    <a href="<?= url('/documents/upload.php' . ($fVehicle ? '?vehicle_id=' . $fVehicle : '')) ?>" class="btn btn-primary">
        <i class="bi bi-upload me-1"></i>Upload Document
    </a>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger">Could not load documents right now. Please try again later.</div>
<?php else: ?>

<?php if ((int)$summary['expired'] > 0): ?>
    <div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong><?= (int)$summary['expired'] ?></strong> document(s) have expired.</div>
        <a class="btn btn-sm btn-outline-danger" href="<?= url('/documents/?expiry_status=expired') ?>">Show expired</a>
    </div>
<?php endif; ?>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-folder2-open"></i></div>
            <div><div class="stat-label">Documents</div><div class="stat-value"><?= (int)$summary['total'] ?></div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-cloud"></i></div>
            <div><div class="stat-label">Stored in S3</div><div class="stat-value fs-4"><?= e(format_bytes((int)$summary['bytes'])) ?></div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
            <div><div class="stat-label">Expiring in <?= DOC_SOON_DAYS ?> days</div><div class="stat-value"><?= (int)$summary['soon'] ?></div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-x-octagon"></i></div>
            <div><div class="stat-label">Expired</div><div class="stat-value"><?= (int)$summary['expired'] ?></div></div>
        </div></div>
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
                <label class="form-label small mb-1" for="f_type">Document type</label>
                <select class="form-select" id="f_type" name="document_type">
                    <option value="">All types</option>
                    <?php foreach (document_types() as $t): ?>
                        <option value="<?= e($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="f_status">Expiry status</label>
                <select class="form-select" id="f_status" name="expiry_status">
                    <option value="">Any status</option>
                    <?php foreach ($statusOptions as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $fStatus === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Filter</button>
                <?php if ($filtersOn): ?><a href="<?= url('/documents/') ?>" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Document list -->
<?php if (!$docs): ?>
    <div class="card"><div class="card-body text-center py-5">
        <i class="bi bi-folder2-open display-4 text-primary"></i>
        <h5 class="mt-3"><?= $filtersOn ? 'No documents match these filters' : 'No documents yet' ?></h5>
        <p class="text-muted"><?= $filtersOn ? 'Try changing or clearing the filters.' : 'Upload your first document. It is stored privately in Amazon S3.' ?></p>
        <?php if (!$filtersOn): ?><a href="<?= url('/documents/upload.php') ?>" class="btn btn-primary">Upload Document</a><?php endif; ?>
    </div></div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Document Records</span>
            <span class="badge text-bg-secondary"><?= count($docs) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Document</th><th>Vehicle</th><th>Type</th><th>Expiry</th>
                    <th class="text-end">Size</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($docs as $d):
                    $ext = document_extension($d['s3_key']);
                    $st  = expiry_status($d['expiry_date'], DOC_SOON_DAYS);
                ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi <?= e(document_icon($ext)) ?> fs-4"></i>
                                <div>
                                    <div class="fw-semibold"><?= e($d['document_name']) ?></div>
                                    <div class="text-muted text-break font-monospace" style="font-size:.7rem"><?= e($d['s3_key']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><a class="text-decoration-none" href="<?= url('/vehicles/view.php?id=' . (int)$d['vehicle_id']) ?>"><?= e($d['registration_number']) ?></a>
                            <div class="small text-muted"><?= e($d['make'] . ' ' . $d['model']) ?></div></td>
                        <td><span class="badge text-bg-light border"><?= e($d['document_type']) ?></span></td>
                        <td class="text-nowrap">
                            <?php if ($d['expiry_date']): ?>
                                <?= format_date($d['expiry_date']) ?>
                                <div><span class="badge text-bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                                     <span class="small text-muted"><?= e($st['text']) ?></span></div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap"><?= e(format_bytes((int)$d['file_size'])) ?></td>
                        <td class="text-nowrap"><?= format_date($d['uploaded_at']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= url('/documents/download.php?id=' . (int)$d['id']) ?>"
                               class="btn btn-sm btn-outline-primary" title="Download"><i class="bi bi-download"></i></a>
                            <form method="post" action="<?= url('/documents/delete.php') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        data-confirm="Delete &quot;<?= e($d['document_name']) ?>&quot;? The file will be permanently removed from storage. This cannot be undone.">
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
