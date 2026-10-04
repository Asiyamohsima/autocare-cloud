<?php
/**
 * The fuel form (used by add.php and edit.php).
 * Expects: $old, $errors, $vehicles, $lockedVehicle (array or null), $submitLabel, $cancelUrl.
 */
$old           = $old           ?? [];
$errors        = $errors        ?? [];
$vehicles      = $vehicles      ?? [];
$lockedVehicle = $lockedVehicle ?? null;

$v       = fn(string $key): string => e($old[$key] ?? '');
$invalid = fn(string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$fb      = fn(string $key): string => isset($errors[$key])
              ? '<div class="invalid-feedback">' . e($errors[$key]) . '</div>' : '';
?>
<form method="post" novalidate>
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-md-6">
            <label for="vehicle_id" class="form-label">Vehicle <span class="text-danger">*</span></label>
            <?php if ($lockedVehicle): ?>
                <input type="text" class="form-control" disabled
                       value="<?= e($lockedVehicle['registration_number'] . ' - ' . $lockedVehicle['make'] . ' ' . $lockedVehicle['model']) ?>">
                <div class="form-text">The vehicle cannot be changed on an existing record.</div>
            <?php else: ?>
                <select class="form-select<?= $invalid('vehicle_id') ?>" id="vehicle_id" name="vehicle_id" required>
                    <option value="">Select a vehicle</option>
                    <?php foreach ($vehicles as $veh): ?>
                        <option value="<?= (int)$veh['id'] ?>" data-odo="<?= (int)$veh['current_odometer'] ?>"
                                <?= (string)$veh['id'] === ($old['vehicle_id'] ?? '') ? 'selected' : '' ?>>
                            <?= e($veh['registration_number'] . ' - ' . $veh['make'] . ' ' . $veh['model']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="odoHint"></div>
                <?= $fb('vehicle_id') ?>
            <?php endif; ?>
        </div>

        <div class="col-md-3">
            <label for="fuel_date" class="form-label">Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control<?= $invalid('fuel_date') ?>" id="fuel_date" name="fuel_date"
                   max="<?= e(date('Y-m-d')) ?>" value="<?= $v('fuel_date') ?>" required>
            <?= $fb('fuel_date') ?>
        </div>

        <div class="col-md-3">
            <label for="odometer_reading" class="form-label">Odometer (km) <span class="text-danger">*</span></label>
            <input type="number" class="form-control<?= $invalid('odometer_reading') ?>" id="odometer_reading"
                   name="odometer_reading" min="0" max="9999999" value="<?= $v('odometer_reading') ?>" required>
            <?= $fb('odometer_reading') ?>
        </div>

        <div class="col-md-4">
            <label for="fuel_quantity" class="form-label">Fuel Quantity <span class="text-danger">*</span></label>
            <input type="number" class="form-control<?= $invalid('fuel_quantity') ?>" id="fuel_quantity"
                   name="fuel_quantity" min="0.01" step="0.01" placeholder="25.50" value="<?= $v('fuel_quantity') ?>" required>
            <div class="form-text">Liters (kWh for electric, kg for CNG).</div>
            <?= $fb('fuel_quantity') ?>
        </div>

        <div class="col-md-4">
            <label for="price_per_liter" class="form-label">Price per Liter (&#8377;) <span class="text-danger">*</span></label>
            <input type="number" class="form-control<?= $invalid('price_per_liter') ?>" id="price_per_liter"
                   name="price_per_liter" min="0.01" step="0.01" placeholder="102.50" value="<?= $v('price_per_liter') ?>" required>
            <?= $fb('price_per_liter') ?>
        </div>

        <div class="col-md-4">
            <label class="form-label">Total Cost (calculated)</label>
            <div class="form-control bg-light fw-semibold" id="totalCostDisplay">&#8377;0.00</div>
            <div class="form-text">Quantity &times; price. Calculated automatically.</div>
        </div>

        <div class="col-md-8">
            <label for="fuel_station" class="form-label">Fuel Station</label>
            <input type="text" class="form-control<?= $invalid('fuel_station') ?>" id="fuel_station"
                   name="fuel_station" maxlength="100" placeholder="Sample Fuels, Main Road"
                   value="<?= $v('fuel_station') ?>">
            <?= $fb('fuel_station') ?>
        </div>

        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="is_full_tank" name="is_full_tank" value="1"
                       <?= ($old['is_full_tank'] ?? '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_full_tank">Filled the tank completely</label>
            </div>
        </div>
        <div class="col-12">
            <div class="form-text mt-0">Mileage is calculated between two full-tank fills. Turn this off for a partial fill.</div>
        </div>

        <div class="col-12">
            <label for="notes" class="form-label">Notes</label>
            <textarea class="form-control<?= $invalid('notes') ?>" id="notes" name="notes" rows="2"
                      maxlength="2000"><?= $v('notes') ?></textarea>
            <?= $fb('notes') ?>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= e($submitLabel) ?></button>
        <a href="<?= e($cancelUrl) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<script>
(function () {
    var qty   = document.getElementById('fuel_quantity');
    var price = document.getElementById('price_per_liter');
    var total = document.getElementById('totalCostDisplay');

    function updateTotal() {
        var sum = (parseFloat(qty.value) || 0) * (parseFloat(price.value) || 0);
        total.textContent = '\u20B9' + sum.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    qty.addEventListener('input', updateTotal);
    price.addEventListener('input', updateTotal);
    updateTotal();

    var select = document.getElementById('vehicle_id');
    var hint   = document.getElementById('odoHint');
    if (select && hint) {
        var show = function () {
            var option = select.options[select.selectedIndex];
            if (option && option.value !== '') {
                var current = parseInt(option.getAttribute('data-odo'), 10) || 0;
                hint.textContent = 'Current odometer: ' + current.toLocaleString('en-IN') + ' km';
            } else {
                hint.textContent = '';
            }
        };
        select.addEventListener('change', show);
        show();
    }
})();
</script>
