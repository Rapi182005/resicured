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

// ================= ACTION: PROCESS REQUEST STATUS UPDATES (APPROVE / REJECT) =================
if (isset($_GET['action']) && isset($_GET['id'])) {
    $request_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if ($action === 'approve' || $action === 'reject') {
        $status_value = ($action === 'approve') ? 'approved' : 'rejected';
        
        // MATCHED: Primary key is 'Id' with a capital I based on your schema image
        $sql = "UPDATE requests SET status = '$status_value' WHERE Id = $request_id";
        if ($conn->query($sql)) {
            header("Location: requests.php?success=Request status updated to " . strtoupper($status_value) . " successfully.");
            exit();
        } else {
            $error_msg = "Database Error: Failed to execute status modification logic.";
        }
    }
}

if (isset($_GET['success'])) { $success_msg = $_GET['success']; }

// 2. FETCH ALL REQUESTS ROWS MATCHED TO YOUR EXACT COLUMNS: Id, requester_name, requester_type, request_details
$requests_query = "SELECT req.Id as request_id, req.requester_type, req.request_details, req.status, req.created_at, res.full_name, res.house_number 
                   FROM requests req
                   JOIN residents res ON req.requester_name = res.id 
                   ORDER BY req.status = 'pending' DESC, req.Id DESC";
$requests_result = $conn->query($requests_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Request Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/theme/bootstrap.min.css" rel="stylesheet">
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

        /* Left Sidebar Layout */
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

        /* Main Workspace Content Area */
        .main-content { flex-grow: 1; padding: 40px; background-color: var(--bg-light); box-sizing: border-box; }
        .page-title { color: var(--text-dark); font-weight: 700; letter-spacing: -0.5px; margin: 0; }

        /* Table Architecture Styles */
        .table-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01); }
        .custom-table { width: 100%; margin-bottom: 0; }
        .custom-table th { background-color: #f8fafc; color: #718096; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding: 12px 16px; }
        .custom-table td { color: var(--text-dark); font-size: 14px; padding: 16px; vertical-align: middle; border-bottom: 1px solid #edf2f7; }
        
        .action-link { padding: 6px 12px; border-radius: 6px; font-size: 13px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; transition: opacity 0.15s; }
        .btn-approve { background-color: #dcfce7; color: #15803d; margin-right: 6px; }
        .btn-approve:hover { background-color: #bbf7d0; }
        .btn-reject { background-color: #fee2e2; color: #dc2626; }
        .btn-reject:hover { background-color: #fca5a5; }
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
                <li><a href="residents.php" class="nav-link"><i class="fa fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link"><i class="fa fa-user-shield"></i> Personnel</a></li>
                <li><a href="requests.php" class="nav-link active"><i class="fa fa-file-alt"></i> Requests</a></li>
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
                <h1 class="h3 page-title">Residents Request Stream</h1>
                <p class="text-muted small mb-0">Review facility permit requests, gate clearance certificates, and amenities permissions.</p>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success d-flex align-items-center small p-3 mb-4" style="border-radius:8px;">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i> <div><?php echo $success_msg; ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger d-flex align-items-center small p-3 mb-4" style="border-radius:8px;">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i> <div><strong>Stream Exception:</strong> <?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <div class="table-card">
            <div class="table-responsive">
                <table class="table custom-table" id="requestsDataTable">
                    <thead>
                        <tr>
                            <th>Submitting Household</th>
                            <th>Request Category</th>
                            <th>Detailed Narrative / Description</th>
                            <th>Date Filed</th>
                            <th>Current State</th>
                            <th class="text-end" style="padding-right:24px;">Action Controls</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($requests_result && $requests_result->num_rows > 0): ?>
                            <?php while($row = $requests_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                        <small class="text-muted fw-bold"><i class="fa fa-home me-1"></i><?php echo htmlspecialchars($row['house_number']); ?></small>
                                    </td>
                                    <td><span class="badge bg-light text-dark border px-2.5 py-1.5 fw-semibold" style="font-size:12px; text-transform: uppercase;"><?php echo htmlspecialchars($row['requester_type']); ?></span></td>
                                    <td style="max-width:300px; white-space: normal;"><span class="small text-secondary"><?php echo htmlspecialchars($row['request_details']); ?></span></td>
                                    <td class="text-secondary small"><?php echo date('M d, Y, g:i A', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <?php if($row['status'] == 'pending'): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2.5 py-1.5" style="font-size:11px; text-transform: uppercase;">Awaiting Review</span>
                                        <?php elseif($row['status'] == 'approved'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5" style="font-size:11px; text-transform: uppercase;">Approved</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5" style="font-size:11px; text-transform: uppercase;">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" style="padding-right:24px;">
                                        <?php if($row['status'] == 'pending'): ?>
                                            <a href="requests.php?action=approve&id=<?php echo $row['request_id']; ?>" onclick="return confirm('Grant formal system approval for this request?');" class="action-link btn-approve"><i class="fa fa-circle-check"></i> Approve</a>
                                            <a href="requests.php?action=reject&id=<?php echo $row['request_id']; ?>" onclick="return confirm('Formally decline this application request?');" class="action-link btn-reject"><i class="fa fa-circle-xmark"></i> Reject</a>
                                        <?php else: ?>
                                            <span class="text-muted small fw-semibold"><i class="fa-solid fa-lock text-secondary me-1"></i> Form Locked</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fa-regular fa-folder-open d-block mb-2 fs-3 text-secondary"></i>No resident tickets or request documents logged in stream.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>