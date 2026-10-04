<?php
/**
 * AutoCare Cloud - helpers for services and maintenance reminders.
 */
require_once __DIR__ . '/vehicle_helpers.php';

function service_types(): array
{
    return ['General Service', 'Oil Change', 'Brake Service', 'Tire Replacement',
            'Battery Replacement', 'AC Service', 'Engine Repair', 'Electrical Repair', 'Other'];
}

/** True if $d is a real calendar date written as YYYY-MM-DD. */
function is_valid_date(string $d): bool
{
    $p = DateTime::createFromFormat('Y-m-d', $d);
    return $p !== false && $p->format('Y-m-d') === $d;
}

function service_defaults(): array
{
    return [
        'vehicle_id'            => '',
        'service_date'          => date('Y-m-d'),
        'odometer_reading'      => '',
        'service_type'          => 'General Service',
        'description'           => '',
        'service_center'        => '',
        'labor_cost'            => '0',
        'parts_cost'            => '0',
        'next_service_date'     => '',
        'next_service_odometer' => '',
        'notes'                 => '',
    ];
}

function read_service_post(): array
{
    $out = [];
    foreach (array_keys(service_defaults()) as $key) {
        $raw = $_POST[$key] ?? '';
        $out[$key] = is_string($raw) ? trim($raw) : '';
    }
    return $out;
}

function service_to_form(array $row): array
{
    $out = [];
    foreach (array_keys(service_defaults()) as $key) {
        $out[$key] = isset($row[$key]) ? (string)$row[$key] : '';
    }
    return $out;
}

/** Vehicles for dropdowns. */
function list_vehicles_for_select(mysqli $db): array
{
    $result = $db->query('SELECT id, registration_number, make, model, current_odometer
                            FROM vehicles ORDER BY registration_number');
    return $result->fetch_all(MYSQLI_ASSOC);
}

/** One service record plus its vehicle's name, or null. */
function find_service(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT s.*, v.registration_number, v.make, v.model
           FROM services s JOIN vehicles v ON v.id = s.vehicle_id
          WHERE s.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Validates the form. Returns [$errors, $data].
 * If $lockedVehicleId is given (edit page), that vehicle is used and not validated.
 */
function validate_service(array $in, mysqli $db, ?int $lockedVehicleId = null): array
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

    // --- Service date (required, not in the future) ---
    $dateOk = false;
    if ($in['service_date'] === '') {
        $errors['service_date'] = 'Service date is required.';
    } elseif (!is_valid_date($in['service_date'])) {
        $errors['service_date'] = 'Enter a valid date.';
    } elseif ($in['service_date'] > date('Y-m-d')) {
        $errors['service_date'] = 'Service date cannot be in the future.';
    } else {
        $dateOk = true;
    }
    $data['service_date'] = $in['service_date'];

    // --- Odometer reading (required) ---
    $odoOk = false;
    if (!preg_match('/^\d{1,7}$/', $in['odometer_reading'])) {
        $errors['odometer_reading'] = 'Enter the odometer as a whole number (0 to 9,999,999).';
        $data['odometer_reading']   = 0;
    } else {
        $odoOk = true;
        $data['odometer_reading'] = (int)$in['odometer_reading'];
    }

    // --- Service type ---
    if (!in_array($in['service_type'], service_types(), true)) {
        $errors['service_type'] = 'Choose a valid service type.';
    }
    $data['service_type'] = $in['service_type'];

    // --- Text fields ---
    if (mb_strlen($in['description']) > 2000) {
        $errors['description'] = 'Description must be 2000 characters or fewer.';
    }
    $data['description'] = ($in['description'] === '') ? null : $in['description'];

    if (mb_strlen($in['service_center']) > 100) {
        $errors['service_center'] = 'Service center must be 100 characters or fewer.';
    }
    $data['service_center'] = ($in['service_center'] === '') ? null : $in['service_center'];

    if (mb_strlen($in['notes']) > 2000) {
        $errors['notes'] = 'Notes must be 2000 characters or fewer.';
    }
    $data['notes'] = ($in['notes'] === '') ? null : $in['notes'];

    // --- Costs (total is calculated by MySQL, not here) ---
    foreach (['labor_cost' => 'Labor cost', 'parts_cost' => 'Parts cost'] as $field => $label) {
        $val = ($in[$field] === '') ? '0' : $in[$field];
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $val)) {
            $errors[$field] = $label . ' must be a valid amount, for example 1500 or 1500.50.';
            $data[$field]   = '0.00';
        } else {
            $data[$field] = number_format((float)$val, 2, '.', '');
        }
    }

    // --- Next service date (optional, not before the service date) ---
    $nd = $in['next_service_date'];
    if ($nd === '') {
        $data['next_service_date'] = null;
    } else {
        if (!is_valid_date($nd)) {
            $errors['next_service_date'] = 'Enter a valid date.';
        } elseif ($dateOk && $nd < $in['service_date']) {
            $errors['next_service_date'] = 'Next service date must be on or after the service date.';
        }
        $data['next_service_date'] = $nd;
    }

    // --- Next service odometer (optional, greater than this reading) ---
    $no = $in['next_service_odometer'];
    if ($no === '') {
        $data['next_service_odometer'] = null;
    } elseif (!preg_match('/^\d{1,7}$/', $no)) {
        $errors['next_service_odometer'] = 'Enter a whole number of kilometres.';
        $data['next_service_odometer']   = null;
    } else {
        if ($odoOk && (int)$no <= $data['odometer_reading']) {
            $errors['next_service_odometer'] = 'Next service odometer must be greater than the odometer reading.';
        }
        $data['next_service_odometer'] = (int)$no;
    }

    return [$errors, $data];
}

