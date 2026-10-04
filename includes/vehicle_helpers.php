<?php
/**
 * AutoCare Cloud - helpers for the vehicle pages:
 * allowed values, input reading, validation, and database functions.
 */

function vehicle_types(): array
{
    return ['Car', 'Bike', 'Scooter', 'SUV', 'Other'];
}

function fuel_types(): array
{
    return ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'CNG'];
}

/** "kl-01 ab 1234" -> "KL01AB1234" */
function normalize_registration(string $reg): string
{
    return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $reg));
}

/** Default (empty) values for the add form. */
function vehicle_defaults(): array
{
    return [
        'registration_number' => '',
        'make'                => '',
        'model'               => '',
        'variant'             => '',
        'vehicle_type'        => 'Car',
        'manufacturing_year'  => '',
        'purchase_date'       => '',
        'purchase_price'      => '',
        'current_odometer'    => '0',
        'fuel_type'           => 'Petrol',
        'color'               => '',
        'notes'               => '',
    ];
}

/** Reads the submitted form into a clean array of trimmed strings. */
function read_vehicle_post(): array
{
    $out = [];
    foreach (array_keys(vehicle_defaults()) as $key) {
        $raw = $_POST[$key] ?? '';
        $out[$key] = is_string($raw) ? trim($raw) : '';
    }
    return $out;
}

/** Turns a database row into form-friendly strings. */
function vehicle_to_form(array $row): array
{
    $out = [];
    foreach (array_keys(vehicle_defaults()) as $key) {
        $out[$key] = isset($row[$key]) ? (string)$row[$key] : '';
    }
    return $out;
}

/** Finds one vehicle by ID, or returns null. */
function find_vehicle(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM vehicles WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Validates the submitted values.
 * Returns [$errors, $data]:
 *   $errors - field name => message (empty if everything is valid)
 *   $data   - cleaned values ready for the database (empty optional fields become NULL)
 */
function validate_vehicle(array $in, mysqli $db, int $excludeId = 0): array
{
    $errors = [];
    $data   = [];

    // --- Registration number (required, unique) ---
    $reg = normalize_registration($in['registration_number']);
    if ($reg === '') {
        $errors['registration_number'] = 'Registration number is required.';
    } elseif (!preg_match('/^[A-Z0-9]{4,15}$/', $reg)) {
        $errors['registration_number'] = 'Use 4 to 15 letters and numbers, for example KL01AB1234.';
    } else {
        $stmt = $db->prepare('SELECT id FROM vehicles WHERE registration_number = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $reg, $excludeId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors['registration_number'] = 'A vehicle with this registration number already exists.';
        }
        $stmt->close();
    }
    $data['registration_number'] = $reg;

    // --- Text fields: label, max length, required? ---
    $textRules = [
        'make'    => ['Make',    50, true],
        'model'   => ['Model',   50, true],
        'variant' => ['Variant', 50, false],
        'color'   => ['Color',   30, false],
    ];
    foreach ($textRules as $field => [$label, $max, $required]) {
        $value = $in[$field];
        if ($value === '' && $required) {
            $errors[$field] = $label . ' is required.';
        } elseif (mb_strlen($value) > $max) {
            $errors[$field] = $label . ' must be ' . $max . ' characters or fewer.';
        }
        $data[$field] = ($value === '') ? null : $value;
    }

    // --- Vehicle type and fuel type (must be from the allowed lists) ---
    if (!in_array($in['vehicle_type'], vehicle_types(), true)) {
        $errors['vehicle_type'] = 'Choose a valid vehicle type.';
    }
    $data['vehicle_type'] = $in['vehicle_type'];

    if (!in_array($in['fuel_type'], fuel_types(), true)) {
        $errors['fuel_type'] = 'Choose a valid fuel type.';
    }
    $data['fuel_type'] = $in['fuel_type'];

    // --- Manufacturing year (optional) ---
    $maxYear = (int)date('Y') + 1;
    $year    = $in['manufacturing_year'];
    if ($year === '') {
        $data['manufacturing_year'] = null;
    } elseif (!preg_match('/^\d{4}$/', $year) || (int)$year < 1950 || (int)$year > $maxYear) {
        $errors['manufacturing_year'] = 'Enter a year between 1950 and ' . $maxYear . '.';
        $data['manufacturing_year'] = null;
    } else {
        $data['manufacturing_year'] = (int)$year;
    }

    // --- Purchase date (optional, not in the future) ---
    $pd = $in['purchase_date'];
    if ($pd === '') {
        $data['purchase_date'] = null;
    } else {
        $parsed = DateTime::createFromFormat('Y-m-d', $pd);
        if (!$parsed || $parsed->format('Y-m-d') !== $pd) {
            $errors['purchase_date'] = 'Enter a valid date.';
        } elseif ($pd > date('Y-m-d')) {
            $errors['purchase_date'] = 'Purchase date cannot be in the future.';
        }
        $data['purchase_date'] = $pd;
    }

    // --- Purchase price (optional, up to 2 decimals) ---
    $price = $in['purchase_price'];
    if ($price === '') {
        $data['purchase_price'] = null;
    } elseif (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $price)) {
        $errors['purchase_price'] = 'Enter a valid amount, for example 750000 or 750000.50.';
        $data['purchase_price'] = null;
    } else {
        $data['purchase_price'] = number_format((float)$price, 2, '.', '');
    }

    // --- Current odometer (required whole number) ---
    $odo = $in['current_odometer'];
    if (!preg_match('/^\d{1,7}$/', $odo)) {
        $errors['current_odometer'] = 'Enter the odometer as a whole number (0 to 9,999,999).';
        $data['current_odometer'] = 0;
    } else {
        $data['current_odometer'] = (int)$odo;
    }

    // --- Notes (optional) ---
    if (mb_strlen($in['notes']) > 2000) {
        $errors['notes'] = 'Notes must be 2000 characters or fewer.';
    }
    $data['notes'] = ($in['notes'] === '') ? null : $in['notes'];

    return [$errors, $data];
}

