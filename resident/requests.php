<?php 
session_start();

// 1. SECURITY GATEWAY: Ensure only logged-in Residents can access this file
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

$resident_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// FETCH RESIDENT'S INTERNAL PROFILE ID MAPPING
$profile_query = "SELECT id, full_name, house_number FROM residents WHERE user_id = '$resident_user_id'";
$profile_result = $conn->query($profile_query);
$resident = $profile_result->fetch_assoc();
$resident_profile_id = $resident['id'] ?? 0;

// ================= ACTION: PROCESS NEW DOCUMENT REQUEST SUBMISSION =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request_btn'])) {
    $base_type = $_POST['request_type'] ?? '';
    
    // Capture custom category if "Other" is selected
    if ($base_type === 'Other' && !empty($_POST['custom_request_type'])) {
        $request_type = $conn->real_escape_string(trim($_POST['custom_request_type']));
    } else {
        $request_type = $conn->real_escape_string(trim($base_type));
    }
    
    // Fallback if empty
    if (empty($request_type)) {
        $request_type = 'General Request';
    }
    
    $description = $conn->real_escape_string($_POST['description']);

    if ($resident_profile_id > 0) {
        $sql = "INSERT INTO requests (requester_name, requester_type, request_details, status) 
                VALUES ('$resident_profile_id', '$request_type', '$description', 'pending')";
                
        if ($conn->query($sql)) {
            $success_msg = "Your application form has been submitted! Awaiting administrator review.";
        } else {
            $error_msg = "Failed to submit request: " . $conn->error;
        }
    } else {
        $error_msg = "Profile Error: Household link could not be verified.";
    }
}

// 2. FETCH ALL HISTORICAL SUBMISSIONS FOR THIS HOUSEHOLD
$history_query = "SELECT requester_type, request_details, status, created_at 
                  FROM requests 
                  WHERE requester_name = '$resident_profile_id' 
                  ORDER BY Id DESC";
