<?php
/**
 * The vehicle form (used by add.php and edit.php).
 * Expects: $old (field values), $errors (field => message),
 *          $submitLabel (button text), $cancelUrl (where Cancel goes).
 */
$old    = $old    ?? [];
$errors = $errors ?? [];

$v       = fn(string $key): string => e($old[$key] ?? '');
$invalid = fn(string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$fb      = fn(string $key): string => isset($errors[$key])
              ? '<div class="invalid-feedback">' . e($errors[$key]) . '</div>' : '';
$options = function (array $list, string $selected): string {
    $html = '';
    foreach ($list as $item) {
        $html .= '<option value="' . e($item) . '"' . ($item === $selected ? ' selected' : '') . '>'
               . e($item) . '</option>';
    }
    return $html;
};
?>
<form method="post" novalidate>
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-md-6">
            <label for="registration_number" class="form-label">Registration Number <span class="text-danger">*</span></label>
            <input type="text" class="form-control<?= $invalid('registration_number') ?>" id="registration_number"
                   name="registration_number" maxlength="20" placeholder="KL01AB1234"
                   value="<?= $v('registration_number') ?>" required>
            <div class="form-text">Letters and numbers only. Spaces and hyphens are removed automatically.</div>
            <?= $fb('registration_number') ?>
        </div>

        <div class="col-md-3">
            <label for="vehicle_type" class="form-label">Vehicle Type <span class="text-danger">*</span></label>
            <select class="form-select<?= $invalid('vehicle_type') ?>" id="vehicle_type" name="vehicle_type">
                <?= $options(vehicle_types(), $old['vehicle_type'] ?? 'Car') ?>
            </select>
            <?= $fb('vehicle_type') ?>
        </div>

        <div class="col-md-3">
            <label for="fuel_type" class="form-label">Fuel Type <span class="text-danger">*</span></label>
            <select class="form-select<?= $invalid('fuel_type') ?>" id="fuel_type" name="fuel_type">
                <?= $options(fuel_types(), $old['fuel_type'] ?? 'Petrol') ?>
            </select>
            <?= $fb('fuel_type') ?>
        </div>

        <div class="col-md-4">
            <label for="make" class="form-label">Make <span class="text-danger">*</span></label>
            <input type="text" class="form-control<?= $invalid('make') ?>" id="make" name="make"
                   maxlength="50" placeholder="Toyota" value="<?= $v('make') ?>" required>
            <?= $fb('make') ?>
        </div>

        <div class="col-md-4">
            <label for="model" class="form-label">Model <span class="text-danger">*</span></label>
            <input type="text" class="form-control<?= $invalid('model') ?>" id="model" name="model"
                   maxlength="50" placeholder="Glanza" value="<?= $v('model') ?>" required>
            <?= $fb('model') ?>
        </div>

        <div class="col-md-4">
            <label for="variant" class="form-label">Variant</label>
            <input type="text" class="form-control<?= $invalid('variant') ?>" id="variant" name="variant"
                   maxlength="50" placeholder="G" value="<?= $v('variant') ?>">
            <?= $fb('variant') ?>
        </div>

        <div class="col-md-3">
            <label for="manufacturing_year" class="form-label">Manufacturing Year</label>
            <input type="number" class="form-control<?= $invalid('manufacturing_year') ?>" id="manufacturing_year"
                   name="manufacturing_year" min="1950" max="<?= (int)date('Y') + 1 ?>" placeholder="2024"
                   value="<?= $v('manufacturing_year') ?>">
            <?= $fb('manufacturing_year') ?>
        </div>

        <div class="col-md-3">
            <label for="purchase_date" class="form-label">Purchase Date</label>
            <input type="date" class="form-control<?= $invalid('purchase_date') ?>" id="purchase_date"
                   name="purchase_date" max="<?= e(date('Y-m-d')) ?>" value="<?= $v('purchase_date') ?>">
            <?= $fb('purchase_date') ?>
        </div>

        <div class="col-md-3">
            <label for="purchase_price" class="form-label">Purchase Price (&#8377;)</label>
            <input type="number" class="form-control<?= $invalid('purchase_price') ?>" id="purchase_price"
                   name="purchase_price" min="0" step="0.01" placeholder="750000"
                   value="<?= $v('purchase_price') ?>">
            <?= $fb('purchase_price') ?>
        </div>

        <div class="col-md-3">
            <label for="current_odometer" class="form-label">Current Odometer (km) <span class="text-danger">*</span></label>
            <input type="number" class="form-control<?= $invalid('current_odometer') ?>" id="current_odometer"
                   name="current_odometer" min="0" max="9999999" value="<?= $v('current_odometer') ?>" required>
            <?= $fb('current_odometer') ?>
        </div>

        <div class="col-md-4">
            <label for="color" class="form-label">Color</label>
            <input type="text" class="form-control<?= $invalid('color') ?>" id="color" name="color"
                   maxlength="30" placeholder="White" value="<?= $v('color') ?>">
            <?= $fb('color') ?>
        </div>

        <div class="col-12">
            <label for="notes" class="form-label">Notes</label>
            <textarea class="form-control<?= $invalid('notes') ?>" id="notes" name="notes" rows="3"
                      maxlength="2000"><?= $v('notes') ?></textarea>
            <?= $fb('notes') ?>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= e($submitLabel) ?></button>
        <a href="<?= e($cancelUrl) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form
