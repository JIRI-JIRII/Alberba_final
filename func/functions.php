<?php

function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit();
    }
}


function checkRole($allowedRoles) {
    $currentRole = $_SESSION['role'] ?? '';
    $allowedLower = array_map('strtolower', $allowedRoles);

    if (!in_array(strtolower($currentRole), $allowedLower, true)) {
        // The public landing page is index.php (there is no index.html in this project).
        header('Location: ../index.php');
        exit();
    }
}

// General-purpose text cleanup for form input that gets echoed back later
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Turns 'pending_operation' into 'Pending Operation', etc. — used on every status badge
function status_label($status) {
    return ucwords(str_replace('_', ' ', $status));
}

// Shared currency formatting for reports, billing, and dashboard stat cards
function format_currency($amount) {
    return '₱' . number_format((float) $amount, 2);
}

/**
 * Escapes the LIKE wildcards % and _ so a search for "100%" or "a_b" matches those
 * literal characters instead of acting as a wildcard. Run this BEFORE
 * mysqli_real_escape_string().
 */
function like_escape(string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

/**
 * Returns the bookable windows for a dentist on a given date as [start, end] pairs.
 *
 * Clinic opening hours are the outer bound. On top of that, if the dentist has any rows
 * in dentist_schedules, those rows define the days/hours they actually work and the
 * result is the intersection of the two. A dentist with no rows at all is treated as
 * "schedule not configured" and falls back to full clinic hours, so existing installs
 * with an empty dentist_schedules table keep behaving exactly as they did before.
 */
function get_working_windows(mysqli $conn, int $dentist_id, string $date): array {
    $dow = (int) (new DateTime($date))->format('w');

    // --- Clinic hours for this day of week ---
    $stmt = mysqli_prepare($conn, "SELECT open_time, close_time, is_closed FROM clinic_hours WHERE day_of_week = ?");
    mysqli_stmt_bind_param($stmt, "i", $dow);
    mysqli_stmt_execute($stmt);
    $hours = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$hours || (int) $hours['is_closed'] === 1) {
        return [];
    }

    // --- Clinic-wide closure (holiday, etc.) ---
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM clinic_closures WHERE closure_date = ?");
    mysqli_stmt_bind_param($stmt, "s", $date);
    mysqli_stmt_execute($stmt);
    $is_closed = mysqli_stmt_get_result($stmt)->num_rows > 0;
    mysqli_stmt_close($stmt);

    if ($is_closed) {
        return [];
    }

    $clinic_open  = $hours['open_time'];
    $clinic_close = $hours['close_time'];

    // --- Does this dentist have a configured schedule at all? ---
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM dentist_schedules WHERE employee_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $dentist_id);
    mysqli_stmt_execute($stmt);
    $configured = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'] > 0;
    mysqli_stmt_close($stmt);

    if (!$configured) {
        return [['start' => $clinic_open, 'end' => $clinic_close]];
    }

    // --- Shifts this dentist works on this day of week ---
    $stmt = mysqli_prepare($conn, "SELECT start_time, end_time FROM dentist_schedules
                                    WHERE employee_id = ? AND day_of_week = ?
                                    ORDER BY start_time");
    mysqli_stmt_bind_param($stmt, "ii", $dentist_id, $dow);
    mysqli_stmt_execute($stmt);
    $shifts = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $windows = [];
    foreach ($shifts as $shift) {
        // Clip each shift to the clinic's opening hours (TIME strings are zero-padded,
        // so plain string comparison is safe here).
        $start = max($shift['start_time'], $clinic_open);
        $end   = min($shift['end_time'],   $clinic_close);
        if ($start < $end) {
            $windows[] = ['start' => $start, 'end' => $end];
        }
    }

    return $windows;
}

/**
 * Compute real available time slots for a dentist on a given date, sized to a service's
 * duration — not a fixed 30-min grid. A slot only counts if the *entire* duration fits
 * inside one working window and doesn't overlap any existing (non-cancelled) booking for
 * that dentist. Used by the patient booking flow and the receptionist walk-in flow.
 */
