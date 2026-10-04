<?php
/** Shared Add/Edit page for Insurance, Warranty and Registration. Expects $module and $mode ('add' or 'edit'). */
$cfg = module_config($module);
if ($cfg === null) {
    http_response_code(404);
    exit;
}

$isEdit     = ($mode === 'edit');
$pageTitle  = ($isEdit ? 'Edit ' : 'Add ') . $cfg['singular'];
$activePage = $module;
$base       = '/' . $module . '/';
$db         = require_db($module);
$vehicles   = list_vehicles_for_select($db);
$record     = null;
$id         = null;

if ($isEdit) {
    $id = valid_id($_GET['id'] ?? null);
    if ($id === null) {
        render_error_page('Invalid record', 'The record ID is not valid.', 400, $base, $module);
    }
    try {
        $record = find_module_record($db, $cfg, $id);
    } catch (Throwable $ex) {
        error_log('[AutoCare] Load ' . $module . ' failed: ' . $ex->getMessage());
        render_error_page('Error', 'Something went wrong while loading this record.', 500, $base, $module);
    }
    if ($record === null) {
        render_error_page('Record not found', 'This record does not exist or was deleted.', 404, $base, $module);
    }
} elseif (!$vehicles) {
    set_flash('warning', 'Add a vehicle first, then you can add records for it.');
    redirect('/vehicles/add.php');
}

$locked = $isEdit ? (int)$record['vehicle_id'] : null;
$old    = $isEdit ? module_to_form($cfg, $record) : module_defaults($cfg);
$errors = [];

if (!$isEdit) {
    $pre = valid_id($_GET['vehicle_id'] ?? null);
    if ($pre !== null) {
        $old['vehicle_id'] = (string)$pre;
        if (isset($old['registration_number']) && $old['registration_number'] === '') {
            foreach ($vehicles as $veh) {
                if ((int)$veh['id'] === $pre) {
                    $old['registration_number'] = $veh['registration_number'];
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_module_post($cfg);
    if ($isEdit) {
        $old['vehicle_id'] = (string)$locked;
    }

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_module($cfg, $old, $db, $locked);
        if (!$errors) {
            try {
                if ($isEdit) {
                    update_module_record($db, $cfg, $id, $data);
                } else {
                    insert_module_record($db, $cfg, $data);
                }
                set_flash('success', $cfg['singular'] . ($isEdit ? ' updated.' : ' added.'));
                redirect($base . '?vehicle_id=' . (int)$data['vehicle_id']);
            } catch (Throwable $ex) {
                error_log('[AutoCare] Save ' . $module . ' failed: ' . $ex->getMessage());
                $errors['form'] = 'Could not save the record. Please try again.';
            }
        }
    }
}

$invalid = fn(string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
$fb      = fn(string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback">' . e($errors[$k]) . '</div>' : '';

require __DIR__ . '/header.php';
?>

<div class="page-header mb-4">
    <h1><?= e($pageTitle) ?></h1>
    <p>Fields marked * are required. After saving, use the <strong>Upload</strong> button on the list to attach the document to Amazon S3.</p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card"><div class="card-body">
<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="vehicle_id" class="form-label">Vehicle <span class="text-danger">*</span></label>
            <?php if ($isEdit): ?>
                <input type="text" class="form-control" disabled
                       value="<?= e($record['veh_reg'] . ' - ' . $record['make'] . ' ' . $record['model']) ?>">
                <div class="form-text">The vehicle cannot be changed on an existing record.</div>
            <?php else: ?>
                <select class="form-select<?= $invalid('vehicle_id') ?>" id="vehicle_id" name="vehicle_id" required>
                    <option value="">Select a vehicle</option>
                    <?php foreach ($vehicles as $veh): ?>
                        <option value="<?= (int)$veh['id'] ?>" data-reg="<?= e($veh['registration_number']) ?>"
                                <?= (string)$veh['id'] === ($old['vehicle_id'] ?? '') ? 'selected' : '' ?>>
                            <?= e($veh['registration_number'] . ' - ' . $veh['make'] . ' ' . $veh['model']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $fb('vehicle_id') ?>
            <?php endif; ?>
        </div>
        <div class="col-md-6"></div>

        <?php foreach ($cfg['fields'] as $name => $f):
            $val = e($old[$name] ?? '');
            $req = !empty($f['required']); ?>
            <div class="col-md-<?= (int)$f['col'] ?>">
                <label for="<?= e($name) ?>" class="form-label"><?= e($f['label']) ?><?= $req ? ' <span class="text-danger">*</span>' : '' ?></label>
                <?php if ($f['type'] === 'select'): ?>
                    <select class="form-select<?= $invalid($name) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>">
                        <?php foreach ($f['options'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $opt === ($old[$name] ?? '') ? 'selected' : '' ?>><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($f['type'] === 'textarea'): ?>
                    <textarea class="form-control<?= $invalid($name) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" rows="2" maxlength="2000"><?= $val ?></textarea>
                <?php elseif ($f['type'] === 'date'): ?>
                    <input type="date" class="form-control<?= $invalid($name) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= $val ?>">
                <?php elseif ($f['type'] === 'money'): ?>
                    <input type="number" class="form-control<?= $invalid($name) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" min="0" step="0.01" value="<?= $val ?>">
                <?php else: ?>
                    <input type="text" class="form-control<?= $invalid($name) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>"
                           maxlength="<?= (int)($f['max'] ?? 100) ?>" value="<?= $val ?>">
                <?php endif; ?>
                <?= $fb($name) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Update' : 'Save' ?></button>
        <a href="<?= url($base) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
</div></div>

<script>
(function () {
    var s = document.getElementById('vehicle_id'), r = document.getElementById('registration_number');
    if (!s || !r) { return; }
    s.addEventListener('change', function () {
        var o = s.options[s.selectedIndex];
        if (o && o.getAttribute('data-reg') && r.value.trim() === '') { r.value = o.getAttribute('data-reg'); }
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