$history_result = $conn->query($history_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Document Requests</title>
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

        /* Left Hand Sidebar Layout */
        .sidebar {
            width: 260px; min-width: 260px; background-color: #ffffff; border-right: 1px solid #e2e8f0; padding-top: 24px;
            display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box;
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

        /* Content Workspace */
        .main-content { flex-grow: 1; padding: 40px; box-sizing: border-box; background-color: var(--bg-light); }
        .portal-title { color: var(--text-dark); font-weight: 700; letter-spacing: -0.5px; margin: 0; }
        .btn-orange { background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); border: none; color: white; font-weight: 600; font-size: 14px; padding: 12px 20px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
        .btn-orange:hover { opacity: 0.95; color: white; }

        /* Layout Grid */
        .split-grid { display: grid; grid-template-columns: 280px 1fr; gap: 32px; margin-top: 24px; align-items: start; }
        .panel-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01); box-sizing: border-box; }
        .panel-card-title { color: var(--text-dark); font-size: 15px; font-weight: 700; margin: 0 0 20px 0; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #edf2f7; padding-bottom: 12px; }
        
        .form-control, .form-select { font-size: 14px; border: 1px solid #cbd5e1; border-radius: 8px; }
        .form-control:focus, .form-select:focus { border-color: var(--subdivision-orange); box-shadow: 0 0 0 3px rgba(230, 106, 0, 0.1); }
        .form-label { display: block; margin-bottom: 6px; font-size: 13px; color: #4a5568; font-weight: 600; }

        .custom-table { width: 100%; margin-bottom: 0; }
        .custom-table th { background-color: #f8fafc; color: #718096; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding: 12px 16px; }
        .custom-table td { color: var(--text-dark); font-size: 14px; padding: 16px; vertical-align: middle; border-bottom: 1px solid #edf2f7; }

        /* Pop-up Overlay */
        .custom-modal-backdrop { 
            position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; 
            background-color: rgba(30, 34, 41, 0.6) !important; z-index: 99999 !important; 
            display: none; align-items: center; justify-content: center; padding: 20px; 
        }
        .custom-modal-backdrop:target { display: flex !important; }
        .custom-popup-window { background-color: #ffffff !important; width: 100% !important; max-width: 480px !important; border-radius: 14px !important; box-shadow: 0 15px 35px rgba(0,0,0,0.2) !important; overflow: hidden; animation: popIn 0.2s ease-out; }
        
        @keyframes popIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .popup-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid #edf2f7; }
        .popup-body { padding: 24px; box-sizing: border-box; }
        .popup-footer { padding: 16px 24px; background-color: #f8fafc; border-top: 1px solid #edf2f7; display: flex; justify-content: flex-end; gap: 10px; }
        .close-popup-btn { text-decoration: none; color: #a0aec0; font-size: 20px; font-weight: bold; line-height: 1; }
        
        .btn-modal-cancel { background-color: #edf2f7; color: #4a5568; border: 1px solid #cbd5e1; padding: 9px 18px; font-size: 14px; font-weight: 600; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-modal-save { padding: 9px 22px; font-size: 14px; }
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
                <li><a href="dashboard.php" class="nav-link"><i class="fa fa-chart-pie"></i> My Dashboard</a></li>
                <li><a href="dashboard.php#requestPassModal" class="nav-link"><i class="fa fa-qrcode"></i> Create QR Pass</a></li>
                <li><a href="requests.php" class="nav-link active"><i class="fa fa-file-invoice"></i> File Request</a></li>
                <li><a href="resident_billing.php" class="nav-link"><i class="fa fa-credit-card"></i> My Billings</a></li>
            </ul>
        </div>
        <div class="logout-btn-container">
            <hr class="mx-3 text-muted">
            <a href="../logout.php" class="nav-link logout-btn"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main-content">
        <div class="pb-3 mb-4 border-bottom">
            <h1 class="h3 portal-title">Document & Permit Hub</h1>
            <p class="text-muted small mb-0">Household Owner: <strong><?php echo htmlspecialchars($resident['full_name'] ?? 'Resident'); ?></strong> | House: <strong><?php echo htmlspecialchars($resident['house_number'] ?? 'N/A'); ?></strong></p>
        </div>

        <?php if(!empty($success_msg)): ?>
            <div class="alert alert-success d-flex align-items-center small p-3 mb-4" style="border-radius: 8px;">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i><div><?php echo $success_msg; ?></div>
            </div>
        <?php endif; ?>
        <?php if(!empty($error_msg)): ?>
            <div class="alert alert-danger d-flex align-items-center small p-3 mb-4" style="border-radius: 8px;">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i><div><strong>Form Warning:</strong> <?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <div class="split-grid">
            <div class="panel-card text-center py-4">
                <h5 class="panel-card-title text-start"><i class="fa-solid fa-file-circle-plus me-1.5 text-orange"></i>Actions</h5>
                <p class="text-muted small text-start mb-4">File formal requests for neighborhood clearances, document copies, or facilities keys.</p>
                <a href="#newRequestModal" class="btn btn-orange py-2.5 w-100"><i class="fa fa-paper-plane me-2"></i>New Application</a>
            </div>

            <div class="panel-card">
                <h5 class="panel-card-title"><i class="fa-solid fa-clock-rotate-left me-1.5 text-warning"></i>Filed Request Logs</h5>
                <div class="table-responsive">
                    <table class="table custom-table">
                        <thead>
                            <tr>
                                <th>Classification Category</th>
                                <th>Narrative / Specifications</th>
                                <th>Date Filed</th>
                                <th class="text-end" style="padding-right:20px;">Current State</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history_result && $history_result->num_rows > 0): ?>
                                <?php while($row = $history_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2.5 py-1.5 fw-bold" style="text-transform: uppercase; font-size:11px;">
                                                <?php echo !empty(trim($row['requester_type'])) ? htmlspecialchars($row['requester_type']) : 'General Request'; ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 320px; white-space: normal;"><span class="text-secondary small"><?php echo htmlspecialchars($row['request_details']); ?></span></td>
                                        <td class="text-muted small"><?php echo date('M d, Y, g:i A', strtotime($row['created_at'])); ?></td>
                                        <td class="text-end" style="padding-right:20px;">
                                            <?php if($row['status'] == 'pending'): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;">Awaiting Review</span>
                                            <?php elseif($row['status'] == 'approved'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;">Approved</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center py-5 text-muted"><i class="fa-regular fa-folder-open d-block mb-2 fs-3 text-secondary"></i>No application history recorded on file.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="newRequestModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark" style="font-size:18px;"><i class="fa-solid fa-file-signature me-2 text-orange"></i>File Application Form</h5>
            <a href="#" class="close-popup-btn">&times;</a>
        </div>
        <form action="requests.php" method="POST">
            <div class="popup-body">
                <div class="mb-3">
                    <label class="form-label">Request Type</label>
                    <select name="request_type" id="requestTypeSelect" class="form-select p-2.5" required>
                        <option value="" disabled selected hidden>Select document category...</option>
                        <option value="Certificate of Residency">Certificate of Residency</option>
                        <option value="Gate Pass Clearance">Gate Pass Clearance / RFID</option>
                        <option value="Clubhouse Reservation">Facility / Clubhouse Reservation</option>
                        <option value="Maintenance Ticket">Neighborhood Maintenance Ticket</option>
                        <option value="Other">Other (Specify below...)</option>
                    </select>
                </div>
                
                <div class="mb-3" id="customTypeFieldBlock" style="display: none;">
                    <label class="form-label text-danger"><i class="fa fa-pen-clip me-1"></i>Specify Custom Request Type</label>
                    <input type="text" name="custom_request_type" id="customTypeInput" class="form-control p-2.5" placeholder="e.g., Water Leaking Repair">
                </div>

                <div class="mb-2">
                    <label class="form-label">Purpose / Additional Details</label>
                    <textarea name="description" rows="4" class="form-control p-2.5" required placeholder="Provide narrative explanation purpose..."></textarea>
                </div>
            </div>
            <div class="popup-footer">
                <a href="#" class="btn-modal-cancel">Cancel</a>
                <button type="submit" name="submit_request_btn" class="btn btn-orange btn-modal-save">Submit Application</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const selectElement = document.getElementById('requestTypeSelect');
    const customFieldBlock = document.getElementById('customTypeFieldBlock');
    const customInput = document.getElementById('customTypeInput');

    if (!selectElement || !customFieldBlock) return;

    selectElement.addEventListener('change', function() {
        if (this.value === 'Other') {
            customFieldBlock.style.display = 'block';
            customInput.required = true;
            customInput.focus();
        } else {
            customFieldBlock.style.display = 'none';
            customInput.required = false;
            customInput.value = '';
        }
    });
});
</script>

</body>
</html>