function get_available_slots(mysqli $conn, int $dentist_id, string $date, int $duration_minutes): array {
    if ($duration_minutes <= 0) {
        return [];
    }

    $windows = get_working_windows($conn, $dentist_id, $date);
    if (!$windows) {
        return [];
    }

    $stmt = mysqli_prepare($conn, "SELECT start_time, end_time FROM appointments
                                    WHERE dentist_id = ? AND appointment_date = ? AND status != 'cancelled'");
    mysqli_stmt_bind_param($stmt, "is", $dentist_id, $date);
    mysqli_stmt_execute($stmt);
    $booked = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $now   = new DateTime();
    $slots = [];

    foreach ($windows as $window) {
        $open  = DateTime::createFromFormat('Y-m-d H:i:s', "$date " . $window['start']);
        $close = DateTime::createFromFormat('Y-m-d H:i:s', "$date " . $window['end']);
        if (!$open || !$close) {
            continue;
        }

        $cursor = clone $open;
        while (true) {
            $slot_end = (clone $cursor)->modify("+{$duration_minutes} minutes");
            if ($slot_end > $close) { break; }

            if ($cursor < $now) { $cursor->modify('+30 minutes'); continue; }

            $start_str = $cursor->format('H:i:s');
            $end_str   = $slot_end->format('H:i:s');

            $conflict = false;
            foreach ($booked as $b) {
                if ($start_str < $b['end_time'] && $end_str > $b['start_time']) { $conflict = true; break; }
            }

            if (!$conflict) {
                $slots[] = ['start' => $start_str, 'end' => $end_str, 'display' => $cursor->format('g:i A')];
            }
            $cursor->modify('+30 minutes');
        }
    }

    // Sort defensively so the UI always lists slots chronologically across windows.
    usort($slots, function ($a, $b) { return strcmp($a['start'], $b['start']); });

    return $slots;
}

/**
 * Atomically books an appointment, re-checking for overlaps inside a transaction.
 *
 * get_available_slots() on its own is a check-then-act race: two people can both be
 * offered the same slot and both pass the check before either INSERTs. This locks the
 * dentist's rows for that date with FOR UPDATE, so a second booker blocks until the
 * first commits and then correctly sees the conflict.
 *
 * Returns the new appointment_id, or null if the slot was taken in the meantime.
 */
function book_appointment_safely(
    mysqli $conn,
    int $patient_id,
    int $dentist_id,
    int $service_id,
    string $date,
    string $start_time,
    string $end_time,
    string $type,
    string $status,
    int $booked_by,
    ?int $confirmed_by = null
): ?int {
    mysqli_begin_transaction($conn);

    try {
        // Lock this dentist's bookings for the date so concurrent bookings serialise here.
        $stmt = mysqli_prepare($conn, "SELECT start_time, end_time FROM appointments
                                        WHERE dentist_id = ? AND appointment_date = ? AND status != 'cancelled'
                                        FOR UPDATE");
        mysqli_stmt_bind_param($stmt, "is", $dentist_id, $date);
        mysqli_stmt_execute($stmt);
        $booked = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        foreach ($booked as $b) {
            if ($start_time < $b['end_time'] && $end_time > $b['start_time']) {
                mysqli_rollback($conn);
                return null; // someone grabbed it first
            }
        }

        $stmt = mysqli_prepare($conn, "INSERT INTO appointments
            (patient_id, dentist_id, service_id, appointment_date, start_time, end_time,
             appointment_type, status, booked_by, confirmed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param(
            $stmt, "iiisssssii",
            $patient_id, $dentist_id, $service_id, $date, $start_time, $end_time,
            $type, $status, $booked_by, $confirmed_by
        );
        $ok = mysqli_stmt_execute($stmt);
        $new_id = $ok ? mysqli_insert_id($conn) : null;
        mysqli_stmt_close($stmt);

        if (!$ok) {
            mysqli_rollback($conn);
            return null;
        }

        mysqli_commit($conn);
        return $new_id;

    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return null;
    }
}
