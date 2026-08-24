<?php 
session_start();

// 1. SECURITY GATEWAY
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Request & Concern Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --subdivision-orange: #e66a00;
            --subdivision-amber: #ffaa00;
            --text-dark: #2d3748;
            --bg-light: #f8fafc;
        }

        body { background-color: var(--bg-light); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 0; }
        .page-wrapper { display: flex; min-height: 100vh; width: 100%; }

        .sidebar {
            width: 260px; min-width: 260px; background-color: #ffffff; border-right: 1px solid #e2e8f0; padding-top: 24px;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .brand-logo-area { padding: 0 24px 20px 24px; display: flex; align-items: center; gap: 12px; }
        .brand-logo-icon { color: var(--subdivision-orange); font-size: 1.6rem; }
        .brand-logo-text { color: var(--text-dark); font-size: 20px; font-weight: 700; letter-spacing: -0.5px; margin: 0; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar .nav-link { color: #4a5568; font-size: 14px; font-weight: 500; padding: 12px 20px; margin: 4px 16px; border-radius: 8px; display: flex; align-items: center; text-decoration: none; transition: all 0.2s ease; }
        .sidebar .nav-link:hover { color: var(--subdivision-orange); background-color: rgba(230, 106, 0, 0.05); }
        .sidebar .nav-link.active { color: #ffffff; background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); font-weight: 600; }
        .sidebar .nav-link i { font-size: 16px; width: 28px; }
        .logout-btn-container { padding-bottom: 24px; }
        .logout-btn { background-color: #fff5f5; color: #c53030 !important; border: 1px solid #fed7d7; }
        .logout-btn:hover { background-color: #e53e3e !important; color: #ffffff !important; }

        .main-content { flex-grow: 1; padding: 40px; background-color: var(--bg-light); box-sizing: border-box; }
        .page-title { color: var(--text-dark); font-weight: 700; letter-spacing: -0.5px; margin: 0; }

        .filter-btn {
            padding: 8px 18px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; border: 1px solid #e2e8f0; background-color: #ffffff; color: #4a5568;
        }
        .filter-btn:hover { border-color: var(--subdivision-orange); color: var(--subdivision-orange); }
        .filter-btn.active { background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); color: #ffffff; border-color: transparent; box-shadow: 0 2px 6px rgba(230, 106, 0, 0.25); }

        .table-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01); }
        .custom-table { width: 100%; margin-bottom: 0; }
        .custom-table th { background-color: #f8fafc; color: #718096; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding: 12px 16px; }
        .custom-table td { color: var(--text-dark); font-size: 14px; padding: 16px; vertical-align: middle; border-bottom: 1px solid #edf2f7; }
        
        .action-link { padding: 6px 12px; border-radius: 6px; font-size: 13px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; border: none; }
        .btn-view { background-color: #eff6ff; color: #2563eb; cursor: pointer; }
        .btn-view:hover { background-color: #dbeafe; color: #1d4ed8; }
        .btn-approve { background-color: #dcfce7; color: #15803d; }
        .btn-reject { background-color: #fee2e2; color: #dc2626; }
        
        .status-badge { font-size: 11px; font-weight: 700; padding: 6px 12px; border-radius: 6px; letter-spacing: 0.3px; text-transform: uppercase; display: inline-block; }
        .status-badge-pending { background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .status-badge-progress { background-color: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
        .status-badge-resolved { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-badge-closed { background-color: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
        .status-badge-rejected { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>

<div class="page-wrapper">
    <div class="sidebar">
        <div>
            <div class="brand-logo-area border-bottom">
                <i class="fa fa-shield-halved brand-logo-icon"></i>
                <h4 class="brand-logo-text">ResiCured</h4>
            </div>
            <ul class="sidebar-menu mt-3">
                <li><a href="dashboard.php" class="nav-link"><i class="fa fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="events.php" class="nav-link"><i class="fa fa-calendar-alt"></i> Events</a></li>
                <li><a href="residents.php" class="nav-link"><i class="fa fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link"><i class="fa fa-user-shield"></i> Personnel</a></li>
                <li><a href="requests.php" class="nav-link active"><i class="fa fa-file-alt"></i> Requests & Concerns</a></li>
                <li><a href="billing.php" class="nav-link"><i class="fa fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link"><i class="fa fa-money-bill-transfer"></i> Expenses</a></li>
                <li><a href="guards.php" class="nav-link"><i class="fa fa-user-lock"></i> Staff Guards</a></li>
            </ul>
        </div>
        <div class="logout-btn-container">
            <hr class="mx-3 text-muted">
            <a href="../logout.php" class="nav-link logout-btn"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 page-title"><?php echo ($current_type === 'concern') ? 'Residents Concern Stream' : 'Residents Request Stream'; ?></h1>
                <p class="text-muted small mb-0">
                    <?php echo ($current_type === 'concern') ? 'Review house issues, noise complaints, and subdivision maintenance concerns.' : 'Review facility permit requests, gate clearance certificates, and amenities permissions.'; ?>
                </p>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 mb-4">
            <a href="requests.php?type=request" class="filter-btn <?php echo ($current_type === 'request') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-lines"></i> Requests Review
            </a>
            <a href="requests.php?type=concern" class="filter-btn <?php echo ($current_type === 'concern') ? 'active' : ''; ?>">
                <i class="fa-solid fa-triangle-exclamation"></i> Concerns Review
            </a>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center small p-3 mb-4" id="flashAlert" role="alert" style="border-radius:8px;">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i> 
                <div><?php echo htmlspecialchars($success_msg); ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center small p-3 mb-4" id="flashAlert" role="alert" style="border-radius:8px;">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i> 
                <div><strong>Stream Exception:</strong> <?php echo htmlspecialchars($error_msg); ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="table-card">
            <div class="table-responsive">
                <table class="table custom-table" id="requestsDataTable">
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
                        <?php if ($requests_result && $requests_result->num_rows > 0): ?>
                            <?php while($row = $requests_result->fetch_assoc()): 
                                $modal_id = "viewRequestModal_" . $row['request_id'];
                                $req_category = !empty(trim($row['requester_type'])) ? $row['requester_type'] : (($current_type === 'concern') ? 'House Concern' : 'General Request');
                                $raw_status = trim($row['status'] ?? '');
                                $status_clean = !empty($raw_status) ? strtolower($raw_status) : 'pending';
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                        <small class="text-muted fw-bold"><i class="fa fa-home me-1"></i><?php echo htmlspecialchars($row['house_number']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1 fw-semibold" style="font-size:12px; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($req_category); ?>
                                        </span>
                                    </td>
                                    <td style="max-width:300px; white-space: normal;"><span class="small text-secondary"><?php echo htmlspecialchars($row['request_details']); ?></span></td>
                                    <td class="text-secondary small"><?php echo date('M d, Y, g:i A', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <?php 
                                        if ($status_clean === 'pending') {
                                            echo '<span class="status-badge status-badge-pending">Awaiting Review</span>';
                                        } elseif ($status_clean === 'in_progress') {
                                            echo '<span class="status-badge status-badge-progress">In Progress</span>';
                                        } elseif ($status_clean === 'resolved') {
                                            echo '<span class="status-badge status-badge-resolved">Resolved</span>';
                                        } elseif ($status_clean === 'approved') {
                                            echo '<span class="status-badge status-badge-resolved">Approved</span>';
                                        } elseif ($status_clean === 'rejected') {
                                            echo '<span class="status-badge status-badge-rejected">Rejected</span>';
                                        } elseif ($status_clean === 'dismissed' || $status_clean === 'closed') {
                                            echo '<span class="status-badge status-badge-closed">Closed</span>';
                                        } else {
                                            echo '<span class="status-badge status-badge-pending">Awaiting Review</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end" style="padding-right:20px;">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <button class="action-link btn-view" data-bs-toggle="modal" data-bs-target="#<?php echo $modal_id; ?>">
                                                <i class="fa-solid fa-eye me-1"></i> View
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
                                                        <i class="fa-solid fa-circle-check me-1"></i> Approve
                                                    </a>
                                                    <a href="requests.php?type=request&action=reject&id=<?php echo $row['request_id']; ?>" 
                                                       class="action-link btn-reject"
                                                       onclick="return confirm('Decline this request?');">
                                                        <i class="fa-solid fa-circle-xmark me-1"></i> Reject
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
                                    </td>
                                </tr>

                                <div class="modal fade" id="<?php echo $modal_id; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow">
                                            <div class="modal-header border-bottom bg-light">
                                                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-file-invoice text-warning me-2"></i>Record Details</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body p-4 text-start">
                                                <div class="mb-3">
                                                    <label class="text-muted small text-uppercase fw-bold">Submitting Resident</label>
                                                    <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                                    <div class="small text-secondary"><i class="fa fa-home me-1"></i><?php echo htmlspecialchars($row['house_number']); ?></div>
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
                                                    <div class="p-3 bg-light border rounded text-dark small" style="white-space: pre-wrap; word-break: break-word;"><?php echo htmlspecialchars($row['request_details']); ?></div>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-top bg-light">
                                                <button type="button" class="btn btn-sm btn-secondary fw-semibold px-3" data-bs-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fa-regular fa-folder-open d-block mb-2 fs-3 text-secondary"></i>No <?php echo htmlspecialchars($current_type); ?> logged in stream.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>