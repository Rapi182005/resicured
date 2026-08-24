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

// Track filter tab selection (default to 'all')
$current_type = isset($_GET['type']) ? $_GET['type'] : 'all';

// FETCH RESIDENT'S INTERNAL PROFILE ID MAPPING
$profile_query = "SELECT id, full_name, house_number FROM residents WHERE user_id = '$resident_user_id'";
$profile_result = $conn->query($profile_query);
$resident = $profile_result->fetch_assoc();
$resident_profile_id = $resident['id'] ?? 0;

// ================= ACTION: PROCESS NEW REQUEST / CONCERN SUBMISSION =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['submit_request_btn']) || isset($_POST['submit_concern_btn']))) {
    $is_concern = isset($_POST['submit_concern_btn']);
    $base_type = $_POST['request_type'] ?? '';
    
    // Capture custom category if "Other" is selected
    if ($base_type === 'Other' && !empty($_POST['custom_request_type'])) {
        $request_type = $conn->real_escape_string(trim($_POST['custom_request_type']));
        // Force 'Concern' keyword if it's a concern submission to align with Admin filter logic
        if ($is_concern && stripos($request_type, 'concern') === false) {
            $request_type .= ' Concern';
        }
    } else {
        $request_type = $conn->real_escape_string(trim($base_type));
    }
    
    // Fallback if empty
    if (empty($request_type)) {
        $request_type = $is_concern ? 'General Concern' : 'General Request';
    }
    
    $description = $conn->real_escape_string($_POST['description']);

    if ($resident_profile_id > 0) {
        $sql = "INSERT INTO requests (requester_name, requester_type, request_details, status) 
                VALUES ('$resident_profile_id', '$request_type', '$description', 'pending')";
                
        if ($conn->query($sql)) {
            $label = $is_concern ? "concern report" : "application form";
            $redirect_type = $is_concern ? "concern" : "request";
            header("Location: requests.php?type=" . $redirect_type . "&success=Your " . urlencode($label) . " has been submitted! Awaiting administrator review.");
            exit();
        } else {
            $error_msg = "Failed to submit: " . $conn->error;
        }
    } else {
        $error_msg = "Profile Error: Household link could not be verified.";
    }
}

if (isset($_GET['success'])) {
    $success_msg = $_GET['success'];
}

// 2. FETCH HISTORICAL SUBMISSIONS BASED ON SELECTED FILTER
$type_filter = "";
if ($current_type === 'concern') {
    $type_filter = " AND LOWER(requester_type) LIKE '%concern%'";
} elseif ($current_type === 'request') {
    $type_filter = " AND (LOWER(requester_type) NOT LIKE '%concern%' OR requester_type IS NULL OR requester_type = '')";
}

$history_query = "SELECT requester_type, request_details, status, created_at 
                  FROM requests 
                  WHERE requester_name = '$resident_profile_id' $type_filter 
                  ORDER BY id DESC";