/** Inserts a vehicle and returns the new ID. */
function insert_vehicle(mysqli $db, array $d): int
{
    $stmt = $db->prepare(
        'INSERT INTO vehicles (registration_number, make, model, variant, vehicle_type,
                               manufacturing_year, purchase_date, purchase_price,
                               current_odometer, fuel_type, color, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sssssississs',
        $d['registration_number'], $d['make'], $d['model'], $d['variant'], $d['vehicle_type'],
        $d['manufacturing_year'], $d['purchase_date'], $d['purchase_price'],
        $d['current_odometer'], $d['fuel_type'], $d['color'], $d['notes']
    );
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

/** Updates a vehicle. */
function update_vehicle(mysqli $db, int $id, array $d): void
{
    $stmt = $db->prepare(
        'UPDATE vehicles
            SET registration_number = ?, make = ?, model = ?, variant = ?, vehicle_type = ?,
                manufacturing_year = ?, purchase_date = ?, purchase_price = ?,
                current_odometer = ?, fuel_type = ?, color = ?, notes = ?
          WHERE id = ?'
    );
    $stmt->bind_param(
        'sssssississsi',
        $d['registration_number'], $d['make'], $d['model'], $d['variant'], $d['vehicle_type'],
        $d['manufacturing_year'], $d['purchase_date'], $d['purchase_price'],
        $d['current_odometer'], $d['fuel_type'], $d['color'], $d['notes'],
        $id
    );
    $stmt->execute();
    $stmt->close();
}

/** Summary numbers for one vehicle's detail page. */
function vehicle_stats(mysqli $db, int $id): array
{
    $stmt = $db->prepare(
        'SELECT
           (SELECT COUNT(*) FROM services WHERE vehicle_id = ?)                   AS service_count,
           (SELECT COALESCE(SUM(total_cost), 0) FROM services WHERE vehicle_id = ?) AS service_cost,
           (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE vehicle_id = ?)     AS expense_total,
           (SELECT COALESCE(SUM(total_cost), 0) FROM fuel_logs WHERE vehicle_id = ?) AS fuel_cost,
           (SELECT COUNT(*) FROM documents WHERE vehicle_id = ?)                  AS document_count'
    );
    $stmt->bind_param('iiiii', $id, $id, $id, $id, $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}
