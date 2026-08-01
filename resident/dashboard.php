<?php 
session_start();

// 1. SECURITY GATEWAY: Ensure only logged-in Residents can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

$resident_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// FETCH RESIDENT'S HOUSEHOLD PROFILE DETAILS
$profile_query = "SELECT id, full_name, house_number FROM residents WHERE user_id = '$resident_user_id'";
$profile_result = $conn->query($profile_query);
$resident = $profile_result->fetch_assoc();
$resident_profile_id = $resident['id'] ?? 0;

// 2. PROCESS NEW VISITOR PASS REQUEST
if (isset($_POST['generate_pass_btn'])) {
    $visitor_name = $conn->real_escape_string($_POST['visitor_name']);
    $visit_date = $conn->real_escape_string($_POST['visit_date']);
    
    // Create a uniquely encrypted cryptographic token for the QR code representation
    $qr_token = "RES-" . $resident_profile_id . "-" . time() . "-" . rand(1000, 9999);

    $sql = "INSERT INTO visitors (resident_id, visitor_name, qr_code_token, visit_date, status) 
            VALUES ('$resident_user_id', '$visitor_name', '$qr_token', '$visit_date', 'approved')";
            
    if ($conn->query($sql)) {
        $success_msg = "Visitor pass authorized! Send this secure token code to your visitor: <strong>$qr_token</strong>";
    } else {
        $error_msg = "Failed to generate security pass. Please try again.";
    }
}

// PROCESS UPDATE VISITOR PASS
if (isset($_POST['update_pass_btn'])) {
    $edit_id = $conn->real_escape_string($_POST['edit_visitor_id']);
    $edit_name = $conn->real_escape_string($_POST['edit_visitor_name']);
    $edit_date = $conn->real_escape_string($_POST['edit_visit_date']);
    
    $upd_sql = "UPDATE visitors SET visitor_name = '$edit_name', visit_date = '$edit_date' WHERE id = '$edit_id' AND resident_id = '$resident_user_id'";
    if ($conn->query($upd_sql)) {
        $success_msg = "Visitor pass successfully updated!";
    } else {
        $error_msg = "Failed to update visitor pass.";
    }
}

// PROCESS DELETE VISITOR PASS
if (isset($_GET['delete_id'])) {
    $delete_id = $conn->real_escape_string($_GET['delete_id']);
    $del_sql = "DELETE FROM visitors WHERE id = '$delete_id' AND resident_id = '$resident_user_id'";
    if ($conn->query($del_sql)) {
        $success_msg = "Visitor pass successfully deleted!";
    } else {
        $error_msg = "Failed to delete visitor pass.";
    }
}

// 3. FETCH ACTIVE VISITOR PASSES CREATED BY THIS RESIDENT
$visitors_result = $conn->query("SELECT id, visitor_name, qr_code_token, visit_date, status FROM visitors WHERE resident_id = '$resident_user_id' ORDER BY visit_date DESC LIMIT 5");

