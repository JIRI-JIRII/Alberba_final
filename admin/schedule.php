<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Administrator']);

/** @var mysqli $conn */

$admin_username = $_SESSION['username'];

$view = $_GET['view'] ?? 'month';
if (!in_array($view, ['month', 'week', 'day'], true)) { $view = 'month'; }

$date_param = $_GET['date'] ?? date('Y-m-d');
try {
    $current = new DateTime($date_param);
} catch (Exception $e) {
    $current = new DateTime();
}

// ---- Clinic hours (by day of week) and one-off closures, loaded once and reused for all three views ----
$clinic_hours = []; // [0..6] => ['open'=>..,'close'=>..,'is_closed'=>bool]
$res = mysqli_query($conn, "SELECT day_of_week, open_time, close_time, is_closed FROM clinic_hours");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $clinic_hours[(int) $row['day_of_week']] = [
            'open'      => $row['open_time'],
            'close'     => $row['close_time'],
            'is_closed' => (bool) $row['is_closed'],
        ];
    }
}

function is_clinic_closed_on(DateTime $d, array $clinic_hours, array $closure_dates): bool {
    $dow = (int) $d->format('w');
    if (!isset($clinic_hours[$dow]) || $clinic_hours[$dow]['is_closed']) { return true; }
    return isset($closure_dates[$d->format('Y-m-d')]);
}

// Load one-off closures just once for whatever date range the current view needs,
// instead of querying per-day (which would mean 42 round trips for month view alone)
function fetch_closure_set(mysqli $conn, string $from, string $to): array {
    $set = [];
    $stmt = mysqli_prepare($conn, "SELECT closure_date FROM clinic_closures WHERE closure_date BETWEEN ? AND ?");
    mysqli_stmt_bind_param($stmt, "ss", $from, $to);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) { $set[$row['closure_date']] = true; }
    mysqli_stmt_close($stmt);
    return $set;
}

