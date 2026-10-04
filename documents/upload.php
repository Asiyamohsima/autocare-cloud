<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document_helpers.php';

$pageTitle  = 'Upload Document';
$activePage = 'documents';
$db = require_db('documents');

$vehicles = list_vehicles_for_select($db);
if (!$vehicles) {
    set_flash('warning', 'Add a vehicle first, then you can upload documents for it.');
    redirect('/vehicles/add.php');
}

$old    = document_defaults();
$errors = [];

// Pre-select values when arriving from another page: upload.php?vehicle_id=3&type=Insurance
$preVehicle = valid_id($_GET['vehicle_id'] ?? null);
if ($preVehicle !== null) {
    $old['vehicle_id'] = (string)$preVehicle;
}
if (isset($_GET['type']) && is_string($_GET['type']) && in_array($_GET['type'], document_types(), true)) {
    $old['document_type'] = $_GET['type'];
}

// If the whole request is bigger than PHP's post_max_size, PHP throws away $_POST and $_FILES completely.
$postTooLarge = ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
                 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($postTooLarge) {
        $errors['file'] = 'The file is too large. The maximum size is 5 MB.';
    } else {
        $old = read_document_post();

        if (!verify_csrf()) {
            $errors['form'] = 'Your session expired. Please try again.';
        } else {
            [$errors, $form]          = validate_document_form($old, $db);
            [$fileError, $fileInfo]   = validate_uploaded_file($_FILES['file'] ?? null);
            if ($fileError !== null) {
                $errors['file'] = $fileError;
            }

            if (!$errors) {
                try {
                    store_document($db, $form, $fileInfo);
                    set_flash('success', 'Document uploaded successfully.');
                    redirect('/documents/?vehicle_id=' . (int)$form['vehicle']['id']);
                } catch (S3OperationException $ex) {
                    $errors['form'] = $ex->getMessage();            // friendly text, safe to show
                } catch (Throwable $ex) {
                    error_log('[AutoCare] Document upload failed: ' . $ex->getMessage());
                    $errors['form'] = 'The document could not be saved. Please try again.';
                }
            }
        }
    }
}

$v       = fn(string $key): string => e($old[$key] ?? '');
$invalid = fn(string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$fb      = fn(string $key): string => isset($errors[$key])
              ? '<div class="invalid-feedback">' . e($errors[$key]) . '</div>' : '';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Upload Document</h1>
    <p>Files are stored privately in Amazon S3. Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX, up to 5 MB.</p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.
        <?php if (isset($errors['file']) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>Your file was not kept, so please select it again.<?php endif; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" id="uploadForm" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="vehicle_id" class="form-label">Vehicle <span class="text-danger">*</span></label>
                    <select class="form-select<?= $invalid('vehicle_id') ?>" id="vehicle_id" name="vehicle_id" required>
                        <option value="">Select a vehicle</option>
                        <?php foreach ($vehicles as $veh): ?>
                            <option value="<?= (int)$veh['id'] ?>" <?= (string)$veh['id'] === ($old['vehicle_id'] ?? '') ? 'selected' : '' ?>>
                                <?= e($veh['registration_number'] . ' - ' . $veh['make'] . ' ' . $veh['model']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $fb('vehicle_id') ?>
                </div>

                <div class="col-md-6">
                    <label for="document_type" class="form-label">Document Type <span class="text-danger">*</span></label>
                    <select class="form-select<?= $invalid('document_type') ?>" id="document_type" name="document_type">
                        <?php foreach (document_types() as $t): ?>
                            <option value="<?= e($t) ?>" <?= $t === ($old['document_type'] ?? '') ? 'selected' : '' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $fb('document_type') ?>
                </div>

                <div class="col-md-8">
                    <label for="document_name" class="form-label">Document Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= $invalid('document_name') ?>" id="document_name"
                           name="document_name" maxlength="150" placeholder="e.g. Insurance Policy 2026"
                           value="<?= $v('document_name') ?>" required>
                    <?= $fb('document_name') ?>
                </div>

                <div class="col-md-4">
                    <label for="expiry_date" class="form-label">Expiry Date</label>
                    <input type="date" class="form-control<?= $invalid('expiry_date') ?>" id="expiry_date"
                           name="expiry_date" value="<?= $v('expiry_date') ?>">
                    <div class="form-text">Leave empty if the document does not expire.</div>
                    <?= $fb('expiry_date') ?>
                </div>

                <div class="col-12">
                    <label for="file" class="form-label">File <span class="text-danger">*</span></label>
                    <input type="file" class="form-control<?= $invalid('file') ?>" id="file" name="file"
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                    <?= $fb('file') ?>
                    <div class="text-danger small mt-1" id="fileWarning"></div>
                    <div class="form-text">PDF, JPG, JPEG, PNG, DOC or DOCX. Maximum 5 MB.</div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" id="uploadBtn"><i class="bi bi-cloud-upload me-1"></i>Upload</button>
                <a href="<?= url('/documents/') ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var MAX   = 5 * 1024 * 1024;
    var input = document.getElementById('file');
    var warn  = document.getElementById('fileWarning');
    var name  = document.getElementById('document_name');
    var form  = document.getElementById('uploadForm');
    var btn   = document.getElementById('uploadBtn');
    var label = btn.innerHTML;

    // Convenience only. The server checks everything again.
    input.addEventListener('change', function () {
        warn.textContent = '';
        var f = input.files[0];
        if (!f) { return; }
        if (f.size > MAX) {
            warn.textContent = 'This file is larger than 5 MB. Please choose a smaller file.';
            input.value = '';
            return;
        }
        if (name.value.trim() === '') {
            name.value = f.name.replace(/\.[^.]+$/, '');
        }
    });

    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading...';
    });

    window.addEventListener('pageshow', function () {
        btn.disabled = false;
        btn.innerHTML = label;
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