/**
 * Inserts a service record, in one transaction:
 *  1. saves the record
 *  2. raises the vehicle's odometer if this reading is higher
 *  3. marks older reminders of the same type (except "Other") as completed
 */
function insert_service(mysqli $db, array $d): int
{
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO services (vehicle_id, service_date, odometer_reading, service_type,
                                   description, service_center, labor_cost, parts_cost,
                                   next_service_date, next_service_odometer, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'isissssssis',
            $d['vehicle_id'], $d['service_date'], $d['odometer_reading'], $d['service_type'],
            $d['description'], $d['service_center'], $d['labor_cost'], $d['parts_cost'],
            $d['next_service_date'], $d['next_service_odometer'], $d['notes']
        );
        $stmt->execute();
        $newId = (int)$db->insert_id;
        $stmt->close();

        bump_vehicle_odometer($db, $d['vehicle_id'], $d['odometer_reading']);

        if ($d['service_type'] !== 'Other') {
            $stmt = $db->prepare(
                'UPDATE services SET reminder_done = 1
                  WHERE vehicle_id = ? AND service_type = ? AND id <> ?
                    AND reminder_done = 0 AND service_date <= ?'
            );
            $stmt->bind_param('isis', $d['vehicle_id'], $d['service_type'], $newId, $d['service_date']);
            $stmt->execute();
            $stmt->close();
        }

        $db->commit();
        return $newId;
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}

