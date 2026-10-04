<?php
/** AutoCare Cloud - helpers shared by the dashboard and analytics pages. */
require_once __DIR__ . '/service_helpers.php';   // also loads vehicle_helpers.php

/**
 * Every money amount in one list: services, fuel logs, insurance premiums and expenses.
 * Columns: vehicle_id, cost_date, category, amount, source.
 */
function cost_ledger_sql(): string
{
    return "SELECT s.vehicle_id, s.service_date AS cost_date,
                   CASE WHEN s.service_type IN ('Engine Repair','Electrical Repair') THEN 'Repairs' ELSE 'Maintenance' END AS category,
                   s.total_cost AS amount, 'Service' AS source
              FROM services s
            UNION ALL
            SELECT f.vehicle_id, f.fuel_date, 'Fuel', f.total_cost, 'Fuel log' FROM fuel_logs f
            UNION ALL
            SELECT i.vehicle_id, i.start_date, 'Insurance', i.premium, 'Insurance' FROM insurance i WHERE i.premium IS NOT NULL
            UNION ALL
            SELECT x.vehicle_id, x.expense_date, x.category, x.amount, 'Expense' FROM expenses x";
}

/**
 * Documents, current insurance, current registration and warranties that are expired
 * or expire within $withinDays. Sorted by expiry date (oldest first).
 */
function fetch_expiring_items(mysqli $db, int $withinDays = 30): array
{
    $limit = (new DateTimeImmutable('today'))->modify('+' . $withinDays . ' days')->format('Y-m-d');

    $sql = "SELECT x.kind, x.name, x.vehicle_id, x.expiry, v.registration_number AS veh_reg, v.make, v.model
              FROM (
                SELECT 'Document' AS kind, d.document_name AS name, d.vehicle_id, d.expiry_date AS expiry
                  FROM documents d
                 WHERE d.expiry_date IS NOT NULL AND d.expiry_date <= ?
                   AND d.expiry_date = (SELECT MAX(d2.expiry_date) FROM documents d2
                                         WHERE d2.vehicle_id = d.vehicle_id AND d2.document_type = d.document_type)
                UNION ALL
                SELECT 'Insurance', CONCAT(i.provider, ' / ', i.policy_number), i.vehicle_id, i.expiry_date
                  FROM insurance i
                 WHERE i.expiry_date <= ?
                   AND i.expiry_date = (SELECT MAX(i2.expiry_date) FROM insurance i2 WHERE i2.vehicle_id = i.vehicle_id)
                UNION ALL
                SELECT 'Registration', CONCAT('Registration ', r.registration_number), r.vehicle_id, r.registration_expiry
                  FROM registration r
                 WHERE r.registration_expiry IS NOT NULL AND r.registration_expiry <= ?
                   AND r.registration_expiry = (SELECT MAX(r2.registration_expiry) FROM registration r2 WHERE r2.vehicle_id = r.vehicle_id)
                UNION ALL
                SELECT 'Warranty', w.provider, w.vehicle_id, w.expiry_date FROM warranty w WHERE w.expiry_date <= ?
              ) x
              JOIN vehicles v ON v.id = x.vehicle_id
             ORDER BY x.expiry ASC, x.kind ASC
             LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ssss', $limit, $limit, $limit, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {
        $r['status'] = expiry_status($r['expiry'], $withinDays);
    }
    unset($r);
    return $rows;
}
