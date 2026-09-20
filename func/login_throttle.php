<?php
/**
 * Brute-force throttling for the login form.
 *
 * Requires the login_attempts table (see migrations/2026_01_login_attempts.sql).
 * If that table hasn't been created yet every function here fails open, so login
 * keeps working exactly as before rather than breaking the whole site.
 */

const LOGIN_MAX_PER_ACCOUNT  = 8;   // failures for one username from one IP
const LOGIN_MAX_PER_IP       = 30;  // failures from one IP across all usernames
const LOGIN_LOCKOUT_MINUTES  = 15;

function login_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * True when this IP has failed too many times recently — either against this one
 * account (targeted guessing) or across many accounts (password spraying).
 */
function login_attempts_exceeded(mysqli $conn, string $username): bool
{
    $ip = login_client_ip();

    try {
        $stmt = mysqli_prepare($conn,
            "SELECT
                SUM(username = ?) AS for_account,
                COUNT(*)          AS for_ip
             FROM login_attempts
             WHERE ip_address = ?
               AND attempted_at > (NOW() - INTERVAL ? MINUTE)");

        $window = LOGIN_LOCKOUT_MINUTES;
        mysqli_stmt_bind_param($stmt, "ssi", $username, $ip, $window);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) {
            return false;
        }

        return (int) $row['for_account'] >= LOGIN_MAX_PER_ACCOUNT
            || (int) $row['for_ip']      >= LOGIN_MAX_PER_IP;

    } catch (Throwable $e) {
        return false; // table missing — don't block anyone
    }
}

function record_login_failure(mysqli $conn, string $username): void
{
    try {
        $ip = login_client_ip();
        $stmt = mysqli_prepare($conn,
            "INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, "ss", $username, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Opportunistic cleanup so the table doesn't grow without bound.
        if (random_int(1, 50) === 1) {
            mysqli_query($conn, "DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (Throwable $e) {
        // table missing — nothing to record
    }
}

function clear_login_failures(mysqli $conn, string $username): void
{
    try {
        $ip = login_client_ip();
        $stmt = mysqli_prepare($conn,
            "DELETE FROM login_attempts WHERE username = ? AND ip_address = ?");
        mysqli_stmt_bind_param($stmt, "ss", $username, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        // table missing — nothing to clear
    }
}
