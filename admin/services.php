<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Administrator']);
require_csrf();

/** @var mysqli $conn */

$admin_username = $_SESSION['username'];
$message = '';
$error = '';

// ---- Handle: Add Service ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $name        = sanitizeInput($_POST['service_name'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $category    = sanitizeInput($_POST['category'] ?? '');
    $duration    = $_POST['duration_minutes'] ?? '';
    $price       = $_POST['price'] ?? '';

    if (empty($name) || $duration === '' || $price === '') {
        $error = "Please fill in the service name, duration, and price.";
    } elseif (!is_numeric($duration) || (int) $duration <= 0) {
        $error = "Duration must be a positive number of minutes.";
    } elseif (!is_numeric($price) || (float) $price <= 0) {
        $error = "Price must be a positive number.";
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO services (service_name, description, category, duration_minutes, price, created_by)
                                        VALUES (?, ?, ?, ?, ?, ?)");
        $duration_int = (int) $duration;
        $price_dec = (float) $price;
        mysqli_stmt_bind_param($stmt, "sssidi", $name, $description, $category, $duration_int, $price_dec, $_SESSION['user_id']);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            header('Location: services.php?added=1');
            exit;
        } else {
            $error = "Failed to add service.";
        }
        mysqli_stmt_close($stmt);
    }
}

// ---- Handle: Edit Service (any detail, including active/inactive toggle) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service'])) {
    $service_id  = intval($_POST['service_id']);
    $name        = sanitizeInput($_POST['service_name'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $category    = sanitizeInput($_POST['category'] ?? '');
    $duration    = $_POST['duration_minutes'] ?? '';
    $price       = $_POST['price'] ?? '';
    $is_active   = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;

    if (empty($name) || $duration === '' || $price === '') {
        $error = "Please fill in the service name, duration, and price.";
    } elseif (!is_numeric($duration) || (int) $duration <= 0) {
        $error = "Duration must be a positive number of minutes.";
    } elseif (!is_numeric($price) || (float) $price <= 0) {
        $error = "Price must be a positive number.";
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE services SET service_name = ?, description = ?, category = ?, duration_minutes = ?, price = ?, is_active = ? WHERE service_id = ?");
        $duration_int = (int) $duration;
        $price_dec = (float) $price;
        mysqli_stmt_bind_param($stmt, "sssidii", $name, $description, $category, $duration_int, $price_dec, $is_active, $service_id);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            header('Location: services.php?updated=1');
            exit;
        } else {
            $error = "Failed to update service.";
        }
        mysqli_stmt_close($stmt);
    }
}

// ---- Handle: Delete Service (soft delete -> Recently Deleted bin) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_service'])) {
    $service_id = intval($_POST['service_id']);
    $stmt = mysqli_prepare($conn, "UPDATE services SET deleted_at = NOW(), is_active = 0 WHERE service_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $service_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: services.php?deleted=1');
    exit;
}

// ---- Handle: Restore Service (out of the Recently Deleted bin) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_service'])) {
    $service_id = intval($_POST['service_id']);
    $stmt = mysqli_prepare($conn, "UPDATE services SET deleted_at = NULL, is_active = 1 WHERE service_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $service_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: services.php?restored=1&view=deleted');
    exit;
}