// Active dentists — used for the Day view's per-dentist columns
$dentists = [];
$res = mysqli_query($conn, "SELECT e.employee_id, CONCAT(e.first_name, ' ', e.last_name) AS dentist_name
                             FROM employees e
                             JOIN users u ON e.user_id = u.user_id
                             JOIN roles r ON u.role_id = r.role_id
                             WHERE r.role_name = 'Dentist' AND u.account_status = 'active'
                             ORDER BY e.first_name");
if ($res) { while ($row = mysqli_fetch_assoc($res)) { $dentists[] = $row; } }

// Fetch appointments (with display fields) for an arbitrary date range
function fetch_appointments_range(mysqli $conn, string $from, string $to): array {
    $stmt = mysqli_prepare($conn, "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time, a.status, a.appointment_type,
                                           a.dentist_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                                           CONCAT(e.first_name, ' ', e.last_name) AS dentist_name, s.service_name
                                    FROM appointments a
                                    JOIN patients p  ON a.patient_id = p.patient_id
                                    JOIN employees e ON a.dentist_id = e.employee_id
                                    JOIN services s  ON a.service_id = s.service_id
                                    WHERE a.appointment_date BETWEEN ? AND ?
                                    ORDER BY a.appointment_date ASC, a.start_time ASC");
    mysqli_stmt_bind_param($stmt, "ss", $from, $to);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

$month_days = [];
$week_days = [];
$day_slots = [];
$day_appointments = [];
$range_label = '';
$prev_link = '';
$next_link = '';
$today_link = "schedule.php?view=$view&date=" . date('Y-m-d');

if ($view === 'month') {
    $month_start = (clone $current)->modify('first day of this month');
    $month_end   = (clone $current)->modify('last day of this month');
    $grid_start  = (clone $month_start)->modify('-' . $month_start->format('w') . ' days');
    $grid_end    = (clone $grid_start)->modify('+41 days'); // 6 full weeks

    $appts = fetch_appointments_range($conn, $grid_start->format('Y-m-d'), $grid_end->format('Y-m-d'));
    $closure_dates = fetch_closure_set($conn, $grid_start->format('Y-m-d'), $grid_end->format('Y-m-d'));
    $counts_by_date = [];
    foreach ($appts as $a) {
        if ($a['status'] === 'cancelled') { continue; }
        $counts_by_date[$a['appointment_date']] = ($counts_by_date[$a['appointment_date']] ?? 0) + 1;
    }

    $cursor = clone $grid_start;
    for ($i = 0; $i < 42; $i++) {
        $d_str = $cursor->format('Y-m-d');
        $closed = is_clinic_closed_on($cursor, $clinic_hours, $closure_dates);
        $month_days[] = [
            'date'         => $d_str,
            'day_num'      => (int) $cursor->format('j'),
            'in_month'     => $cursor->format('n') === $month_start->format('n'),
            'is_today'     => $d_str === date('Y-m-d'),
            'is_closed'    => $closed,
            'appt_count'   => $counts_by_date[$d_str] ?? 0,
        ];
        $cursor->modify('+1 day');
    }

    $range_label = $current->format('F Y');
    $prev_link = "schedule.php?view=month&date=" . (clone $current)->modify('-1 month')->format('Y-m-d');
    $next_link = "schedule.php?view=month&date=" . (clone $current)->modify('+1 month')->format('Y-m-d');

} elseif ($view === 'week') {
    $week_start = (clone $current)->modify('-' . $current->format('w') . ' days');
    $week_end   = (clone $week_start)->modify('+6 days');

    $appts = fetch_appointments_range($conn, $week_start->format('Y-m-d'), $week_end->format('Y-m-d'));
    $closure_dates = fetch_closure_set($conn, $week_start->format('Y-m-d'), $week_end->format('Y-m-d'));
    $by_date = [];
    foreach ($appts as $a) {
        $by_date[$a['appointment_date']][] = $a;
    }

    $cursor = clone $week_start;
    for ($i = 0; $i < 7; $i++) {
        $d_str = $cursor->format('Y-m-d');
        $day_appts = $by_date[$d_str] ?? [];
        $active_appts = array_filter($day_appts, fn($a) => $a['status'] !== 'cancelled');

        $per_dentist = [];
        foreach ($active_appts as $a) {
            $per_dentist[$a['dentist_name']] = ($per_dentist[$a['dentist_name']] ?? 0) + 1;
        }

        $week_days[] = [
            'date'        => $d_str,
            'label'       => $cursor->format('D, M j'),
            'is_today'    => $d_str === date('Y-m-d'),
            'is_closed'   => is_clinic_closed_on($cursor, $clinic_hours, $closure_dates),
            'hours'       => $clinic_hours[(int) $cursor->format('w')] ?? null,
            'appt_count'  => count($active_appts),
            'per_dentist' => $per_dentist,
        ];
        $cursor->modify('+1 day');
    }

    $range_label = $week_start->format('M j') . ' – ' . $week_end->format('M j, Y');
    $prev_link = "schedule.php?view=week&date=" . (clone $current)->modify('-7 days')->format('Y-m-d');
    $next_link = "schedule.php?view=week&date=" . (clone $current)->modify('+7 days')->format('Y-m-d');

} else { // day
    $d_str = $current->format('Y-m-d');
    $dow = (int) $current->format('w');
    $closed_today = is_clinic_closed_on($current, $clinic_hours, fetch_closure_set($conn, $d_str, $d_str));

    $day_appointments = fetch_appointments_range($conn, $d_str, $d_str);

    if (!$closed_today && isset($clinic_hours[$dow])) {
        $slot_cursor = DateTime::createFromFormat('H:i:s', $clinic_hours[$dow]['open']);
        $slot_end    = DateTime::createFromFormat('H:i:s', $clinic_hours[$dow]['close']);
        while ($slot_cursor < $slot_end) {
            $slot_label = $slot_cursor->format('H:i:s');
            $row = ['time' => $slot_label, 'display' => $slot_cursor->format('g:i A'), 'dentists' => []];
            foreach ($dentists as $dent) {
                $occupying = null;
                foreach ($day_appointments as $a) {
                    if ((int) $a['dentist_id'] !== (int) $dent['employee_id']) { continue; }
                    if ($a['status'] === 'cancelled') { continue; }
                    if ($slot_label >= $a['start_time'] && $slot_label < $a['end_time']) { $occupying = $a; break; }
                }
                $row['dentists'][$dent['employee_id']] = $occupying;
            }
            $day_slots[] = $row;
            $slot_cursor->modify('+30 minutes');
        }
    }

    $range_label = $current->format('l, F j, Y');
    $prev_link = "schedule.php?view=day&date=" . (clone $current)->modify('-1 day')->format('Y-m-d');
    $next_link = "schedule.php?view=day&date=" . (clone $current)->modify('+1 day')->format('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendar - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css">
<style>
    .tab-bar{display:flex;gap:.6rem;margin-bottom:1.4rem;}
    .tab-link{padding:.6rem 1.2rem;border-radius:100px;font-size:.86rem;font-weight:600;color:var(--text-gray);border:1.5px solid var(--border-color);background:var(--white);}
    .tab-link.active{background:var(--primary-pink);border-color:var(--primary-pink);color:var(--white);}

    .cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem;flex-wrap:wrap;gap:1rem;}
    .cal-nav h2{font-family:var(--display);font-size:1.3rem;color:var(--text-dark);}
    .cal-nav-controls{display:flex;align-items:center;gap:.5rem;}
    .cal-arrow{border:1.5px solid var(--border-color);border-radius:8px;padding:.5rem .8rem;font-weight:700;color:var(--text-gray);background:var(--white);}
    .cal-arrow:hover{border-color:var(--primary-pink);color:var(--accent-pink);}
    .cal-today{border:1.5px solid var(--primary-pink);border-radius:8px;padding:.5rem 1rem;font-size:.85rem;font-weight:600;color:var(--accent-pink);background:var(--white);}
    .cal-today:hover{background:var(--primary-pink);color:var(--white);}

    .legend{display:flex;gap:1.4rem;margin-bottom:1.2rem;flex-wrap:wrap;}
    .legend-item{display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--text-gray);font-weight:600;}
    .legend-dot{width:12px;height:12px;border-radius:4px;display:inline-block;}
    .legend-dot.green{background:var(--green);}
    .legend-dot.red{background:var(--red);}
    .legend-dot.gray{background:var(--gray);}

    /* Month grid */
    .month-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:.5rem;}
    .month-weekday{text-align:center;font-size:.75rem;font-weight:700;color:var(--text-gray);text-transform:uppercase;letter-spacing:.05em;padding-bottom:.3rem;}
    .month-cell{border-radius:10px;padding:.6rem;min-height:70px;border:1.5px solid var(--border-color);background:var(--ivory);text-decoration:none;display:block;transition:transform .15s ease;}
    .month-cell:hover{transform:translateY(-2px);}
    .month-cell.other-month{opacity:.4;}
    .month-cell.today{border-color:var(--primary-pink);border-width:2px;}
    .month-cell .day-num{font-weight:700;font-size:.9rem;color:var(--text-dark);}
    .month-cell.status-closed{background:var(--gray-bg);}
    .month-cell.status-open{background:var(--green-bg);}
    .month-cell.status-busy{background:var(--red-bg);}
    .month-cell .appt-badge{margin-top:.4rem;font-size:.72rem;font-weight:600;color:var(--text-gray);}

    /* Week table */
    .week-status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:.4rem;}

    /* Day grid */
    .day-grid{overflow-x:auto;}
    .day-grid table{border-collapse:collapse;width:100%;}
    .day-grid th, .day-grid td{border:1px solid var(--border-color);padding:.5rem .6rem;font-size:.8rem;text-align:center;white-space:nowrap;}
    .day-grid th{background:var(--light-pink);color:var(--deep-rose);font-size:.75rem;}
    .day-grid td.time-col{background:var(--ivory);font-weight:600;color:var(--text-gray);text-align:left;}
    .slot-open{background:var(--green-bg);}
    .slot-taken{background:var(--red-bg);color:var(--red);font-weight:600;cursor:pointer;}
    .slot-taken:hover{opacity:.8;}
    .closed-banner{background:var(--gray-bg);color:var(--gray);border-radius:12px;padding:2rem;text-align:center;font-weight:600;}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">Alberba <span>Dental</span></div>

    <a href="dashboard.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        Dashboard
    </a>
    <a href="appointments.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
        Appointments
    </a>
    <a href="patients.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5v-1a7.5 7.5 0 0 1 15 0v1"/></svg>
        Patient Records
    </a>
    <a href="schedule.php" class="nav-link active">
        <svg viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/></svg>
        Calendar
    </a>

    <div class="nav-section-label">Maintenance</div>
    <a href="services.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>
        Manage Services
    </a>
    <a href="users.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3.2"/><circle cx="17" cy="9.5" r="2.4"/><path d="M3 20v-.8a5.8 5.8 0 0 1 11.6 0V20"/><path d="M15.3 14.2c2.2.5 3.9 2 3.9 4v1.8"/></svg>
        User Management
    </a>
    <a href="archives.php" class="nav-link">
        <svg viewBox="0 0 24 24"><path d="M3 7.5 12 3l9 4.5-9 4.5-9-4.5Z"/><path d="M3 7.5v9L12 21l9-4.5v-9"/><path d="M12 12v9"/></svg>
        Archives
    </a>

    <div class="nav-section-label">Reports</div>
    <a href="reports.php" class="nav-link">
        <svg viewBox="0 0 24 24"><path d="M4 20V11M10 20V5M16 20v-8M22 20H2"/></svg>
        Reports &amp; Analytics
    </a>
    <a href="payments.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 10h19"/></svg>
        Payments
    </a>

    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
            Log Out
        </a>
    </div>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h1>Calendar</h1>
            <p class="subtitle">Track appointment bookings across the clinic by day, week, or month</p>
        </div>
        <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
    </div>

    <div class="tab-bar">
        <a href="schedule.php?view=day&date=<?php echo $current->format('Y-m-d'); ?>"   class="tab-link <?php echo $view === 'day'   ? 'active' : ''; ?>">Daily</a>
        <a href="schedule.php?view=week&date=<?php echo $current->format('Y-m-d'); ?>"  class="tab-link <?php echo $view === 'week'  ? 'active' : ''; ?>">Weekly</a>
        <a href="schedule.php?view=month&date=<?php echo $current->format('Y-m-d'); ?>" class="tab-link <?php echo $view === 'month' ? 'active' : ''; ?>">Monthly</a>
    </div>

    <div class="card">
        <div class="cal-nav">
            <h2><?php echo $range_label; ?></h2>
            <div class="cal-nav-controls">
                <a href="<?php echo $prev_link; ?>" class="cal-arrow">&larr;</a>
                <a href="<?php echo $today_link; ?>" class="cal-today">Today</a>
                <a href="<?php echo $next_link; ?>" class="cal-arrow">&rarr;</a>
            </div>
        </div>

        <div class="legend">
            <span class="legend-item"><span class="legend-dot green"></span> Open / Available</span>
            <span class="legend-item"><span class="legend-dot red"></span> Booked</span>
            <span class="legend-item"><span class="legend-dot gray"></span> Clinic Closed</span>
        </div>

        <?php if ($view === 'month'): ?>
            <div class="month-grid">
                <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?>
                    <div class="month-weekday"><?php echo $wd; ?></div>
                <?php endforeach; ?>
                <?php foreach ($month_days as $day): ?>
                    <?php
                        $status_class = $day['is_closed'] ? 'status-closed' : ($day['appt_count'] > 0 ? 'status-busy' : 'status-open');
                        $cell_class = trim('month-cell ' . $status_class . ($day['in_month'] ? '' : ' other-month') . ($day['is_today'] ? ' today' : ''));
                    ?>
                    <a href="schedule.php?view=day&date=<?php echo $day['date']; ?>" class="<?php echo $cell_class; ?>">
                        <div class="day-num"><?php echo $day['day_num']; ?></div>
                        <?php if ($day['is_closed']): ?>
                            <div class="appt-badge">Closed</div>
                        <?php elseif ($day['appt_count'] > 0): ?>
                            <div class="appt-badge"><?php echo $day['appt_count']; ?> appt<?php echo $day['appt_count'] === 1 ? '' : 's'; ?></div>
                        <?php else: ?>
                            <div class="appt-badge">Open</div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

        <?php elseif ($view === 'week'): ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Date</th><th>Clinic Hours</th><th>Appointments</th><th>By Dentist</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($week_days as $wd): ?>
                            <tr>
                                <td>
                                    <span class="week-status-dot" style="background:<?php echo $wd['is_closed'] ? 'var(--gray)' : ($wd['appt_count'] > 0 ? 'var(--red)' : 'var(--green)'); ?>"></span>
                                    <?php echo $wd['label']; ?><?php echo $wd['is_today'] ? ' <strong>(Today)</strong>' : ''; ?>
                                </td>
                                <td><?php echo ($wd['is_closed'] || !$wd['hours']) ? 'Closed' : date('g:i A', strtotime($wd['hours']['open'])) . ' – ' . date('g:i A', strtotime($wd['hours']['close'])); ?></td>
                                <td><?php echo $wd['appt_count']; ?></td>
                                <td>
                                    <?php if (empty($wd['per_dentist'])): ?>
                                        —
                                    <?php else: ?>
                                        <?php $parts = []; foreach ($wd['per_dentist'] as $name => $cnt) { $parts[] = htmlspecialchars($name) . " ($cnt)"; } echo implode(', ', $parts); ?>
                                    <?php endif; ?>
                                </td>
                                <td><a href="schedule.php?view=day&date=<?php echo $wd['date']; ?>" class="btn-sm btn-view">View Day</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: // day ?>
            <?php if (empty($day_slots)): ?>
                <div class="closed-banner">The clinic is closed on <?php echo $current->format('l, F j'); ?>.</div>
            <?php else: ?>
                <div class="day-grid">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <?php foreach ($dentists as $dent): ?>
                                    <th><?php echo htmlspecialchars($dent['dentist_name']); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($day_slots as $slot): ?>
                                <tr>
                                    <td class="time-col"><?php echo $slot['display']; ?></td>
                                    <?php foreach ($dentists as $dent): ?>
                                        <?php $occ = $slot['dentists'][$dent['employee_id']] ?? null; ?>
                                        <?php if ($occ): ?>
                                            <td class="slot-taken" onclick='showAppt(<?php echo json_encode($occ, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>Booked</td>
                                        <?php else: ?>
                                            <td class="slot-open">Open</td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($dentists)): ?>
                    <p style="font-size:.8rem;color:var(--text-gray);margin-top:1rem;">No active dentist accounts yet — add one from User Management to see per-dentist availability here.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($view === 'day'): ?>
    <div class="card">
        <div class="card-head">
            <div><h2>Pending Appointments</h2><p>All appointments booked for <?php echo $current->format('F j, Y'); ?></p></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Time</th><th>Patient</th><th>Dentist</th><th>Service</th><th>Type</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($day_appointments)): ?>
                        <tr class="empty-row"><td colspan="6">No appointments booked for this date.</td></tr>
                    <?php else: ?>
                        <?php foreach ($day_appointments as $a): ?>
                            <tr>
                                <td><?php echo date('g:i A', strtotime($a['start_time'])); ?></td>
                                <td><?php echo htmlspecialchars($a['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['dentist_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['service_name']); ?></td>
                                <td><span class="badge <?php echo htmlspecialchars($a['appointment_type']); ?>"><?php echo ucfirst($a['appointment_type']); ?></span></td>
                                <td><span class="badge <?php echo htmlspecialchars($a['status']); ?>"><?php echo status_label($a['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- Appointment Detail Modal -->
<div class="modal-overlay" id="apptModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Appointment Details</h3>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="apptModalBody"></div>
    </div>
</div>

<script>
    function showAppt(a) {
        const rows = [
            ['Patient', a.patient_name],
            ['Dentist', a.dentist_name],
            ['Service', a.service_name],
            ['Time', new Date('1970-01-01T' + a.start_time).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'}) + ' – ' + new Date('1970-01-01T' + a.end_time).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'})],
            ['Type', a.appointment_type === 'online' ? 'Online Booking' : 'Walk-in'],
            ['Status', a.status.replace('_',' ').replace(/\b\w/g, c => c.toUpperCase())],
        ];
        document.getElementById('apptModalBody').innerHTML = rows.map(([label, value]) =>
            `<div class="detail-row"><span>${label}</span><strong>${value}</strong></div>`
        ).join('');
        document.getElementById('apptModal').classList.add('open');
    }

    function closeModal() {
        document.getElementById('apptModal').classList.remove('open');
    }

    document.getElementById('apptModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
</script>
</body>
</html>