$history_result = $conn->query($history_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Requests & Concerns</title>
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
        .btn-orange { background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); border: none; color: white; font-weight: 600; font-size: 14px; padding: 12px 20px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .btn-orange:hover { opacity: 0.95; color: white; }
        .btn-outline-danger-custom { background-color: #fff5f5; color: #e53e3e; border: 1px solid #feb2b2; font-weight: 600; font-size: 14px; padding: 12px 20px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; }
        .btn-outline-danger-custom:hover { background-color: #e53e3e; color: white; border-color: #e53e3e; }

        /* Filter Toggle Buttons */
        .filter-btn {
            padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s ease; border: 1px solid #cbd5e1; background-color: #ffffff; color: #4a5568;
        }
        .filter-btn:hover { border-color: var(--subdivision-orange); color: var(--subdivision-orange); }
        .filter-btn.active { background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); color: #ffffff; border-color: transparent; box-shadow: 0 2px 6px rgba(230, 106, 0, 0.2); }

        /* Layout Grid */
        .split-grid { display: grid; grid-template-columns: 300px 1fr; gap: 32px; margin-top: 24px; align-items: start; }
        .panel-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01); box-sizing: border-box; }
        .panel-card-title { color: var(--text-dark); font-size: 15px; font-weight: 700; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; }
        
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
                <li><a href="requests.php" class="nav-link active"><i class="fa fa-file-invoice"></i> File Request & Concern</a></li>
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
            <h1 class="h3 portal-title">Requests & Concerns Center</h1>
            <p class="text-muted small mb-0">Household Owner: <strong><?php echo htmlspecialchars($resident['full_name'] ?? 'Resident'); ?></strong> | House: <strong><?php echo htmlspecialchars($resident['house_number'] ?? 'N/A'); ?></strong></p>
        </div>

        <?php if(!empty($success_msg)): ?>
            <div class="alert alert-success d-flex align-items-center small p-3 mb-4" style="border-radius: 8px;">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i><div><?php echo htmlspecialchars($success_msg); ?></div>
            </div>
        <?php endif; ?>
        <?php if(!empty($error_msg)): ?>
            <div class="alert alert-danger d-flex align-items-center small p-3 mb-4" style="border-radius: 8px;">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i><div><strong>Form Warning:</strong> <?php echo htmlspecialchars($error_msg); ?></div>
            </div>
        <?php endif; ?>

        <div class="split-grid">
            <div class="panel-card text-center py-4">
                <h5 class="panel-card-title text-start pb-3 border-bottom"><i class="fa-solid fa-layer-group me-1.5 text-orange"></i>Actions</h5>
                <p class="text-muted small text-start my-3">File formal requests for permits/documents, or submit neighborhood concerns directly to management.</p>
                
                <div class="d-flex flex-column gap-2">
                    <a href="#newRequestModal" class="btn btn-orange py-2.5 w-100">
                        <i class="fa fa-paper-plane me-2"></i>New Permit Request
                    </a>
                    <a href="#newConcernModal" class="btn btn-outline-danger-custom py-2.5 w-100">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>Report a Concern
                    </a>
                </div>
            </div>

            <div class="panel-card">
                <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                    <h5 class="panel-card-title"><i class="fa-solid fa-clock-rotate-left me-1.5 text-warning"></i>Filed Activity Logs</h5>
                    
                    <!-- FILTER BUTTONS -->
                    <div class="d-inline-flex gap-1">
                        <a href="requests.php?type=all" class="filter-btn <?php echo ($current_type === 'all') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-list"></i> All
                        </a>
                        <a href="requests.php?type=request" class="filter-btn <?php echo ($current_type === 'request') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-file-lines"></i> Requests
                        </a>
                        <a href="requests.php?type=concern" class="filter-btn <?php echo ($current_type === 'concern') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-triangle-exclamation"></i> Concerns
                        </a>
                    </div>
                </div>

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
                                <?php while($row = $history_result->fetch_assoc()): 
                                    $category_text = !empty(trim($row['requester_type'])) ? $row['requester_type'] : 'General Item';
                                    $is_concern_item = (stripos($category_text, 'concern') !== false);
                                    $status_clean = strtolower(trim($row['status'] ?? 'pending'));
                                ?>
                                    <tr>
                                        <td>
                                            <?php if($is_concern_item): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 fw-bold" style="text-transform: uppercase; font-size:11px;">
                                                    <i class="fa-solid fa-triangle-exclamation me-1"></i><?php echo htmlspecialchars($category_text); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border px-2.5 py-1.5 fw-bold" style="text-transform: uppercase; font-size:11px;">
                                                    <i class="fa-solid fa-file-lines me-1"></i><?php echo htmlspecialchars($category_text); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width: 320px; white-space: normal;"><span class="text-secondary small"><?php echo htmlspecialchars($row['request_details']); ?></span></td>
                                        <td class="text-muted small"><?php echo date('M d, Y, g:i A', strtotime($row['created_at'])); ?></td>
                                        <td class="text-end" style="padding-right:20px;">
                                            <?php if($status_clean === 'pending' || empty($status_clean)): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;">Awaiting Review</span>
                                            <?php elseif($status_clean === 'in_progress'): ?>
                                                <span class="badge bg-info-subtle text-info border border-info-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;">In Progress</span>
                                            <?php elseif($status_clean === 'resolved'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;">Resolved</span>
                                            <?php elseif($status_clean === 'approved'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;">Approved</span>
                                            <?php elseif($status_clean === 'rejected'): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;">Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1.5 text-uppercase" style="font-size:10px;"><?php echo htmlspecialchars(ucfirst($status_clean)); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center py-5 text-muted"><i class="fa-regular fa-folder-open d-block mb-2 fs-3 text-secondary"></i>No <?php echo ($current_type !== 'all') ? htmlspecialchars($current_type) : ''; ?> history recorded on file.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 1: PERMIT & DOCUMENT REQUEST -->
<div id="newRequestModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark" style="font-size:18px;"><i class="fa-solid fa-file-signature me-2 text-orange"></i>File Document / Permit Request</h5>
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
                        <option value="Car Sticker Permit">Car Sticker Permit</option>
                        <option value="Other">Other (Specify below...)</option>
                    </select>
                </div>
                
                <div class="mb-3" id="customTypeFieldBlock" style="display: none;">
                    <label class="form-label text-orange"><i class="fa fa-pen-clip me-1"></i>Specify Custom Request Type</label>
                    <input type="text" name="custom_request_type" id="customTypeInput" class="form-control p-2.5" placeholder="e.g., Construction Permit">
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

<!-- MODAL 2: SUBDIVISION CONCERN REPORT -->
<div id="newConcernModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header bg-light">
            <h5 class="m-0 fw-bold text-danger" style="font-size:18px;"><i class="fa-solid fa-triangle-exclamation me-2"></i>Report Subdivision Concern</h5>
            <a href="#" class="close-popup-btn">&times;</a>
        </div>
        <form action="requests.php" method="POST">
            <div class="popup-body">
                <div class="mb-3">
                    <label class="form-label">Concern Classification</label>
                    <select name="request_type" id="concernTypeSelect" class="form-select p-2.5" required>
                        <option value="" disabled selected hidden>Select category of issue...</option>
                        <option value="Noise & Nuisance Concern">Noise / Nuisance Concern</option>
                        <option value="Maintenance Concern">Neighborhood Maintenance Concern</option>
                        <option value="Security Concern">Security & Guard Concern</option>
                        <option value="House / Property Concern">House / Property Issue Concern</option>
                        <option value="Garbage / Sanitation Concern">Garbage & Sanitation Concern</option>
                        <option value="Other">Other Concern (Specify below...)</option>
                    </select>
                </div>
                
                <div class="mb-3" id="customConcernFieldBlock" style="display: none;">
                    <label class="form-label text-danger"><i class="fa fa-pen-clip me-1"></i>Specify Custom Concern Type</label>
                    <input type="text" name="custom_request_type" id="customConcernInput" class="form-control p-2.5" placeholder="e.g., Water Leakage / Stray Animals">
                </div>

                <div class="mb-2">
                    <label class="form-label">Detailed Narrative of the Concern</label>
                    <textarea name="description" rows="4" class="form-control p-2.5" required placeholder="Describe what happened, location, time, or specific details..."></textarea>
                </div>
            </div>
            <div class="popup-footer">
                <a href="#" class="btn-modal-cancel">Cancel</a>
                <button type="submit" name="submit_concern_btn" class="btn btn-danger btn-modal-save fw-bold">Submit Concern</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Request Modal Custom Category Toggle
    const selectElement = document.getElementById('requestTypeSelect');
    const customFieldBlock = document.getElementById('customTypeFieldBlock');
    const customInput = document.getElementById('customTypeInput');

    if (selectElement && customFieldBlock) {
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
    }

    // Concern Modal Custom Category Toggle
    const concernSelect = document.getElementById('concernTypeSelect');
    const customConcernBlock = document.getElementById('customConcernFieldBlock');
    const customConcernInput = document.getElementById('customConcernInput');

    if (concernSelect && customConcernBlock) {
        concernSelect.addEventListener('change', function() {
            if (this.value === 'Other') {
                customConcernBlock.style.display = 'block';
                customConcernInput.required = true;
                customConcernInput.focus();
            } else {
                customConcernBlock.style.display = 'none';
                customConcernInput.required = false;
                customConcernInput.value = '';
            }
        });
    }
});
</script>

</body>
</html>