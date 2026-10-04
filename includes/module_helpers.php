<?php
/**
 * AutoCare Cloud - shared helpers for Insurance, Warranty and Registration.
 * Each module is described by a config array; one set of pages serves all three.
 */
require_once __DIR__ . '/service_helpers.php';   // also loads vehicle_helpers.php

function module_config(string $key): ?array
{
    $notes = ['label' => 'Notes', 'type' => 'textarea', 'col' => 12];

    $all = [
        'insurance' => [
            'table' => 'insurance', 'title' => 'Insurance', 'singular' => 'Insurance policy',
            'icon' => 'bi-shield-check', 'doc_type' => 'Insurance',
            'expiry_field' => 'expiry_date', 'valid_label' => 'Valid', 'history' => true,
            'date_order' => ['start_date', 'expiry_date'],
            'columns' => ['provider' => 'Provider', 'policy_number' => 'Policy No.', 'policy_type' => 'Type',
                          'start_date' => 'Start', 'premium' => 'Premium'],
            'fields' => [
                'provider'      => ['label' => 'Insurance Company', 'type' => 'text', 'required' => true, 'max' => 100, 'col' => 6],
                'policy_number' => ['label' => 'Policy Number', 'type' => 'text', 'required' => true, 'max' => 50, 'col' => 6],
                'policy_type'   => ['label' => 'Policy Type', 'type' => 'select', 'required' => true,
                                    'options' => ['Comprehensive', 'Third Party', 'Other'], 'default' => 'Comprehensive', 'col' => 3],
                'start_date'    => ['label' => 'Start Date', 'type' => 'date', 'required' => true, 'col' => 3],
                'expiry_date'   => ['label' => 'Expiry Date', 'type' => 'date', 'required' => true, 'col' => 3],
                'premium'       => ['label' => "Premium (\u{20B9})", 'type' => 'money', 'col' => 3],
                'notes'         => $notes,
            ],
        ],
        'warranty' => [
            'table' => 'warranty', 'title' => 'Warranty', 'singular' => 'Warranty',
            'icon' => 'bi-patch-check', 'doc_type' => 'Warranty',
            'expiry_field' => 'expiry_date', 'valid_label' => 'Active', 'history' => false,
            'date_order' => ['start_date', 'expiry_date'],
            'columns' => ['provider' => 'Provider', 'start_date' => 'Start', 'coverage' => 'Coverage'],
            'fields' => [
                'provider'    => ['label' => 'Warranty Provider', 'type' => 'text', 'required' => true, 'max' => 100, 'col' => 6],
                'start_date'  => ['label' => 'Start Date', 'type' => 'date', 'required' => true, 'col' => 3],
                'expiry_date' => ['label' => 'Expiry Date', 'type' => 'date', 'required' => true, 'col' => 3],
                'coverage'    => ['label' => 'Coverage', 'type' => 'textarea', 'col' => 12],
                'notes'       => $notes,
            ],
        ],
        'registration' => [
            'table' => 'registration', 'title' => 'Registration', 'singular' => 'Registration record',
            'icon' => 'bi-card-checklist', 'doc_type' => 'Registration',
            'expiry_field' => 'registration_expiry', 'valid_label' => 'Valid', 'history' => true,
            'date_order' => ['registration_date', 'registration_expiry'],
            'columns' => ['registration_number' => 'Reg. No.', 'registration_date' => 'Registered',
                          'registration_authority' => 'Authority'],
            'fields' => [
                'registration_number'    => ['label' => 'Registration Number', 'type' => 'text', 'required' => true,
                                             'max' => 20, 'normalize_reg' => true, 'col' => 6],
                'registration_authority' => ['label' => 'Registration Authority', 'type' => 'text', 'max' => 100, 'col' => 6],
                'registration_date'      => ['label' => 'Registration Date', 'type' => 'date', 'col' => 6],
                'registration_expiry'    => ['label' => 'Registration Expiry', 'type' => 'date', 'col' => 6],
                'notes'                  => $notes,
            ],
        ],
    ];

    return $all[$key] ?? null;
}

function module_defaults(array $cfg): array
{
    $out = ['vehicle_id' => ''];
    foreach ($cfg['fields'] as $name => $f) {
        $out[$name] = $f['default'] ?? '';
    }
    return $out;
}

function read_module_post(array $cfg): array
{
    $out = [];
    foreach (array_keys(module_defaults($cfg)) as $key) {
        $raw = $_POST[$key] ?? '';
        $out[$key] = is_string($raw) ? trim($raw) : '';
    }
    return $out;
}

function module_to_form(array $cfg, array $row): array
{
    $out = ['vehicle_id' => (string)$row['vehicle_id']];
    foreach ($cfg['fields'] as $name => $f) {
        $out[$name] = isset($row[$name]) ? (string)$row[$name] : '';
    }
    return $out;
}

