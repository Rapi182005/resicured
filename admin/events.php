<?php 
session_start();

// 1. SECURITY UTILITY GATEWAY
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

// 2. SAFE DATABASE ACCESS INTEGRATION
if (file_exists('../config/database.php')) {
    require_once '../config/database.php';
} else {
    $host = "localhost";
    $user = "root";
    $pass = "";
    $dbname = "resicured_db";
    $conn = mysqli_connect($host, $user, $pass, $dbname);
    if (!$conn) {
        die("Database Connection Failed: " . mysqli_connect_error());
    }
}

// 3. HANDLER: ADD NEW EVENT (FIXED FOREIGN KEY CHECK)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $title = mysqli_real_escape_string($conn, trim($_POST['title']));
    $event_date = mysqli_real_escape_string($conn, $_POST['event_date']);
    $location = mysqli_real_escape_string($conn, trim($_POST['location']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description']));

    // Capture user ID from session
    $session_user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
    $created_by = "NULL";

    // Validate that the session user ID actually exists in the `users` table
    if (!empty($session_user_id)) {
        $safe_user_id = intval($session_user_id);
        $user_check = mysqli_query($conn, "SELECT id FROM users WHERE id = $safe_user_id");
        if ($user_check && mysqli_num_rows($user_check) > 0) {
            $created_by = $safe_user_id; // Valid FK match
        }
    }

    $insert_query = "INSERT INTO events (title, description, event_date, location, created_by) 
                     VALUES ('$title', '$description', '$event_date', '$location', $created_by)";

    if (mysqli_query($conn, $insert_query)) {
        $_SESSION['status'] = "Subdivision event published successfully!";
        $_SESSION['status_type'] = "success";
    } else {
        $_SESSION['status'] = "Error publishing event: " . mysqli_error($conn);
        $_SESSION['status_type'] = "danger";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// 4. HANDLER: DELETE EVENT
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $event_id = intval($_GET['id']);
    $delete_query = "DELETE FROM events WHERE id = $event_id";

    if (mysqli_query($conn, $delete_query)) {
        $_SESSION['status'] = "Event deleted successfully!";
        $_SESSION['status_type'] = "success";
    } else {
        $_SESSION['status'] = "Error deleting event: " . mysqli_error($conn);
        $_SESSION['status_type'] = "danger";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// 5. FETCH DATA & STATISTICS
$events_query = "SELECT * FROM events ORDER BY event_date ASC";
$events_result = mysqli_query($conn, $events_query);

$total_events = $events_result ? mysqli_num_rows($events_result) : 0;

$upcoming_query = "SELECT COUNT(*) as total FROM events WHERE event_date >= NOW()";
$upcoming_res = mysqli_query($conn, $upcoming_query);
$upcoming_count = ($upcoming_res && $u_row = mysqli_fetch_assoc($upcoming_res)) ? $u_row['total'] : 0;

$past_query = "SELECT COUNT(*) as total FROM events WHERE event_date < NOW()";
$past_res = mysqli_query($conn, $past_query);
$past_count = ($past_res && $p_row = mysqli_fetch_assoc($past_res)) ? $p_row['total'] : 0;

// Page tracking for dynamic sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Subdivision Events</title>
    <!-- Bootstrap 5 & FontAwesome 6 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --subdivision-orange: #e66a00; 
            --subdivision-amber: #ffaa00;  
            --text-dark: #2d3748;          
            --bg-light: #f8fafc;           
        }

        body {
            background-color: var(--bg-light);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .page-wrapper {
            display: flex !important;
            flex-direction: row !important;
            min-height: 100vh;
            width: 100%;
        }

        .sidebar {
            width: 260px !important;
            min-width: 260px !important;
            background-color: #ffffff !important;
            border-right: 1px solid #e2e8f0 !important;
            padding-top: 24px;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            box-sizing: border-box !important;
        }

        .main-content {
            flex-grow: 1 !important;
            padding: 40px !important;
            box-sizing: border-box !important;
            background-color: var(--bg-light) !important;
        }

        .brand-logo-area {
            padding: 0 24px 20px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo-icon {
            color: var(--subdivision-orange);
            font-size: 1.6rem;
        }

        .brand-logo-text {
            color: var(--text-dark);
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0;
        }

        .sidebar-menu {
            list-style: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .sidebar .nav-link {
            color: #4a5568 !important;
            font-size: 14px;
            font-weight: 500;
            padding: 12px 20px;
            margin: 4px 16px;
            border-radius: 8px;
            display: flex !important;
            align-items: center;
            text-decoration: none !important;
            transition: all 0.2s ease;
        }

        .sidebar .nav-link:hover {
            color: var(--subdivision-orange) !important;
            background-color: rgba(230, 106, 0, 0.05) !important;
        }

        .sidebar .nav-link.active {
            color: #ffffff !important;
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%) !important;
            font-weight: 600;
        }

        .sidebar .nav-link i {
            font-size: 16px;
            width: 28px;
        }

        .logout-btn-container {
            padding-bottom: 24px;
        }

        .logout-btn {
            background-color: #fff5f5 !important;
            color: #c53030 !important;
            border: 1px solid #fed7d7 !important;
        }

        .logout-btn:hover {
            background-color: #e53e3e !important;
            color: #ffffff !important;
        }

        .dashboard-title {
            color: var(--text-dark);
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0 0 4px 0;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .card-counter {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(230, 106, 0, 0.01);
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: transform 0.2s ease;
        }

        .card-counter:hover {
            transform: translateY(-2px);
        }

        .stat-label {
            color: #718096;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.75px;
            display: block;
        }

        .stat-value {
            color: var(--text-dark);
            font-size: 28px;
            font-weight: 700;
            margin-top: 4px;
        }

        .icon-badge {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 18px;
        }

        .badge-orange { background-color: rgba(230, 106, 0, 0.08); color: var(--subdivision-orange); }
        .badge-amber { background-color: rgba(255, 170, 0, 0.1); color: #d97706; }
        .badge-success-custom { background-color: #dcfce7; color: #15803d; }

        .content-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            box-sizing: border-box;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .btn-orange-action {
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%);
            color: #ffffff;
            font-weight: 600;
            border-radius: 8px;
            padding: 8px 18px;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: opacity 0.2s ease;
        }

        .btn-orange-action:hover {
            opacity: 0.9;
            color: #ffffff;
        }

        .filter-search-input {
            font-size: 13px;
            font-weight: 500;
            color: #475569;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 14px;
            width: 320px;
            transition: all 0.2s;
        }

        .filter-search-input:focus {
            border-color: var(--subdivision-orange);
            box-shadow: 0 0 0 3px rgba(230, 106, 0, 0.15);
            outline: none;
        }

        .custom-log-table {
            margin-bottom: 0;
            vertical-align: middle;
        }
        
        .custom-log-table thead th {
            background-color: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 16px;
            border-bottom: 2px solid #edf2f7;
        }

        .custom-log-table tbody td {
            padding: 16px;
            color: #334155;
            font-size: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .badge-location-tag {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 500;
        }

        .btn-soft-danger {
            background-color: #fff5f5;
            color: #c53030;
            border: 1px solid #fed7d7;
            font-weight: 600;
            font-size: 12px;
            padding: 5px 12px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-soft-danger:hover {
            background-color: #e53e3e;
            color: #ffffff;
        }
    </style>
</head>
<body>

<div class="page-wrapper">
    
    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div>
            <div class="brand-logo-area border-bottom">
                <i class="fa fa-shield-halved brand-logo-icon"></i>
                <h4 class="brand-logo-text">ResiCured</h4>
            </div>
            
            <ul class="sidebar-menu mt-3">
                <li><a href="dashboard.php" class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fa fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="events.php" class="nav-link <?= ($current_page == 'events.php') ? 'active' : ''; ?>"><i class="fa fa-calendar-alt"></i> Events</a></li>
                <li><a href="residents.php" class="nav-link <?= ($current_page == 'residents.php') ? 'active' : ''; ?>"><i class="fa fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link <?= ($current_page == 'face_registration.php') ? 'active' : ''; ?>"><i class="fa fa-user-shield"></i> Personnel</a></li>
                <li><a href="requests.php" class="nav-link <?= ($current_page == 'requests.php') ? 'active' : ''; ?>"><i class="fa fa-file-alt"></i> Requests</a></li>
                <li><a href="billing.php" class="nav-link <?= ($current_page == 'billing.php') ? 'active' : ''; ?>"><i class="fa fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link <?= ($current_page == 'expenses.php') ? 'active' : ''; ?>"><i class="fa fa-money-bill-transfer"></i> Expenses</a></li>
                <li><a href="guards.php" class="nav-link <?= ($current_page == 'guards.php') ? 'active' : ''; ?>"><i class="fa fa-user-lock"></i> Staff Guards</a></li>
            </ul>
        </div>

        <div class="logout-btn-container">
            <hr class="mx-3 text-muted">
            <a href="../logout.php" class="nav-link logout-btn"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        
        <!-- Header Bar -->
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom" style="width: 100%;">
            <div>
                <h1 class="h3 dashboard-title">Subdivision Events</h1>
                <p class="text-muted small mb-0">Manage subdivision announcements, assembly meetings, and community calendar schedules.</p>
            </div>
            <button class="btn btn-orange-action" data-bs-toggle="modal" data-bs-target="#addEventModal">
                <i class="fa fa-plus"></i> Add Event
            </button>
        </div>

        <!-- Alert Notification -->
        <?php if (isset($_SESSION['status'])): ?>
            <div class="alert alert-<?= isset($_SESSION['status_type']) ? $_SESSION['status_type'] : 'info'; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                <?= $_SESSION['status']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php 
                unset($_SESSION['status']); 
                unset($_SESSION['status_type']);
            ?>
        <?php endif; ?>

        <!-- Stat Counter Cards -->
        <div class="metrics-grid">
            <div class="card-counter">
                <div>
                    <span class="stat-label">Total Published Events</span>
                    <div class="stat-value"><?= $total_events; ?></div>
                </div>
                <div class="icon-badge badge-orange"><i class="fa fa-calendar-days"></i></div>
            </div>
            
            <div class="card-counter">
                <div>
                    <span class="stat-label">Upcoming Events</span>
                    <div class="stat-value text-warning"><?= $upcoming_count; ?></div>
                </div>
                <div class="icon-badge badge-amber"><i class="fa fa-clock"></i></div>
            </div>
            
            <div class="card-counter">
                <div>
                    <span class="stat-label">Completed / Past</span>
                    <div class="stat-value text-success"><?= $past_count; ?></div>
                </div>
                <div class="icon-badge badge-success-custom"><i class="fa fa-check-circle"></i></div>
            </div>
        </div>

        <!-- Table Container Card -->
        <div class="content-card">
            
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                <div class="d-flex align-items-center position-relative">
                    <input type="text" id="eventSearch" class="filter-search-input ps-5" placeholder="Search event title or location..." onkeyup="filterEvents()">
                    <i class="fa fa-search text-muted position-absolute ms-3" style="font-size: 13px;"></i>
                </div>
                <div class="text-secondary small fw-semibold">
                    Directory Records: <span class="text-dark fw-bold" id="recordCount"><?= $total_events; ?></span>
                </div>
            </div>

            <div class="table-responsive" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
                <table class="table custom-log-table" id="eventsTable">
                    <thead>
                        <tr>
                            <th>Event Details</th>
                            <th>Date & Time</th>
                            <th>Location</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($events_result && mysqli_num_rows($events_result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($events_result)): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark mb-1"><?= htmlspecialchars($row['title']); ?></div>
                                        <?php if (!empty($row['description'])): ?>
                                            <div class="text-muted" style="font-size: 13px;"><?= htmlspecialchars($row['description']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-monospace" style="font-size: 13px;">
                                        <i class="fa-regular fa-calendar-days text-muted me-1"></i> 
                                        <?= date("M d, Y — g:i A", strtotime($row['event_date'])); ?>
                                    </td>
                                    <td>
                                        <span class="badge-location-tag">
                                            <i class="fa-solid fa-location-dot me-1 text-danger"></i><?= htmlspecialchars($row['location']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="<?= $_SERVER['PHP_SELF']; ?>?action=delete&id=<?= $row['id']; ?>" 
                                           class="btn-soft-danger" 
                                           onclick="return confirm('Are you sure you want to delete this event?');">
                                           <i class="fa-solid fa-trash-can me-1"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr id="noRecordsRow">
                                <td colspan="4" class="text-center py-5 text-muted small">
                                    <i class="fa fa-calendar-xmark d-block mb-2 text-secondary" style="font-size: 28px;"></i>
                                    No subdivision events scheduled at the moment.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div> 
    </div> 
</div> 

<!-- Add Event Modal -->
<div class="modal fade" id="addEventModal" tabindex="-1" aria-labelledby="addEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header border-bottom pb-3">
                <h5 class="modal-title fw-bold text-dark" id="addEventModalLabel">Add New Subdivision Event</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= $_SERVER['PHP_SELF']; ?>" method="POST">
                <div class="modal-body py-3">
                    <div class="mb-3">
                        <label for="title" class="form-label fw-semibold text-secondary small">Event Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control filter-search-input w-100" id="title" name="title" placeholder="e.g., General HOA Assembly" required>
                    </div>

                    <div class="mb-3">
                        <label for="event_date" class="form-label fw-semibold text-secondary small">Event Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control filter-search-input w-100" id="event_date" name="event_date" required>
                    </div>

                    <div class="mb-3">
                        <label for="location" class="form-label fw-semibold text-secondary small">Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control filter-search-input w-100" id="location" name="location" placeholder="e.g., Subdivision Clubhouse" required>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label fw-semibold text-secondary small">Event Description / Details</label>
                        <textarea class="form-control filter-search-input w-100" id="description" name="description" rows="3" placeholder="Enter agendas or details for residents..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top pt-3">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_event" class="btn btn-orange-action">Publish Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Search Filter Script -->
<script>
function filterEvents() {
    let input = document.getElementById("eventSearch").value.toLowerCase();
    let rows = document.querySelectorAll("#eventsTable tbody tr");
    let visibleCount = 0;

    rows.forEach(row => {
        if(row.id === "noRecordsRow") return;
        let text = row.innerText.toLowerCase();
        if (text.includes(input)) {
            row.style.display = "";
            visibleCount++;
        } else {
            row.style.display = "none";
        }
    });

    document.getElementById("recordCount").innerText = visibleCount;
}
</script>

</body>
</html>