/** Updates a service record (the vehicle cannot be changed). */
function update_service(mysqli $db, int $id, array $d): void
{
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'UPDATE services
                SET vehicle_id = ?, service_date = ?, odometer_reading = ?, service_type = ?,
                    description = ?, service_center = ?, labor_cost = ?, parts_cost = ?,
                    next_service_date = ?, next_service_odometer = ?, notes = ?
              WHERE id = ?'
        );
        $stmt->bind_param(
            'isissssssisi',
            $d['vehicle_id'], $d['service_date'], $d['odometer_reading'], $d['service_type'],
            $d['description'], $d['service_center'], $d['labor_cost'], $d['parts_cost'],
            $d['next_service_date'], $d['next_service_odometer'], $d['notes'],
            $id
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

/** Raises the vehicle's current odometer if $reading is higher. Never lowers it. */
function bump_vehicle_odometer(mysqli $db, int $vehicleId, int $reading): void
{
    $stmt = $db->prepare('UPDATE vehicles SET current_odometer = GREATEST(current_odometer, ?) WHERE id = ?');
    $stmt->bind_param('ii', $reading, $vehicleId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Works out the reminder status of a service row.
 * The row must contain: next_service_date, next_service_odometer,
 * reminder_done and current_odometer (the vehicle's).
 * Returns null if the row has no reminder at all.
 */
function reminder_status(array $r, int $soonDays = 14, int $soonKm = 1000): ?array
{
    $hasDate = !empty($r['next_service_date']);
    $hasOdo  = isset($r['next_service_odometer']) && $r['next_service_odometer'] !== '';

    if (!$hasDate && !$hasOdo) {
        return null;
    }

    if (!empty($r['reminder_done'])) {
        return ['label' => 'Completed', 'class' => 'success', 'text' => 'Reminder completed', 'priority' => 0];
    }

    $level = 1;       // 1 Scheduled, 2 Due Soon, 3 Due Today, 4 Overdue
    $parts = [];

    if ($hasDate) {
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $r['next_service_date']);
        if ($due !== false) {
            $days = (int)(new DateTimeImmutable('today'))->diff($due)->format('%r%a');
            if ($days < 0) {
                $lv = 4; $parts[] = 'Overdue by ' . abs($days) . ' day(s)';
            } elseif ($days === 0) {
                $lv = 3; $parts[] = 'Due today';
            } elseif ($days <= $soonDays) {
                $lv = 2; $parts[] = 'Due in ' . $days . ' day(s)';
            } else {
                $lv = 1; $parts[] = 'Due in ' . $days . ' day(s)';
            }
            $level = max($level, $lv);
        }
    }

    if ($hasOdo) {
        $remaining = (int)$r['next_service_odometer'] - (int)$r['current_odometer'];
        if ($remaining < 0) {
            $lv = 4; $parts[] = 'Overdue by ' . format_number(abs($remaining)) . ' km';
        } elseif ($remaining === 0) {
            $lv = 3; $parts[] = 'Due now (odometer reached)';
        } elseif ($remaining <= $soonKm) {
            $lv = 2; $parts[] = 'Due in ' . format_number($remaining) . ' km';
        } else {
            $lv = 1; $parts[] = 'Due in ' . format_number($remaining) . ' km';
        }
        $level = max($level, $lv);
    }

    $map = [
        4 => ['Overdue',   'danger'],
        3 => ['Due Today', 'warning'],
        2 => ['Due Soon',  'info'],
        1 => ['Scheduled', 'secondary'],
    ];

    return ['label' => $map[$level][0], 'class' => $map[$level][1],
            'text' => implode(' / ', $parts), 'priority' => $level];
}

/**
 * All active (not completed) reminders, most urgent first.
 * Optional: only for one vehicle. Reused by the dashboard in Phase 14.
 */
function fetch_reminders(mysqli $db, ?int $vehicleId = null): array
{
    $sql = 'SELECT s.id, s.vehicle_id, s.service_type, s.service_date, s.next_service_date,
                   s.next_service_odometer, s.reminder_done,
                   v.registration_number, v.make, v.model, v.current_odometer
              FROM services s JOIN vehicles v ON v.id = s.vehicle_id
             WHERE s.reminder_done = 0
               AND (s.next_service_date IS NOT NULL OR s.next_service_odometer IS NOT NULL)';
    if ($vehicleId !== null) {
        $sql .= ' AND s.vehicle_id = ?';
    }
    $sql .= ' ORDER BY s.next_service_date IS NULL, s.next_service_date ASC, s.id DESC';

    $stmt = $db->prepare($sql);
    if ($vehicleId !== null) {
        $stmt->bind_param('i', $vehicleId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($rows as $row) {
        $status = reminder_status($row);
        if ($status !== null) {
            $row['status'] = $status;
            $out[] = $row;
        }
    }

    // Most urgent first (Overdue, Due Today, Due Soon, Scheduled).
    usort($out, fn($a, $b) => $b['status']['priority'] <=> $a['status']['priority']);
    return $out;
}
