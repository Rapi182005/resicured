<?php 
session_start();

// 1. SECURITY GATEWAY: Kicks out unauthorized sessions
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

$success_msg = "";
$error_msg = "";

// Track current tab selection (default to 'request')
$current_type = (isset($_GET['type']) && $_GET['type'] === 'concern') ? 'concern' : 'request';

// ================= ACTION: PROCESS REQUEST & CONCERN STATUS UPDATES =================
if (isset($_GET['action']) && isset($_GET['id'])) {
    $request_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    // Map actions to database status values
    $valid_actions = [
        'approve'     => 'approved',
        'reject'      => 'rejected',
        'in_progress' => 'in_progress',
        'resolve'     => 'resolved',
        'dismiss'     => 'dismissed',
        'reopen'      => 'pending'
    ];

    if (array_key_exists($action, $valid_actions)) {
        $status_value = $valid_actions[$action];
        
        $sql = "UPDATE requests SET status = '$status_value' WHERE id = $request_id";
        if ($conn->query($sql)) {
            $display_label = ($status_value === 'pending') ? 'REOPENED (AWAITING REVIEW)' : strtoupper(str_replace('_', ' ', $status_value));
            header("Location: requests.php?type=" . urlencode($current_type) . "&success=Status updated to " . $display_label . " successfully.");
            exit();
        } else {
            $error_msg = "Database Error: " . $conn->error;
        }
    }
}

if (isset($_GET['success'])) { $success_msg = $_GET['success']; }

// 2. FETCH REQUESTS / CONCERNS BASED ON SELECTED TAB
if ($current_type === 'concern') {
    $where_clause = "WHERE LOWER(req.requester_type) LIKE '%concern%'";
} else {
    $where_clause = "WHERE (LOWER(req.requester_type) NOT LIKE '%concern%' OR req.requester_type IS NULL OR req.requester_type = '')";
}

$requests_query = "SELECT req.id as request_id, req.requester_type, req.request_details, req.status, req.created_at, res.full_name, res.house_number 
                   FROM requests req
                   JOIN residents res ON req.requester_name = res.id 
                   $where_clause
                   ORDER BY (req.status IS NULL OR req.status = '' OR req.status = 'pending') DESC, req.id DESC";
$requests_result = $conn->query($requests_query);

// Summary Counts
$pending_count = 0;
$resolved_count = 0;
$total_count = 0;

