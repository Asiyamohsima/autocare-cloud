<?php
/**
 * AutoCare Cloud - Database connection (MySQL via mysqli).
 *
 * db()     returns a connection or throws an exception.
 * try_db() returns a connection or null, and logs the technical
 *          reason privately instead of showing it to the user.
 */

function db(): mysqli
{
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    $configFile = __DIR__ . '/config.local.php';
    if (!is_readable($configFile)) {
        throw new RuntimeException('Database configuration file is missing or unreadable.');
    }

    $cfg = require $configFile;

    // Make mysqli throw exceptions instead of printing warnings.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn = new mysqli(
        $cfg['db_host'],
        $cfg['db_user'],
        $cfg['db_pass'],
        $cfg['db_name']
    );
    $conn->set_charset('utf8mb4');

    return $conn;
}

function try_db(): ?mysqli
{
    try {
        return db();
    } catch (Throwable $e) {
        // Technical detail goes to the server log only (Apache error log).
        error_log('[AutoCare] Database connection failed: ' . $e->getMessage());
        return null;
    }
}