// 4. FETCH THE RESIDENT'S UNPAID MONTHLY BILLS
$billing_result = $conn->query("SELECT amount, billing_month, due_date FROM billings WHERE resident_id = '$resident_profile_id' AND status = 'unpaid' ORDER BY due_date ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Resident Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root { --subdivision-orange: #e66a00; --subdivision-amber: #ffaa00; --text-dark: #2d3748; --bg-light: #f8fafc; }
        body { background-color: var(--bg-light); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        .page-wrapper { display: flex; flex-direction: row; min-height: 100vh; width: 100%; }
        .sidebar { width: 260px; min-width: 260px; background-color: #ffffff; border-right: 1px solid #e2e8f0; padding-top: 24px; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box; }
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
        .main-content { flex-grow: 1; padding: 40px; box-sizing: border-box; background-color: var(--bg-light); }
        .portal-title { color: var(--text-dark); font-weight: 700; letter-spacing: -0.5px; margin: 0; }
        .btn-orange { background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); border: none; color: white; font-weight: 600; font-size: 14px; padding: 12px 20px; border-radius: 6px; }
        .content-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-sizing: border-box; height: 100%; }
        .content-card-title { color: var(--text-dark); font-size: 16px; font-weight: 600; margin: 0 0 20px 0; }
        .workspace-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 24px; }
        
        /* Modal Design Fixes */
        .custom-modal-backdrop { position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; background-color: rgba(30, 34, 41, 0.6) !important; z-index: 99999 !important; display: none; align-items: center; justify-content: center; padding: 20px; }
        .custom-modal-backdrop:target { display: flex !important; }
        .custom-popup-window { background-color: #ffffff !important; width: 100% !important; max-width: 460px !important; border-radius: 14px !important; box-shadow: 0 15px 35px rgba(0,0,0,0.2) !important; overflow: hidden; }
        .popup-header { padding: 20px 24px !important; border-bottom: 1px solid #edf2f7 !important; display: flex !important; justify-content: space-between !important; align-items: center !important; }
        .popup-body { padding: 24px !important; }
        .popup-footer { padding: 16px 24px !important; background-color: #f8fafc !important; border-top: 1px solid #edf2f7 !important; display: flex !important; justify-content: flex-end !important; gap: 10px !important; }
        .close-popup-btn { font-size: 24px !important; color: #a0aec0 !important; text-decoration: none !important; }
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
                <li><a href="dashboard.php" class="nav-link active"><i class="fa fa-chart-pie"></i> My Dashboard</a></li>
                <li><a href="#requestPassModal" class="nav-link"><i class="fa fa-qrcode"></i> Create QR Pass</a></li>
                <li><a href="requests.php" class="nav-link"><i class="fa fa-file-invoice"></i> File Request</a></li>
                <li><a href="resident_billing.php" class="nav-link"><i class="fa fa-credit-card"></i> My Billings</a></li>
            </ul>
        </div>
        <div class="logout-btn-container"><hr class="mx-3 text-muted"><a href="../logout.php" class="nav-link logout-btn"><i class="fa fa-sign-out-alt"></i> Logout</a></div>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 portal-title">Hello, <?php echo htmlspecialchars($resident['full_name'] ?? 'Resident'); ?></h1>
                <p class="text-muted small mb-0">House Location Reference: <strong><?php echo htmlspecialchars($resident['house_number'] ?? 'N/A'); ?></strong></p>
            </div>
            <a href="#requestPassModal" class="btn btn-orange"><i class="fa fa-qrcode me-2"></i> New Visitor Pass</a>
        </div>

        <?php if(!empty($success_msg)): ?>
            <div class="alert alert-success d-flex align-items-center small p-3 mb-4" style="border-radius: 8px;">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i><div><?php echo $success_msg; ?></div>
            </div>
        <?php endif; ?>
        <?php if(!empty($error_msg)): ?>
            <div class="alert alert-danger d-flex align-items-center small p-3 mb-4" style="border-radius: 8px;">
                <i class="fa-solid fa-circle-xmark me-2 fs-5"></i><div><?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <div class="workspace-grid">
            <div class="content-card">
                <h5 class="content-card-title"><i class="fa fa-history me-2" style="color: var(--subdivision-orange);"></i> My Authorized Visitor Passes</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:14px;">
                        <thead class="table-light" style="font-size:11px; text-transform:uppercase; color:#718096;">
                            <tr><th>Visitor Guest Name</th><th>Scheduled Date</th><th>Pass Token ID</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($visitors_result && $visitors_result->num_rows > 0): ?>
                                <?php while($pass = $visitors_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($pass['visitor_name']); ?></strong></td>
                                        <td><?php echo date('M d, Y', strtotime($pass['visit_date'])); ?></td>
                                        <td><code class="text-secondary" style="font-size:12px;"><?php echo htmlspecialchars($pass['qr_code_token']); ?></code></td>
                                        <td><span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 text-uppercase" style="font-size:10px;"><?php echo $pass['status']; ?></span></td>
                                        <td>
                                            <a href="#viewQrModal" class="btn btn-sm btn-outline-warning" style="padding: 2px 8px;" onclick="viewSavedQR('<?php echo htmlspecialchars($pass['qr_code_token']); ?>', '<?php echo addslashes(htmlspecialchars($pass['visitor_name'])); ?>')"><i class="fa fa-qrcode"></i> View</a>
                                            <a href="#editPassModal" class="btn btn-sm btn-outline-primary" style="padding: 2px 8px;" onclick="openEditModal(<?php echo $pass['id']; ?>, '<?php echo addslashes(htmlspecialchars($pass['visitor_name'])); ?>', '<?php echo $pass['visit_date']; ?>')"><i class="fa fa-edit"></i></a>
                                            <a href="dashboard.php?delete_id=<?php echo $pass['id']; ?>" class="btn btn-sm btn-outline-danger" style="padding: 2px 8px;" onclick="return confirm('Are you sure you want to delete this visitor pass?')"><i class="fa fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No active visitor passes registered.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="content-card">
                <h5 class="content-card-title"><i class="fa fa-credit-card me-2" style="color: var(--subdivision-amber);"></i> Pending Subdivision Dues</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:14px;">
                        <thead class="table-light" style="font-size:11px; text-transform:uppercase; color:#718096;">
                            <tr>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($billing_result && $billing_result->num_rows > 0): ?>
                                <?php while($bill = $billing_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($bill['billing_month']); ?></strong></td>
                                        <td class="text-danger fw-bold">₱<?php echo number_format($bill['amount'], 2); ?></td>
                                        <td class="small text-muted"><?php echo date('M d, Y', strtotime($bill['due_date'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">
                                        <i class="fa-solid fa-circle-check text-success d-block fs-4 mb-2"></i>
                                        All balances cleared! No pending dues.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div> 
    </div> 
</div> 

<div id="requestPassModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-square-plus me-2 text-orange" style="color:var(--subdivision-orange);"></i>Authorize New Gate Pass</h5>
            <a href="#" class="close-popup-btn">&times;</a>
        </div>
        <form id="passForm" action="dashboard.php" method="POST">
            <div class="popup-body">
                <div class="mb-3">
                    <label class="form-label">Visitor / Guest Full Name</label>
                    <input type="text" name="visitor_name" id="visitor_name_field" class="form-control" required placeholder="e.g., Maria Santos">
                </div>
                <div class="mb-2">
                    <label class="form-label">Scheduled Arrival Date</label>
                    <input type="date" name="visit_date" id="visit_date_field" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="popup-footer">
                <a href="#" class="btn btn-secondary btn-sm px-3 py-2 text-dark" style="background-color:#edf2f7; border:none;">Cancel</a>
                <button type="submit" name="generate_pass_btn" class="btn btn-orange btn-sm px-4 py-2">Confirm & Save Pass</button>
            </div>
        </form>
    </div>
</div>

<div id="viewQrModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-qrcode me-2 text-warning"></i>Active Visitor Gate Pass</h5>
            <a href="#" class="close-popup-btn">&times;</a>
        </div>
        <div class="popup-body text-center">
            <p class="mb-2 small">Scan this code at the subdivision guard terminal gate for entry approval:</p>
            <h4 id="lblQrGuestName" class="fw-bold text-dark mb-3">-</h4>
            <div style="background: #f8fafc; padding: 20px; border-radius: 8px; inline-block;">
                <img id="liveQrCodeImg" src="" alt="Live Gate Pass QR" style="width: 200px; height: 200px; border: 1px solid #e2e8f0; padding: 4px; background:#ffffff;">
            </div>
            <p class="small text-muted mt-3 mb-1">Database String Token ID Reference:</p>
            <code id="lblQrTokenString" class="text-danger font-monospace" style="font-size:13px;">-</code>
        </div>
        <div class="popup-footer">
            <a href="#" class="btn btn-secondary btn-sm px-4 py-2">Close Window</a>
        </div>
    </div>
</div>

<div id="editPassModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-pen-to-square me-2" style="color: var(--subdivision-orange);"></i>Edit Gate Pass</h5>
            <a href="#" class="close-popup-btn">&times;</a>
        </div>
        <form action="dashboard.php" method="POST">
            <input type="hidden" name="edit_visitor_id" id="edit_visitor_id_field">
            <div class="popup-body">
                <div class="mb-3">
                    <label class="form-label">Visitor / Guest Full Name</label>
                    <input type="text" name="edit_visitor_name" id="edit_visitor_name_field" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">Scheduled Arrival Date</label>
                    <input type="date" name="edit_visit_date" id="edit_visit_date_field" class="form-control" required>
                </div>
            </div>
            <div class="popup-footer">
                <a href="#" class="btn btn-secondary btn-sm px-3 py-2 text-dark" style="background-color:#edf2f7; border:none;">Cancel</a>
                <button type="submit" name="update_pass_btn" class="btn btn-orange btn-sm px-4 py-2">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function viewSavedQR(dbToken, guestName) {
    document.getElementById('lblQrGuestName').textContent = guestName;
    document.getElementById('lblQrTokenString').textContent = dbToken;
    
    const cleanUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(dbToken)}`;
    document.getElementById('liveQrCodeImg').src = cleanUrl;
}

function openEditModal(id, name, date) {
    document.getElementById('edit_visitor_id_field').value = id;
    document.getElementById('edit_visitor_name_field').value = name;
    document.getElementById('edit_visit_date_field').value = date;
}
</script>

</body>
</html>