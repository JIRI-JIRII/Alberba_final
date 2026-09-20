<?php
/**
 * Database connection.
 *
 * Variables are prefixed $db_* so they can't be clobbered by (or clobber) page-level
 * variables such as $username / $password, which several pages read from $_POST.
 */

date_default_timezone_set('Asia/Manila');

$db_host = 'localhost';
$db_name = 'alberba_dental_clinic'; // matches CREATE DATABASE in the .sql file
$db_user = 'root';
$db_pass = '';

// Make mysqli raise exceptions instead of silent warnings, so the try/catch blocks
// around transactions in the app actually fire and roll back.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
} catch (Throwable $e) {
    // Never echo the driver message: it leaks the host, database name and username.
    error_log('[alberba] DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('The clinic system is temporarily unavailable. Please try again shortly.');
}

// Keep PHP <-> MySQL charset in sync with the utf8mb4 database created in the .sql file
mysqli_set_charset($conn, 'utf8mb4');

// Credentials shouldn't linger in scope for the rest of the request.
unset($db_host, $db_name, $db_user, $db_pass);
