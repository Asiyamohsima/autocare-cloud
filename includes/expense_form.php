<?php
/**
 * The expense form (used by add.php and edit.php).
 * Expects: $old, $errors, $vehicles, $submitLabel, $cancelUrl.
 */
$old      = $old      ?? [];
$errors   = $errors   ?? [];
$vehicles = $vehicles ?? [];

$v       = fn(string $key): string => e($old[$key] ?? '');
$invalid = fn(string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$fb      = fn(string $key): string => isset($errors[$key])
              ? '<div class="invalid-feedback">' . e($errors[$key]) . '</div>' : '';
?>
<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    Record <strong>fuel purchases</strong> in <em>Fuel</em> and <strong>service bills</strong> in <em>Services</em>.
    Entering the same cost here too would count it twice in your totals.
</div>

<form method="post" novalidate>
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
            <label for="expense_date" class="form-label">Expense Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control<?= $invalid('expense_date') ?>" id="expense_date"
                   name="expense_date" max="<?= e(date('Y-m-d')) ?>" value="<?= $v('expense_date') ?>" required>
            <?= $fb('expense_date') ?>
        </div>

        <div class="col-md-4">
            <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
            <select class="form-select<?= $invalid('category') ?>" id="category" name="category">
                <?php foreach (expense_categories() as $c): ?>
                    <option value="<?= e($c) ?>" <?= $c === ($old['category'] ?? '') ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $fb('category') ?>
        </div>

        <div class="col-md-4">
            <label for="amount" class="form-label">Amount (&#8377;) <span class="text-danger">*</span></label>
            <input type="number" class="form-control<?= $invalid('amount') ?>" id="amount" name="amount"
                   min="0.01" step="0.01" placeholder="250" value="<?= $v('amount') ?>" required>
            <?= $fb('amount') ?>
        </div>

        <div class="col-md-4">
            <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
            <select class="form-select<?= $invalid('payment_method') ?>" id="payment_method" name="payment_method">
                <?php foreach (payment_methods() as $m): ?>
                    <option value="<?= e($m) ?>" <?= $m === ($old['payment_method'] ?? '') ? 'selected' : '' ?>><?= e($m) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $fb('payment_method') ?>
        </div>

        <div class="col-12">
            <label for="description" class="form-label">Description</label>
            <input type="text" class="form-control<?= $invalid('description') ?>" id="description"
                   name="description" maxlength="255" placeholder="e.g. Mall parking"
                   value="<?= $v('description') ?>">
            <?= $fb('description') ?>
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
