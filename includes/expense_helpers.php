<?php
/**
 * AutoCare Cloud - helpers for the expense pages.
 */
require_once __DIR__ . '/service_helpers.php';   // also loads vehicle_helpers.php

function expense_categories(): array
{
    return ['Maintenance', 'Fuel', 'Insurance', 'Repairs', 'Parking', 'Toll',
            'Accessories', 'Cleaning', 'Other'];
}

function payment_methods(): array
{
    return ['Cash', 'Card', 'UPI', 'Other'];
}

function expense_defaults(): array
{
    return [
        'vehicle_id'     => '',
        'expense_date'   => date('Y-m-d'),
        'category'       => 'Other',
        'amount'         => '',
        'description'    => '',
        'payment_method' => 'Cash',
        'notes'          => '',
    ];
}

function read_expense_post(): array
{
    $out = [];
    foreach (array_keys(expense_defaults()) as $key) {
        $raw = $_POST[$key] ?? '';
        $out[$key] = is_string($raw) ? trim($raw) : '';
    }
    return $out;
}

function expense_to_form(array $row): array
{
    $out = [];
    foreach (array_keys(expense_defaults()) as $key) {
        $out[$key] = isset($row[$key]) ? (string)$row[$key] : '';
    }
    return $out;
}

function find_expense(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT e.*, v.registration_number, v.make, v.model
           FROM expenses e JOIN vehicles v ON v.id = e.vehicle_id
          WHERE e.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Validates the form. Returns [$errors, $data]. */
function validate_expense(array $in, mysqli $db): array
{
    $errors = [];
    $data   = [];

    // --- Vehicle ---
    $vid = valid_id($in['vehicle_id']);
    if ($vid === null) {
        $errors['vehicle_id'] = 'Please select a vehicle.';
        $data['vehicle_id']   = 0;
    } elseif (find_vehicle($db, $vid) === null) {
        $errors['vehicle_id'] = 'The selected vehicle does not exist.';
        $data['vehicle_id']   = 0;
    } else {
        $data['vehicle_id'] = $vid;
    }

    // --- Date (required, not in the future) ---
    if ($in['expense_date'] === '') {
        $errors['expense_date'] = 'Expense date is required.';
    } elseif (!is_valid_date($in['expense_date'])) {
        $errors['expense_date'] = 'Enter a valid date.';
    } elseif ($in['expense_date'] > date('Y-m-d')) {
        $errors['expense_date'] = 'Expense date cannot be in the future.';
    }
    $data['expense_date'] = $in['expense_date'];

    // --- Category and payment method (must be from the allowed lists) ---
    if (!in_array($in['category'], expense_categories(), true)) {
        $errors['category'] = 'Choose a valid category.';
    }
    $data['category'] = $in['category'];

    if (!in_array($in['payment_method'], payment_methods(), true)) {
        $errors['payment_method'] = 'Choose a valid payment method.';
    }
    $data['payment_method'] = $in['payment_method'];

    // --- Amount (required, greater than 0, up to 2 decimals) ---
    $amt = $in['amount'];
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amt) || (float)$amt <= 0) {
        $errors['amount'] = 'Enter an amount greater than 0, for example 250 or 250.50.';
        $data['amount']   = '0.00';
    } else {
        $data['amount'] = number_format((float)$amt, 2, '.', '');
    }

    // --- Text fields ---
    if (mb_strlen($in['description']) > 255) {
        $errors['description'] = 'Description must be 255 characters or fewer.';
    }
    $data['description'] = ($in['description'] === '') ? null : $in['description'];

    if (mb_strlen($in['notes']) > 2000) {
        $errors['notes'] = 'Notes must be 2000 characters or fewer.';
    }
    $data['notes'] = ($in['notes'] === '') ? null : $in['notes'];

    return [$errors, $data];
}

function insert_expense(mysqli $db, array $d): int
{
    $stmt = $db->prepare(
        'INSERT INTO expenses (vehicle_id, expense_date, category, amount, description, payment_method, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssss',
        $d['vehicle_id'], $d['expense_date'], $d['category'], $d['amount'],
        $d['description'], $d['payment_method'], $d['notes']
    );
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

function update_expense(mysqli $db, int $id, array $d): void
{
    $stmt = $db->prepare(
        'UPDATE expenses
            SET vehicle_id = ?, expense_date = ?, category = ?, amount = ?,
                description = ?, payment_method = ?, notes = ?
          WHERE id = ?'
    );
    $stmt->bind_param('issssssi',
        $d['vehicle_id'], $d['expense_date'], $d['category'], $d['amount'],
        $d['description'], $d['payment_method'], $d['notes'], $id
    );
    $stmt->execute();
    $stmt->close();
}
