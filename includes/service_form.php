<?php
/**
 * The service form (used by add.php and edit.php).
 * Expects: $old, $errors, $vehicles (for the dropdown), $lockedVehicle (array or null),
 *          $submitLabel, $cancelUrl.
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
                        <option value="<?= (int)$veh['id'] ?>"
                                data-odo="<?= (int)$veh['current_odometer'] ?>"
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
            <label for="service_date" class="form-label">Service Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control<?= $invalid('service_date') ?>" id="service_date"
                   name="service_date" max="<?= e(date('Y-m-d')) ?>" value="<?= $v('service_date') ?>" required>
            <?= $fb('service_date') ?>
        </div>

        <div class="col-md-3">
            <label for="odometer_reading" class="form-label">Odometer (km) <span class="text-danger">*</span></label>
            <input type="number" class="form-control<?= $invalid('odometer_reading') ?>" id="odometer_reading"
                   name="odometer_reading" min="0" max="9999999" value="<?= $v('odometer_reading') ?>" required>
            <?= $fb('odometer_reading') ?>
        </div>

        <div class="col-md-4">
            <label for="service_type" class="form-label">Service Type <span class="text-danger">*</span></label>
            <select class="form-select<?= $invalid('service_type') ?>" id="service_type" name="service_type">
                <?php foreach (service_types() as $type): ?>
                    <option value="<?= e($type) ?>" <?= $type === ($old['service_type'] ?? '') ? 'selected' : '' ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $fb('service_type') ?>
        </div>

        <div class="col-md-8">
            <label for="service_center" class="form-label">Service Center</label>
            <input type="text" class="form-control<?= $invalid('service_center') ?>" id="service_center"
                   name="service_center" maxlength="100" placeholder="Sample Motors Service Center"
                   value="<?= $v('service_center') ?>">
            <?= $fb('service_center') ?>
        </div>

        <div class="col-md-4">
            <label for="labor_cost" class="form-label">Labor Cost (&#8377;)</label>
            <input type="number" class="form-control<?= $invalid('labor_cost') ?>" id="labor_cost"
                   name="labor_cost" min="0" step="0.01" value="<?= $v('labor_cost') ?>">
            <?= $fb('labor_cost') ?>
        </div>

        <div class="col-md-4">
            <label for="parts_cost" class="form-label">Parts Cost (&#8377;)</label>
            <input type="number" class="form-control<?= $invalid('parts_cost') ?>" id="parts_cost"
                   name="parts_cost" min="0" step="0.01" value="<?= $v('parts_cost') ?>">
            <?= $fb('parts_cost') ?>
        </div>

        <div class="col-md-4">
            <label class="form-label">Total Cost (calculated)</label>
            <div class="form-control bg-light fw-semibold" id="totalCostDisplay">&#8377;0.00</div>
            <div class="form-text">Labor + Parts. Calculated automatically.</div>
        </div>

        <div class="col-12">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control<?= $invalid('description') ?>" id="description" name="description"
                      rows="2" maxlength="2000" placeholder="What was done?"><?= $v('description') ?></textarea>
            <?= $fb('description') ?>
        </div>

        <div class="col-12"><hr class="my-1"><h6 class="mt-2 mb-0">Next service reminder <span class="text-muted fw-normal">(optional)</span></h6></div>

        <div class="col-md-6">
            <label for="next_service_date" class="form-label">Next Service Date</label>
            <input type="date" class="form-control<?= $invalid('next_service_date') ?>" id="next_service_date"
                   name="next_service_date" value="<?= $v('next_service_date') ?>">
            <?= $fb('next_service_date') ?>
        </div>

        <div class="col-md-6">
            <label for="next_service_odometer" class="form-label">Next Service Odometer (km)</label>
            <input type="number" class="form-control<?= $invalid('next_service_odometer') ?>" id="next_service_odometer"
                   name="next_service_odometer" min="0" max="9999999" value="<?= $v('next_service_odometer') ?>">
            <div class="form-text">Set a date, an odometer, or both. The more urgent one is shown.</div>
            <?= $fb('next_service_odometer') ?>
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
    var labor = document.getElementById('labor_cost');
    var parts = document.getElementById('parts_cost');
    var total = document.getElementById('totalCostDisplay');

    function updateTotal() {
        var sum = (parseFloat(labor.value) || 0) + (parseFloat(parts.value) || 0);
        total.textContent = '\u20B9' + sum.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    labor.addEventListener('input', updateTotal);
    parts.addEventListener('input', updateTotal);
    updateTotal();

    // Show the vehicle's current odometer, and pre-fill the field if it is empty.
    var select = document.getElementById('vehicle_id');
    var hint   = document.getElementById('odoHint');
    var odo    = document.getElementById('odometer_reading');

    function updateHint(prefill) {
        if (!select || !hint) { return; }
        var option = select.options[select.selectedIndex];
        if (option && option.value !== '') {
            var current = parseInt(option.getAttribute('data-odo'), 10) || 0;
            hint.textContent = 'Current odometer: ' + current.toLocaleString('en-IN') + ' km';
            if (prefill && odo.value === '') { odo.value = current; }
        } else {
            hint.textContent = '';
        }
    }
    if (select) {
        select.addEventListener('change', function () { updateHint(true); });
        updateHint(true);
    }
})();
</script>