function find_module_record(mysqli $db, array $cfg, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT t.*, v.registration_number AS veh_reg, v.make, v.model
           FROM `' . $cfg['table'] . '` t JOIN vehicles v ON v.id = t.vehicle_id
          WHERE t.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Validates the form. Returns [$errors, $data]. */
function validate_module(array $cfg, array $in, mysqli $db, ?int $lockedVehicleId = null): array
{
    $errors = [];
    $data   = [];

    if ($lockedVehicleId !== null) {
        $vid = $lockedVehicleId;
    } else {
        $vid = valid_id($in['vehicle_id']);
        if ($vid === null) {
            $errors['vehicle_id'] = 'Please select a vehicle.';
        } elseif (find_vehicle($db, $vid) === null) {
            $errors['vehicle_id'] = 'The selected vehicle does not exist.';
            $vid = null;
        }
    }
    $data['vehicle_id'] = $vid ?? 0;

    $maxDate = date('Y-m-d', strtotime('+30 years'));

    foreach ($cfg['fields'] as $name => $f) {
        $val   = $in[$name] ?? '';
        $label = $f['label'];
        $data[$name] = null;

        if ($val === '') {
            if (!empty($f['required'])) {
                $errors[$name] = $label . ' is required.';
            }
            continue;
        }

        switch ($f['type']) {
            case 'text':
                if (!empty($f['normalize_reg'])) {
                    $val = normalize_registration($val);
                    if (!preg_match('/^[A-Z0-9]{4,15}$/', $val)) {
                        $errors[$name] = 'Use 4 to 15 letters and numbers, for example KL01AB1234.';
                        break;
                    }
                }
                if (mb_strlen($val) > $f['max']) {
                    $errors[$name] = $label . ' must be ' . $f['max'] . ' characters or fewer.';
                    break;
                }
                $data[$name] = $val;
                break;

            case 'textarea':
                if (mb_strlen($val) > 2000) {
                    $errors[$name] = $label . ' must be 2000 characters or fewer.';
                    break;
                }
                $data[$name] = $val;
                break;

            case 'select':
                if (!in_array($val, $f['options'], true)) {
                    $errors[$name] = 'Choose a valid option for ' . strtolower($label) . '.';
                    break;
                }
                $data[$name] = $val;
                break;

            case 'date':
                if (!is_valid_date($val) || $val < '1950-01-01' || $val > $maxDate) {
                    $errors[$name] = 'Enter a valid date.';
                    break;
                }
                $data[$name] = $val;
                break;

            case 'money':
                if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $val)) {
                    $errors[$name] = 'Enter a valid amount, for example 14500 or 14500.50.';
                    break;
                }
                $data[$name] = number_format((float)$val, 2, '.', '');
                break;
        }
    }

    if (!empty($cfg['date_order'])) {
        [$first, $second] = $cfg['date_order'];
        if ($data[$first] !== null && $data[$second] !== null && $data[$second] < $data[$first]) {
            $errors[$second] = $cfg['fields'][$second]['label'] . ' must be on or after '
                             . strtolower($cfg['fields'][$first]['label']) . '.';
        }
    }

    return [$errors, $data];
}

function insert_module_record(mysqli $db, array $cfg, array $data): int
{
    $cols   = array_keys($cfg['fields']);
    $sql    = 'INSERT INTO `' . $cfg['table'] . '` (vehicle_id, `' . implode('`, `', $cols) . '`) VALUES (?'
            . str_repeat(', ?', count($cols)) . ')';
    $values = [(int)$data['vehicle_id']];
    foreach ($cols as $c) {
        $values[] = $data[$c];
    }
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i' . str_repeat('s', count($cols)), ...$values);
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

function update_module_record(mysqli $db, array $cfg, int $id, array $data): void
{
    $cols   = array_keys($cfg['fields']);
    $set    = implode(', ', array_map(fn($c) => '`' . $c . '` = ?', $cols));
    $values = [];
    foreach ($cols as $c) {
        $values[] = $data[$c];
    }
    $values[] = $id;
    $stmt = $db->prepare('UPDATE `' . $cfg['table'] . '` SET ' . $set . ' WHERE id = ?');
    $stmt->bind_param(str_repeat('s', count($cols)) . 'i', ...$values);
    $stmt->execute();
    $stmt->close();
}

/** Status of an expiry date, with the module's own wording for "valid". */
function module_status(array $cfg, ?string $expiry): array
{
    $st   = expiry_status($expiry, 30);
    $keys = ['Valid' => 'valid', 'Expiring Soon' => 'soon', 'Expired' => 'expired'];
    $st['key'] = $keys[$st['label']] ?? 'none';
    if ($st['label'] === 'Valid') {
        $st['label'] = $cfg['valid_label'];
    }
    return $st;
}

/** Formats a value for the list table (returns safe HTML). */
function module_format_value(array $field, $value): string
{
    if ($value === null || $value === '') {
        return '<span class="text-muted">-</span>';
    }
    switch ($field['type']) {
        case 'date':     return e(format_date((string)$value));
        case 'money':    return e(format_money($value));
        case 'textarea': return e(mb_strimwidth((string)$value, 0, 60, '...'));
        default:         return e($value);
    }
}

/** How many documents of a type each vehicle has: vehicle_id => count. */
function module_doc_counts(mysqli $db, string $docType): array
{
    $stmt = $db->prepare('SELECT vehicle_id, COUNT(*) AS c FROM documents WHERE document_type = ? GROUP BY vehicle_id');
    $stmt->bind_param('s', $docType);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $r) {
        $out[(int)$r['vehicle_id']] = (int)$r['c'];
    }
    $stmt->close();
    return $out;
}