if ($requests_result) {
    $total_count = $requests_result->num_rows;
    // We will iterate or fetch all into array to count stats and retain row iteration
    $all_rows = [];
    while ($row = $requests_result->fetch_assoc()) {
        $st = strtolower(trim($row['status'] ?? 'pending'));
        if ($st === 'pending' || $st === '') $pending_count++;
        if ($st === 'resolved' || $st === 'approved') $resolved_count++;
        $all_rows[] = $row;
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Requests & Concerns Hub</title>
    <!-- Modern Typography: Plus Jakarta Sans -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --subdivision-orange: #ea580c;
            --subdivision-amber: #f97316;
            --subdivision-soft-orange: #fff7ed;
            --subdivision-border: #ffedd5;
            --bg-main: #f8fafc;
            --sidebar-bg: #ffffff;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --text-dark: #2d3748;
            --border-color: #e2e8f0;
            --radius-lg: 14px;
            --radius-md: 10px;
            --radius-sm: 6px;
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-body);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .page-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* SIDEBAR STYLING (MATCHED TO RESIDENTS) */
        .sidebar {
            width: 250px !important;
            min-width: 250px !important;
            background-color: #ffffff !important;
            border-right: 1px solid #f0f3f7 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            height: 100vh !important;
            position: sticky !important;
            top: 0 !important;
            box-sizing: border-box !important;
            padding-bottom: 16px;
        }

        .brand-logo-area {
            padding: 24px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .brand-logo-icon { 
            color: #ffffff; 
            background: linear-gradient(135deg, #e65c00, #f06a00);
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(230, 92, 0, 0.35);
        }

        .brand-logo-text { 
            color: #1e293b; 
            font-size: 20px; 
            font-weight: 700; 
            margin: 0; 
            letter-spacing: -0.4px;
        }

        .sidebar-section-title {
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 20px 24px 8px 24px;
            margin: 0;
        }

        .sidebar-menu { 
            list-style: none !important; 
            padding: 0 !important; 
            margin: 0 !important; 
        }

        .sidebar .nav-link {
            color: #334155 !important;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 16px;
            margin: 3px 14px;
            border-radius: 12px;
            display: flex !important;
            align-items: center;
            text-decoration: none !important;
            transition: all 0.2s ease;
        }

        .sidebar .nav-link:not(.active):hover {
            color: #e66a00 !important;
            background-color: #fff7ed !important;
        }

        .sidebar .nav-link.active {
            color: #ffffff !important;
            background-color: #e65c00 !important;
            box-shadow: 0 4px 12px rgba(230, 92, 0, 0.35);
            font-weight: 600;
        }

        .sidebar .nav-link i {
            font-size: 16px;
            width: 24px;
            text-align: center;
            margin-right: 10px;
        }

        .logout-container {
            padding: 16px;
            border-top: 1px solid #f1f5f9;
        }

        .logout-btn {
            background-color: #fef2f2 !important;
            color: #334155 !important;
            border: 1px solid #fecaca !important;
            border-radius: 12px !important;
            padding: 10px 16px !important;
            font-weight: 600 !important;
            display: flex !important;
            align-items: center !important;
            text-decoration: none !important;
            transition: all 0.2s ease;
            margin: 0 !important;
        }

        .logout-btn:hover {
            background-color: #fee2e2 !important;
            color: #dc2626 !important;
        }

        /* MAIN CONTENT AREA */
        .main-content {
            flex-grow: 1;
            padding: 32px 40px;
            background-color: var(--bg-main);
            max-width: calc(100vw - 250px);
        }

        .dashboard-title { 
            color: var(--text-heading); 
            font-weight: 800; 
            font-size: 24px;
            letter-spacing: -0.5px;
            margin: 0; 
        }

        /* FILTER BUTTONS */
        .filter-btn {
            padding: 10px 20px;
            border-radius: var(--radius-md);
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            border: 1px solid var(--border-color);
            background-color: #ffffff;
            color: var(--text-muted);
        }
        .filter-btn:hover {
            border-color: var(--subdivision-orange);
            color: var(--subdivision-orange);
        }
        .filter-btn.active {
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%);
            color: #ffffff;
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.25);
        }

        /* STAT CARDS */
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }

        /* TABLE CARD */
        .table-card { 
            background: #ffffff; 
            border: 1px solid var(--border-color); 
            border-radius: var(--radius-lg); 
            overflow: hidden; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .modern-table { margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
        .modern-table thead th {
            background-color: #f8fafc;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
        }
        .modern-table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-body);
            font-size: 13.5px;
        }

        /* ACTION CONTROLS & BADGES */
        .action-link {
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-view { background-color: #eff6ff; color: #2563eb; }
        .btn-view:hover { background-color: #dbeafe; color: #1d4ed8; }
        .btn-approve { background-color: #dcfce7; color: #15803d; }
        .btn-approve:hover { background-color: #bbf7d0; color: #166534; }
        .btn-reject { background-color: #fee2e2; color: #dc2626; }
        .btn-reject:hover { background-color: #fca5a5; color: #991b1b; }
        
        .status-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 20px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .status-badge-pending { background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .status-badge-progress { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .status-badge-resolved { background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .status-badge-closed { background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .status-badge-rejected { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        .form-control {
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            padding: 8px 12px;
            font-size: 13.5px;
        }
        .form-control:focus {
            border-color: var(--subdivision-orange);
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.12);
        }
    </style>
</head>
<body>

<div class="page-wrapper">
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div>
            <div class="brand-logo-area">
                <div class="brand-logo-icon">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h4 class="brand-logo-text">ResiCured</h4>
            </div>
            
            <div class="sidebar-section-title">MAIN MENU</div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php" class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="households.php" class="nav-link <?= ($current_page == 'households.php') ? 'active' : ''; ?>"><i class="fa-solid fa-house-user"></i> Household Directory</a></li>
                <li><a href="residents.php" class="nav-link <?= ($current_page == 'residents.php') ? 'active' : ''; ?>"><i class="fa-solid fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link <?= ($current_page == 'face_registration.php') ? 'active' : ''; ?>"><i class="fa-solid fa-user-gear"></i> Personnel</a></li>
                <li><a href="guards.php" class="nav-link <?= ($current_page == 'guards.php') ? 'active' : ''; ?>"><i class="fa-solid fa-user-shield"></i> Staff Guards</a></li>
            </ul>

            <div class="sidebar-section-title">OPERATIONS</div>
            <ul class="sidebar-menu">
                <li><a href="events.php" class="nav-link <?= ($current_page == 'events.php') ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-days"></i> Events</a></li>
                <li><a href="requests.php" class="nav-link <?= ($current_page == 'requests.php') ? 'active' : ''; ?>"><i class="fa-solid fa-file-lines"></i> Requests & Concerns</a></li>
                <li><a href="billing.php" class="nav-link <?= ($current_page == 'billing.php') ? 'active' : ''; ?>"><i class="fa-solid fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link <?= ($current_page == 'expenses.php') ? 'active' : ''; ?>"><i class="fa-solid fa-money-bill-transfer"></i> Expenses</a></li>
            </ul>
        </div>

        <div class="logout-container">
            <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a>
        </div>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="main-content">
        <!-- HEADER TITLE -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="dashboard-title"><?php echo ($current_type === 'concern') ? 'Residents Concern Stream' : 'Residents Request Stream'; ?></h1>
                <p class="text-muted small mb-0 mt-1">
                    <?php echo ($current_type === 'concern') ? 'Review house issues, noise complaints, and subdivision maintenance concerns.' : 'Review facility permit requests, gate clearance certificates, and amenities permissions.'; ?>
                </p>
            </div>
        </div>

        <!-- STREAM SELECTION TABS & METRICS -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #fff7ed; color: #ea580c;">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Total Logged</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $total_count; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #fef3c7; color: #d97706;">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Awaiting Review</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $pending_count; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #dcfce7; color: #15803d;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Resolved / Approved</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $resolved_count; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center gap-2">
                <a href="requests.php?type=request" class="filter-btn <?php echo ($current_type === 'request') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-lines"></i> Requests Review
                </a>
                <a href="requests.php?type=concern" class="filter-btn <?php echo ($current_type === 'concern') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-triangle-exclamation"></i> Concerns Review
                </a>
            </div>
            <input type="text" id="streamSearchInput" class="form-control form-control-sm" style="max-width:240px;" placeholder="Search resident or details...">
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4 small fw-medium" style="border-radius: var(--radius-md);"><i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4 small fw-medium" style="border-radius: var(--radius-md);"><i class="fa-solid fa-circle-exclamation me-2"></i><strong>Stream Exception:</strong> <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <!-- TABLE CARD -->
        <div class="table-card p-0">
            <div class="table-responsive">
                <table class="table modern-table align-middle" id="requestsDataTable">
                    <thead>
                        <tr>
                            <th>Submitting Household</th>
                            <th>Category</th>
                            <th>Detailed Narrative / Description</th>
                            <th>Date Filed</th>
                            <th>Current State</th>
                            <th class="text-end" style="padding-right:24px;">Action Controls</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($all_rows) > 0): ?>
                            <?php foreach ($all_rows as $row): 
                                $modal_id = "viewRequestModal_" . $row['request_id'];
                                $req_category = !empty(trim($row['requester_type'])) ? $row['requester_type'] : (($current_type === 'concern') ? 'House Concern' : 'General Request');
                                $raw_status = trim($row['status'] ?? '');
                                $status_clean = !empty($raw_status) ? strtolower($raw_status) : 'pending';
                            ?>
                                <tr class="stream-row">
                                    <td>
                                        <div class="fw-bold text-dark target-name"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                        <small class="text-muted fw-semibold"><i class="fa fa-home me-1 text-warning"></i><?php echo htmlspecialchars($row['house_number']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1 fw-semibold" style="font-size:11px; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($req_category); ?>
                                        </span>
                                    </td>
                                    <td style="max-width:320px; white-space: normal;">
                                        <span class="small text-secondary target-details"><?php echo htmlspecialchars($row['request_details']); ?></span>
                                    </td>
                                    <td class="text-secondary small fw-medium">
                                        <?php echo date('M d, Y, g:i A', strtotime($row['created_at'])); ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($status_clean === 'pending') {
                                            echo '<span class="status-badge status-badge-pending"><i class="fa-solid fa-clock"></i> Awaiting Review</span>';
                                        } elseif ($status_clean === 'in_progress') {
                                            echo '<span class="status-badge status-badge-progress"><i class="fa-solid fa-spinner fa-spin"></i> In Progress</span>';
                                        } elseif ($status_clean === 'resolved' || $status_clean === 'approved') {
                                            echo '<span class="status-badge status-badge-resolved"><i class="fa-solid fa-circle-check"></i> ' . ucfirst($status_clean) . '</span>';
                                        } elseif ($status_clean === 'rejected') {
                                            echo '<span class="status-badge status-badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';
                                        } elseif ($status_clean === 'dismissed' || $status_clean === 'closed') {
                                            echo '<span class="status-badge status-badge-closed"><i class="fa-solid fa-box-archive"></i> Closed</span>';
                                        } else {
                                            echo '<span class="status-badge status-badge-pending"><i class="fa-solid fa-clock"></i> Awaiting Review</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end" style="padding-right:24px;">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <button type="button" class="action-link btn-view" data-bs-toggle="modal" data-bs-target="#<?php echo $modal_id; ?>">
                                                <i class="fa-solid fa-eye"></i> View
                                            </button>

                                            <?php if ($current_type === 'concern'): ?>
                                                <?php if ($status_clean === 'pending'): ?>
                                                    <a href="requests.php?type=concern&action=in_progress&id=<?php echo $row['request_id']; ?>" 
                                                       class="btn btn-warning btn-sm text-dark fw-semibold"
                                                       onclick="return confirm('Mark this concern as in progress?');">
                                                        <i class="fa-solid fa-spinner me-1"></i> In Progress
                                                    </a>
                                                    <a href="requests.php?type=concern&action=resolve&id=<?php echo $row['request_id']; ?>" 
                                                       class="btn btn-success btn-sm fw-semibold"
                                                       onclick="return confirm('Mark this concern as resolved?');">
                                                        <i class="fa-solid fa-check me-1"></i> Resolve
                                                    </a>
                                                <?php elseif ($status_clean === 'in_progress'): ?>
                                                    <a href="requests.php?type=concern&action=resolve&id=<?php echo $row['request_id']; ?>" 
                                                       class="btn btn-success btn-sm fw-semibold"
                                                       onclick="return confirm('Mark this concern as resolved?');">
                                                        <i class="fa-solid fa-check me-1"></i> Resolve
                                                    </a>
                                                    <a href="requests.php?type=concern&action=reopen&id=<?php echo $row['request_id']; ?>" 
                                                       class="btn btn-outline-secondary btn-sm fw-semibold"
                                                       onclick="return confirm('Reset status to pending review?');">
                                                        <i class="fa-solid fa-rotate-left me-1"></i> Reopen
                                                    </a>
                                                <?php else: ?>
                                                    <a href="requests.php?type=concern&action=reopen&id=<?php echo $row['request_id']; ?>" 
                                                       class="btn btn-outline-secondary btn-sm fw-semibold"
                                                       onclick="return confirm('Reopen this concern back to pending review?');">
                                                        <i class="fa-solid fa-rotate-left me-1"></i> Reopen
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($status_clean === 'pending'): ?>
                                                    <a href="requests.php?type=request&action=approve&id=<?php echo $row['request_id']; ?>" 
                                                       class="action-link btn-approve"
                                                       onclick="return confirm('Grant formal approval for this request?');">
                                                        <i class="fa-solid fa-circle-check"></i> Approve
                                                    </a>
                                                    <a href="requests.php?type=request&action=reject&id=<?php echo $row['request_id']; ?>" 
                                                       class="action-link btn-reject"
                                                       onclick="return confirm('Decline this request?');">
                                                        <i class="fa-solid fa-circle-xmark"></i> Reject
                                                    </a>
                                                <?php else: ?>
                                                    <a href="requests.php?type=request&action=reopen&id=<?php echo $row['request_id']; ?>" 
                                                       class="btn btn-outline-secondary btn-sm fw-semibold"
                                                       onclick="return confirm('Reset this request status to pending review?');">
                                                        <i class="fa-solid fa-rotate-left me-1"></i> Reopen
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <!-- VIEW DETAIL MODAL -->
                                        <div class="modal fade text-start" id="<?php echo $modal_id; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content border-0">
                                                    <div class="modal-header border-bottom py-3 bg-light">
                                                        <h6 class="modal-title fw-bold text-dark fs-6"><i class="fa-solid fa-file-invoice text-warning me-2"></i>Record Details</h6>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body p-4 bg-white">
                                                        <div class="mb-3">
                                                            <label class="text-muted small text-uppercase fw-bold">Submitting Resident</label>
                                                            <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                                            <div class="small text-secondary"><i class="fa fa-home me-1 text-warning"></i><?php echo htmlspecialchars($row['house_number']); ?></div>
                                                        </div>
                                                        <div class="row mb-3">
                                                            <div class="col-6">
                                                                <label class="text-muted small text-uppercase fw-bold">Category</label>
                                                                <div><span class="badge bg-light text-dark border px-2 py-1 fw-semibold"><?php echo htmlspecialchars($req_category); ?></span></div>
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="text-muted small text-uppercase fw-bold">Current State</label>
                                                                <div>
                                                                    <?php 
                                                                    if ($status_clean === 'pending') {
                                                                        echo '<span class="status-badge status-badge-pending">Awaiting Review</span>';
                                                                    } elseif ($status_clean === 'in_progress') {
                                                                        echo '<span class="status-badge status-badge-progress">In Progress</span>';
                                                                    } elseif ($status_clean === 'resolved' || $status_clean === 'approved') {
                                                                        echo '<span class="status-badge status-badge-resolved">' . ucfirst($status_clean) . '</span>';
                                                                    } else {
                                                                        echo '<span class="status-badge status-badge-closed">Closed</span>';
                                                                    }
                                                                    ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="text-muted small text-uppercase fw-bold">Date Filed</label>
                                                            <div class="small text-dark fw-semibold"><?php echo date('F d, Y - h:i A', strtotime($row['created_at'])); ?></div>
                                                        </div>
                                                        <div>
                                                            <label class="text-muted small text-uppercase fw-bold">Detailed Narrative / Description</label>
                                                            <div class="p-3 bg-light border rounded text-dark small mt-1" style="white-space: pre-wrap; word-break: break-word; border-radius: var(--radius-md);"><?php echo htmlspecialchars($row['request_details']); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer border-top bg-light">
                                                        <button type="button" class="btn btn-sm btn-secondary fw-semibold px-4" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-regular fa-folder-open d-block mb-2 fs-3 text-secondary opacity-50"></i>
                                    No <?php echo htmlspecialchars($current_type); ?> entries logged in stream.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Client-side Instant Filter Search
    const searchInput = document.getElementById('streamSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            document.querySelectorAll('#requestsDataTable .stream-row').forEach(row => {
                const name = row.querySelector('.target-name')?.textContent.toLowerCase() || '';
                const details = row.querySelector('.target-details')?.textContent.toLowerCase() || '';
                row.style.display = (name.includes(query) || details.includes(query)) ? '' : 'none';
            });
        });
    }
});
</script>
</body>
</html>