// ---- Handle: Permanently Delete Service ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge_service'])) {
    $service_id = intval($_POST['service_id']);

    $stmt = mysqli_prepare($conn, "SELECT service_name, description, category, duration_minutes, price, deleted_at FROM services WHERE service_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $service_id);
    mysqli_stmt_execute($stmt);
    $svc = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($svc) {
        $archive_stmt = mysqli_prepare($conn, "INSERT INTO services_archive (original_service_id, service_name, description, category, duration_minutes, price, deleted_at)
                                                VALUES (?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($archive_stmt, "isssids", $service_id, $svc['service_name'], $svc['description'], $svc['category'], $svc['duration_minutes'], $svc['price'], $svc['deleted_at']);
        mysqli_stmt_execute($archive_stmt);
        mysqli_stmt_close($archive_stmt);

        $delete_stmt = mysqli_prepare($conn, "DELETE FROM services WHERE service_id = ?");
        mysqli_stmt_bind_param($delete_stmt, "i", $service_id);
        if (mysqli_stmt_execute($delete_stmt)) {
            mysqli_stmt_close($delete_stmt);
            header('Location: services.php?purged=1&view=deleted');
            exit;
        } else {
            // most likely a foreign key constraint (errno 1451) — appointments or billing still reference this service
            mysqli_stmt_close($delete_stmt);
            if (mysqli_errno($conn) === 1451) {
                $error = "This service can't be permanently deleted — it still has appointment or billing history attached. It will stay safely in Recently Deleted, or you can restore it instead.";
            } else {
                $error = "Failed to permanently delete this service.";
            }
        }
    }
}

if (isset($_GET['added']))    { $message = "Service added successfully."; }
if (isset($_GET['updated']))  { $message = "Service updated."; }
if (isset($_GET['deleted']))  { $message = "Service moved to Recently Deleted. It will be kept there for 30 days."; }
if (isset($_GET['restored'])) { $message = "Service restored to the active catalog."; }
if (isset($_GET['purged']))   { $message = "Service permanently deleted."; }

$view = ($_GET['view'] ?? 'active') === 'deleted' ? 'deleted' : 'active';

// ---- Count for the Recently Deleted tab badge ----
$deleted_count = 0;
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM services WHERE deleted_at IS NOT NULL AND deleted_at > NOW() - INTERVAL 30 DAY");
if ($res && $row = mysqli_fetch_assoc($res)) { $deleted_count = (int) $row['total']; }

$services = [];
$categories = [];
$search = '';

if ($view === 'active') {
    // ---- Search + Category + Status filter + Sort (Active Services tab) ----
    $search        = trim($_GET['search'] ?? '');
    $category_filter = $_GET['category'] ?? 'all';
    $status_filter = $_GET['status'] ?? 'all';
    $sort          = $_GET['sort'] ?? 'name_asc';

    $conditions = ["deleted_at IS NULL"];
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(service_name LIKE '%$s%' OR category LIKE '%$s%')";
    }
    if ($category_filter !== 'all' && $category_filter !== '') {
        $conditions[] = "category = '" . mysqli_real_escape_string($conn, $category_filter) . "'";
    }
    if ($status_filter !== 'all') {
        $conditions[] = "is_active = " . ($status_filter === 'active' ? 1 : 0);
    }
    $where_clause = implode(' AND ', $conditions);

    $order_by = "service_name ASC";
    if ($sort === 'name_desc')  { $order_by = "service_name DESC"; }
    if ($sort === 'price_asc')  { $order_by = "price ASC"; }
    if ($sort === 'price_desc') { $order_by = "price DESC"; }

    $query = "SELECT service_id, service_name, description, category, duration_minutes, price, is_active, created_at
              FROM services WHERE $where_clause ORDER BY $order_by";
    $result = mysqli_query($conn, $query);
    $services = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

    $cat_result = mysqli_query($conn, "SELECT DISTINCT category FROM services WHERE deleted_at IS NULL AND category IS NOT NULL AND category <> '' ORDER BY category");
    if ($cat_result) {
        while ($row = mysqli_fetch_assoc($cat_result)) { $categories[] = $row['category']; }
    }
} else {
    // ---- Recently Deleted tab ----
    $result = mysqli_query($conn, "SELECT service_id, service_name, category, price, deleted_at,
                                           DATEDIFF(deleted_at + INTERVAL 30 DAY, NOW()) AS days_left_before_purge
                                    FROM services
                                    WHERE deleted_at IS NOT NULL AND deleted_at > NOW() - INTERVAL 30 DAY
                                    ORDER BY deleted_at DESC");
    $services = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Services - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css">
<style>
    .tab-bar{display:flex;gap:.6rem;margin-bottom:1.4rem;}
    .tab-link{padding:.6rem 1.2rem;border-radius:100px;font-size:.86rem;font-weight:600;color:var(--text-gray);border:1.5px solid var(--border-color);background:var(--white);}
    .tab-link.active{background:var(--primary-pink);border-color:var(--primary-pink);color:var(--white);}
    .tab-link .count{opacity:.85;font-weight:600;}
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
    <a href="schedule.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/></svg>
        Calendar
    </a>

    <div class="nav-section-label">Maintenance</div>
    <a href="services.php" class="nav-link active">
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
            <h1>Manage Services</h1>
            <p class="subtitle">Keep the clinic's service catalog accurate and up to date</p>
        </div>
        <div class="topbar-right">
            <?php if ($view === 'active'): ?>
                <button type="button" class="btn-primary-sm" onclick="openAdd()">+ Add Service</button>
            <?php endif; ?>
            <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
        </div>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="tab-bar">
        <a href="services.php?view=active" class="tab-link <?php echo $view === 'active' ? 'active' : ''; ?>">Active Services</a>
        <a href="services.php?view=deleted" class="tab-link <?php echo $view === 'deleted' ? 'active' : ''; ?>">Recently Deleted <span class="count">(<?php echo $deleted_count; ?>)</span></a>
    </div>

    <?php if ($view === 'active'): ?>

        <div class="card">
            <form class="filter-bar" method="get" action="services.php">
                <input type="hidden" name="view" value="active">
                <div class="filter-field" style="flex:1;min-width:200px;">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Service name or category">
                </div>
                <div class="filter-field">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="all">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="all"      <?php echo $status_filter === 'all'      ? 'selected' : ''; ?>>All</option>
                        <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="sort">Sort By</label>
                    <select id="sort" name="sort">
                        <option value="name_asc"   <?php echo $sort === 'name_asc'   ? 'selected' : ''; ?>>Name (A–Z)</option>
                        <option value="name_desc"  <?php echo $sort === 'name_desc'  ? 'selected' : ''; ?>>Name (Z–A)</option>
                        <option value="price_asc"  <?php echo $sort === 'price_asc'  ? 'selected' : ''; ?>>Price (Low–High)</option>
                        <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price (High–Low)</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Search</button>
                    <a href="services.php?view=active" class="btn-reset">Reset</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-head">
                <div><h2>Service Catalog</h2><p><?php echo count($services); ?> service<?php echo count($services) === 1 ? '' : 's'; ?></p></div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Service</th><th>Category</th><th>Duration</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($services)): ?>
                            <tr class="empty-row"><td colspan="6">No services match these filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($services as $svc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($svc['service_name']); ?></td>
                                    <td><?php echo htmlspecialchars($svc['category'] ?: '—'); ?></td>
                                    <td><?php echo (int) $svc['duration_minutes']; ?> min</td>
                                    <td><?php echo format_currency($svc['price']); ?></td>
                                    <td><span class="badge <?php echo $svc['is_active'] ? 'completed' : 'cancelled'; ?>"><?php echo $svc['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn-sm btn-complete" onclick='openEdit(<?php echo json_encode($svc, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>Edit</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Move this service to Recently Deleted? Patients won\'t be able to book it, and it will be kept for 30 days.');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="service_id" value="<?php echo $svc['service_id']; ?>">
                                                <button type="submit" name="delete_service" class="btn-sm btn-cancel">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>

        <div class="card">
            <div class="card-head">
                <div><h2>Recently Deleted</h2><p>Kept for 30 days from deletion, then permanently removed automatically</p></div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Service</th><th>Category</th><th>Price</th><th>Deleted On</th><th>Days Left</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($services)): ?>
                            <tr class="empty-row"><td colspan="6">Nothing in Recently Deleted right now.</td></tr>
                        <?php else: ?>
                            <?php foreach ($services as $svc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($svc['service_name']); ?></td>
                                    <td><?php echo htmlspecialchars($svc['category'] ?: '—'); ?></td>
                                    <td><?php echo format_currency($svc['price']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($svc['deleted_at'])); ?></td>
                                    <td><span class="badge <?php echo $svc['days_left_before_purge'] <= 5 ? 'pending' : 'confirmed'; ?>"><?php echo max(0, (int) $svc['days_left_before_purge']); ?> days</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this service to the active catalog?');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="service_id" value="<?php echo $svc['service_id']; ?>">
                                                <button type="submit" name="restore_service" class="btn-sm btn-confirm">Restore</button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this service? This cannot be undone. It will only succeed if no appointments or billing records reference it.');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="service_id" value="<?php echo $svc['service_id']; ?>">
                                                <button type="submit" name="purge_service" class="btn-sm btn-cancel">Delete Permanently</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</main>

<!-- Add Service Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal modal-wide">
        <div class="modal-head">
            <h3>Add Service</h3>
            <button type="button" class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" novalidate><?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label for="add_service_name">Service Name *</label>
                    <input type="text" id="add_service_name" name="service_name" required>
                </div>
                <div class="form-group">
                    <label for="add_description">Description</label>
                    <textarea id="add_description" name="description" rows="3"></textarea>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add_category">Category</label>
                        <input type="text" id="add_category" name="category" placeholder="e.g. Preventive, Surgical, Cosmetic">
                    </div>
                    <div class="form-group">
                        <label for="add_duration">Duration (minutes) *</label>
                        <input type="number" id="add_duration" name="duration_minutes" min="1" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="add_price">Price (₱) *</label>
                    <input type="number" id="add_price" name="price" min="0" step="0.01" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" name="add_service" class="btn-primary-sm">Add Service</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Service Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal modal-wide">
        <div class="modal-head">
            <h3>Edit Service</h3>
            <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" novalidate><?php echo csrf_field(); ?>
            <input type="hidden" name="service_id" id="edit_service_id">
            <div class="modal-body">
                <div class="form-group">
                    <label for="edit_service_name">Service Name *</label>
                    <input type="text" id="edit_service_name" name="service_name" required>
                </div>
                <div class="form-group">
                    <label for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" rows="3"></textarea>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_category">Category</label>
                        <input type="text" id="edit_category" name="category">
                    </div>
                    <div class="form-group">
                        <label for="edit_duration">Duration (minutes) *</label>
                        <input type="number" id="edit_duration" name="duration_minutes" min="1" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_price">Price (₱) *</label>
                        <input type="number" id="edit_price" name="price" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_is_active">Status</label>
                        <select id="edit_is_active" name="is_active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" name="edit_service" class="btn-primary-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAdd() {
        document.getElementById('addModal').classList.add('open');
    }

    function openEdit(svc) {
        document.getElementById('edit_service_id').value = svc.service_id;
        document.getElementById('edit_service_name').value = svc.service_name;
        document.getElementById('edit_description').value = svc.description || '';
        document.getElementById('edit_category').value = svc.category || '';
        document.getElementById('edit_duration').value = svc.duration_minutes;
        document.getElementById('edit_price').value = svc.price;
        document.getElementById('edit_is_active').value = svc.is_active ? '1' : '0';
        document.getElementById('editModal').classList.add('open');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
    }

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.classList.remove('open');
        });
    });

    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.transition = 'opacity .5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
</script>
</body>
</html>