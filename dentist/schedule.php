<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';
/** @var mysqli $conn */
checkLogin();
checkRole(['Dentist']);

$dentist_id = $_SESSION['employee_id'] ?? 0;
if (!$dentist_id) {
    header('Location: ../login.php');
    exit;
}

$dentist_stmt = mysqli_prepare($conn, "SELECT first_name, last_name, specialization
                                        FROM employees WHERE employee_id = ?");
mysqli_stmt_bind_param($dentist_stmt, "i", $dentist_id);
mysqli_stmt_execute($dentist_stmt);
$dentist = mysqli_fetch_assoc(mysqli_stmt_get_result($dentist_stmt));
mysqli_stmt_close($dentist_stmt);

// The calendar shows a 7-day window. ?start=YYYY-MM-DD moves it; default is today.
$start_param = $_GET['start'] ?? date('Y-m-d');
$start_date  = DateTime::createFromFormat('Y-m-d', $start_param) ?: new DateTime();
$start_date->setTime(0, 0);

$range_start = $start_date->format('Y-m-d');
$range_end   = (clone $start_date)->modify('+6 days')->format('Y-m-d');

$prev_start = (clone $start_date)->modify('-7 days')->format('Y-m-d');
$next_start = (clone $start_date)->modify('+7 days')->format('Y-m-d');

// One query for the whole week instead of seven queries in a loop.
$stmt = mysqli_prepare($conn, "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time,
                                      a.status, a.appointment_type,
                                      p.first_name AS patient_fname, p.last_name AS patient_lname,
                                      p.contact_number,
                                      s.service_name
                               FROM appointments a
                               JOIN patients p ON a.patient_id = p.patient_id
                               JOIN services s ON a.service_id = s.service_id
                               WHERE a.dentist_id = ?
                                 AND a.appointment_date BETWEEN ? AND ?
                               ORDER BY a.appointment_date ASC, a.start_time ASC");
mysqli_stmt_bind_param($stmt, "iss", $dentist_id, $range_start, $range_end);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$by_date = [];
foreach ($rows as $row) {
    $by_date[$row['appointment_date']][] = $row;
}

// Build the 7 days, annotating each with the dentist's actual working hours.
$days = [];
$week_total = 0;
for ($i = 0; $i < 7; $i++) {
    $day  = (clone $start_date)->modify("+$i days");
    $date = $day->format('Y-m-d');

    $appointments = $by_date[$date] ?? [];
    $active = array_values(array_filter($appointments, fn($a) => $a['status'] !== 'cancelled'));
    $week_total += count($active);

    $windows = get_working_windows($conn, $dentist_id, $date);
    $hours_label = '';
    if ($windows) {
        $parts = [];
        foreach ($windows as $w) {
            $parts[] = date('g:i A', strtotime($w['start'])) . ' – ' . date('g:i A', strtotime($w['end']));
        }
        $hours_label = implode(', ', $parts);
    }

    $days[] = [
        'date'         => $date,
        'day_name'     => $day->format('l'),
        'display'      => $day->format('M j'),
        'is_today'     => $date === date('Y-m-d'),
        'appointments' => $appointments,
        'active_count' => count($active),
        'hours_label'  => $hours_label,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Calendar - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/dentist.css">
<style>
    .day-block { border-top: 1px solid var(--border-color, #e9dde3); padding: 1rem 0; }
    .day-block:first-child { border-top: none; }
    .day-head { display: flex; align-items: baseline; gap: .6rem; flex-wrap: wrap; margin-bottom: .6rem; }
    .day-head h3 { font-size: .98rem; margin: 0; }
    .day-head .date-sub { font-size: .82rem; color: #7a6670; }
    .day-head .hours { font-size: .78rem; color: #7a6670; margin-left: auto; }
    .today-pill { background: #d4739b; color: #fff; border-radius: 100px; padding: .1rem .55rem; font-size: .7rem; font-weight: 600; }
    .rest-day { font-size: .82rem; color: #9a8790; font-style: italic; }
    .appt-line { display: flex; gap: .75rem; align-items: baseline; padding: .4rem 0; flex-wrap: wrap; }
    .appt-time { font-weight: 600; font-size: .85rem; min-width: 8.5rem; }
    .appt-meta { font-size: .82rem; color: #7a6670; }
    .cancelled-line { opacity: .55; text-decoration: line-through; }
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
        My Appointments
    </a>
    <a href="patients.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5v-1a7.5 7.5 0 0 1 15 0v1"/></svg>
        My Patients
    </a>
    <a href="schedule.php" class="nav-link active">
        <svg viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/></svg>
        Calendar
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
            <h1>My Calendar</h1>
            <p class="today"><?php echo date('M j, Y', strtotime($range_start)); ?> &ndash; <?php echo date('M j, Y', strtotime($range_end)); ?></p>
        </div>
        <span class="welcome-pill">
            Dr. <?php echo htmlspecialchars(($dentist['first_name'] ?? '') . ' ' . ($dentist['last_name'] ?? '')); ?>
        </span>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Week at a glance</h2>
                <p><?php echo $week_total; ?> appointment<?php echo $week_total === 1 ? '' : 's'; ?> scheduled this week</p>
            </div>
            <div class="filter-actions">
                <a class="btn-ghost-sm" href="schedule.php?start=<?php echo htmlspecialchars($prev_start); ?>">&larr; Previous</a>
                <a class="btn-ghost-sm" href="schedule.php">This week</a>
                <a class="btn-ghost-sm" href="schedule.php?start=<?php echo htmlspecialchars($next_start); ?>">Next &rarr;</a>
            </div>
        </div>

        <div style="padding: 0 1.25rem 1rem;">
            <?php foreach ($days as $day): ?>
                <div class="day-block">
                    <div class="day-head">
                        <h3><?php echo htmlspecialchars($day['day_name']); ?></h3>
                        <span class="date-sub"><?php echo htmlspecialchars($day['display']); ?></span>
                        <?php if ($day['is_today']): ?><span class="today-pill">Today</span><?php endif; ?>
                        <span class="hours">
                            <?php echo $day['hours_label'] !== ''
                                ? htmlspecialchars($day['hours_label'])
                                : 'Not working'; ?>
                        </span>
                    </div>

                    <?php if (empty($day['appointments'])): ?>
                        <div class="rest-day">
                            <?php echo $day['hours_label'] !== '' ? 'No appointments booked.' : 'Clinic closed or day off.'; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($day['appointments'] as $a): ?>
                            <div class="appt-line <?php echo $a['status'] === 'cancelled' ? 'cancelled-line' : ''; ?>">
                                <span class="appt-time">
                                    <?php echo date('g:i A', strtotime($a['start_time'])); ?>
                                    &ndash;
                                    <?php echo date('g:i A', strtotime($a['end_time'])); ?>
                                </span>
                                <span>
                                    <?php echo htmlspecialchars($a['patient_fname'] . ' ' . $a['patient_lname']); ?>
                                </span>
                                <span class="appt-meta">
                                    <?php echo htmlspecialchars($a['service_name']); ?>
                                    <?php if (!empty($a['contact_number'])): ?>
                                        &middot; <?php echo htmlspecialchars($a['contact_number']); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="badge <?php echo htmlspecialchars($a['status']); ?>" style="margin-left:auto;">
                                    <?php echo status_label($a['status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

</body>
</html>
