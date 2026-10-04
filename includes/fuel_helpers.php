<?php
/**
 * AutoCare Cloud - helpers for fuel logs and mileage calculation.
 */
require_once __DIR__ . '/service_helpers.php';   // also loads vehicle_helpers.php

/** Unit of fuel for a vehicle's fuel type. */
function fuel_unit(string $fuelType): string
{
    if ($fuelType === 'Electric') { return 'kWh'; }
    if ($fuelType === 'CNG')      { return 'kg'; }
    return 'L';
}

function fuel_defaults(): array
{
    return [
        'vehicle_id'       => '',
        'fuel_date'        => date('Y-m-d'),
        'odometer_reading' => '',
        'fuel_quantity'    => '',
        'price_per_liter'  => '',
        'fuel_station'     => '',
        'is_full_tank'     => '1',
        'notes'            => '',
    ];
}

function read_fuel_post(): array
{
    $out = [];
    foreach (array_keys(fuel_defaults()) as $key) {
        if ($key === 'is_full_tank') {
            $out[$key] = isset($_POST['is_full_tank']) ? '1' : '0';   // checkbox
            continue;
        }
        $raw = $_POST[$key] ?? '';
        $out[$key] = is_string($raw) ? trim($raw) : '';
    }
    return $out;
}

function fuel_to_form(array $row): array
{
    $out = [];
    foreach (array_keys(fuel_defaults()) as $key) {
        $out[$key] = isset($row[$key]) ? (string)$row[$key] : '';
    }
    return $out;
}

function find_fuel_log(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT f.*, v.registration_number, v.make, v.model, v.fuel_type
           FROM fuel_logs f JOIN vehicles v ON v.id = f.vehicle_id
          WHERE f.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Validates the form. Returns [$errors, $data].
 * total_cost is NOT handled here, because MySQL calculates it (quantity x price).
 */
function validate_fuel(array $in, mysqli $db, ?int $lockedVehicleId = null): array
{
    $errors = [];
    $data   = [];

    // --- Vehicle ---
    if ($lockedVehicleId !== null) {
        $data['vehicle_id'] = $lockedVehicleId;
    } else {
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
    }

    // --- Date ---
    if ($in['fuel_date'] === '') {
        $errors['fuel_date'] = 'Fuel date is required.';
    } elseif (!is_valid_date($in['fuel_date'])) {
        $errors['fuel_date'] = 'Enter a valid date.';
    } elseif ($in['fuel_date'] > date('Y-m-d')) {
        $errors['fuel_date'] = 'Fuel date cannot be in the future.';
    }
    $data['fuel_date'] = $in['fuel_date'];

    // --- Odometer ---
    if (!preg_match('/^\d{1,7}$/', $in['odometer_reading'])) {
        $errors['odometer_reading'] = 'Enter the odometer as a whole number (0 to 9,999,999).';
        $data['odometer_reading']   = 0;
    } else {
        $data['odometer_reading'] = (int)$in['odometer_reading'];
    }

    // --- Quantity and price (greater than 0, up to 4 digits before the decimal point) ---
    foreach (['fuel_quantity' => 'Fuel quantity', 'price_per_liter' => 'Price per liter'] as $field => $label) {
        $val = $in[$field];
        if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $val) || (float)$val <= 0) {
            $errors[$field] = $label . ' must be greater than 0, with up to 2 decimals (for example 25.50).';
            $data[$field]   = '0.00';
        } else {
            $data[$field] = number_format((float)$val, 2, '.', '');
        }
    }

    // --- Text fields ---
    if (mb_strlen($in['fuel_station']) > 100) {
        $errors['fuel_station'] = 'Fuel station must be 100 characters or fewer.';
    }
    $data['fuel_station'] = ($in['fuel_station'] === '') ? null : $in['fuel_station'];

    if (mb_strlen($in['notes']) > 2000) {
        $errors['notes'] = 'Notes must be 2000 characters or fewer.';
    }
    $data['notes'] = ($in['notes'] === '') ? null : $in['notes'];

    $data['is_full_tank'] = ($in['is_full_tank'] === '1') ? 1 : 0;

    return [$errors, $data];
}

/** Inserts a fuel record and raises the vehicle odometer if this reading is higher. */
function insert_fuel(mysqli $db, array $d): int
{
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO fuel_logs (vehicle_id, fuel_date, odometer_reading, fuel_quantity,
                                    price_per_liter, fuel_station, is_full_tank, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisssis',
            $d['vehicle_id'], $d['fuel_date'], $d['odometer_reading'], $d['fuel_quantity'],
            $d['price_per_liter'], $d['fuel_station'], $d['is_full_tank'], $d['notes']
        );
        $stmt->execute();
        $newId = (int)$db->insert_id;
        $stmt->close();

        bump_vehicle_odometer($db, $d['vehicle_id'], $d['odometer_reading']);

        $db->commit();
        return $newId;
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}

function update_fuel(mysqli $db, int $id, array $d): void
{
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'UPDATE fuel_logs
                SET vehicle_id = ?, fuel_date = ?, odometer_reading = ?, fuel_quantity = ?,
                    price_per_liter = ?, fuel_station = ?, is_full_tank = ?, notes = ?
              WHERE id = ?'
        );
        $stmt->bind_param('isisssisi',
            $d['vehicle_id'], $d['fuel_date'], $d['odometer_reading'], $d['fuel_quantity'],
            $d['price_per_liter'], $d['fuel_station'], $d['is_full_tank'], $d['notes'], $id
        );
        $stmt->execute();
        $stmt->close();

        bump_vehicle_odometer($db, $d['vehicle_id'], $d['odometer_reading']);

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}

/**
 * Mileage ("full tank to full tank" method) for ONE vehicle.
 * $logs must be sorted by odometer_reading, then id, and contain:
 * id, odometer_reading, fuel_quantity, is_full_tank.
 *
 * - The first full-tank fill is only a starting point.
 * - For every later full-tank fill:
 *     distance = odometer now - odometer at the previous full-tank fill
 *     fuel     = all fuel added after that previous full fill, up to and including this one
 *     efficiency = distance / fuel
 *
 * Returns: segments (log id => [distance, fuel, eff]), distance, fuel (totals),
 *          avg (total distance / total fuel, or null if there is not enough data).
 */
function fuel_efficiency(array $logs): array
{
    $segments      = [];
    $totalDistance = 0;
    $totalFuel     = 0.0;
    $lastFullOdo   = null;
    $fuelSince     = 0.0;

    foreach ($logs as $log) {
        $odo = (int)$log['odometer_reading'];
        $qty = (float)$log['fuel_quantity'];

        if ($lastFullOdo !== null) {
            $fuelSince += $qty;
        }

        if ((int)$log['is_full_tank'] === 1) {
            if ($lastFullOdo !== null) {
                $distance = $odo - $lastFullOdo;
                if ($distance > 0 && $fuelSince > 0) {
                    $segments[(int)$log['id']] = [
                        'distance' => $distance,
                        'fuel'     => $fuelSince,
                        'eff'      => $distance / $fuelSince,
                    ];
                    $totalDistance += $distance;
                    $totalFuel     += $fuelSince;
                }
            }
            $lastFullOdo = $odo;
            $fuelSince   = 0.0;
        }
    }

    return [
        'segments' => $segments,
        'distance' => $totalDistance,
        'fuel'     => $totalFuel,
        'avg'      => $totalFuel > 0 ? $totalDistance / $totalFuel : null,
    ];
}
