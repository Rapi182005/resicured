<?php 
session_start();

// SECURITY CHECK
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

$success_msg = "";
$error_msg = "";

// ================= ACTION: PROCESS NEW VISITOR/PERSONNEL ENROLLMENT =================
if (isset($_POST['enroll_face_btn'])) {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $vehicle_plate = isset($_POST['vehicle_plate']) ? mysqli_real_escape_string($conn, $_POST['vehicle_plate']) : '';
    
    if ($_POST['person_type'] === 'Other' && !empty($_POST['custom_person_type'])) {
        $person_type = mysqli_real_escape_string($conn, $_POST['custom_person_type']);
    } else {
        $person_type = mysqli_real_escape_string($conn, $_POST['person_type']);
    }
    
    // Process JSON payload containing the 4 base64 images
    if (!empty($_POST['captured_images_json'])) {
        $images_array = json_decode($_POST['captured_images_json'], true);
        
        if (is_array($images_array) && count($images_array) === 4) {
            $target_dir = "../uploads/faces/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $saved_paths = [];
            $has_error = false;
            
            foreach ($images_array as $index => $img_data) {
                $img_data = str_replace('data:image/jpeg;base64,', '', $img_data);
                $img_data = str_replace(' ', '+', $img_data);
                $decoded_file = base64_decode($img_data);
                
                $new_filename = "face_ext_" . time() . "_frame" . ($index + 1) . "_" . rand(1000, 9999) . ".jpg";
                $relative_db_path = "uploads/faces/" . $new_filename;
                $destination_path = $target_dir . $new_filename;
                
                if (file_put_contents($destination_path, $decoded_file)) {
                    $saved_paths[] = $relative_db_path;
                } else {
                    $has_error = true;
                    break;
                }
            }
            
            if (!$has_error) {
                // Main visual reference to store in the primary field path column
                $primary_face_path = $saved_paths[0]; 
                
                $sql = "INSERT INTO frequent_personnel (full_name, role_type, face_template_path, registered_vehicle_plate) 
                        VALUES ('$full_name', '$person_type', '$primary_face_path', '$vehicle_plate')";
                
                if ($conn->query($sql)) {
                    $success_msg = "Successfully registered $full_name with 4 biometric snapshots!";
                    
                    // ================= REAL-TIME ENGINE SYNCHRONIZATION =================
                    $ch = curl_init('http://127.0.0.1:5000/api/retrain');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                    curl_exec($ch);
                    curl_close($ch);
                    
                } else {
                    $error_msg = "Database profile logging failure: " . $conn->error;
                }
            } else {
                $error_msg = "Failed to compile snapshot frame stream onto disk storage layers.";
            }
        } else {
            $error_msg = "Invalid image bundle detected. Exactly 4 snapshots are required.";
        }
    } else {
        $error_msg = "No identity snapshots detected. Please capture all 4 required angles.";
    }
}

// ================= ACTION: EDIT PERSONNEL =================
if (isset($_POST['edit_personnel_btn'])) {
    $personnel_id = intval($_POST['personnel_id']);
    $edit_full_name = mysqli_real_escape_string($conn, $_POST['edit_full_name']);
    $edit_role_type = mysqli_real_escape_string($conn, $_POST['edit_role_type']);
    $edit_vehicle_plate = mysqli_real_escape_string($conn, $_POST['edit_vehicle_plate']);

    $update_sql = "UPDATE frequent_personnel SET full_name = '$edit_full_name', role_type = '$edit_role_type', registered_vehicle_plate = '$edit_vehicle_plate' WHERE id = $personnel_id";
    
    if ($conn->query($update_sql)) {
        $success_msg = "Personnel profile updated successfully!";
    } else {
        $error_msg = "Failed to update profile: " . $conn->error;
    }
}

// ================= ACTION: DELETE PERSONNEL =================
if (isset($_POST['delete_personnel_btn'])) {
    $personnel_id = intval($_POST['personnel_id']);

    // Retrieve file path to remove stored image file from storage if it exists
    $stmt = $conn->prepare("SELECT face_template_path FROM frequent_personnel WHERE id = ?");
    $stmt->bind_param("i", $personnel_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        if (!empty($row['face_template_path']) && file_exists("../" . $row['face_template_path'])) {
            @unlink("../" . $row['face_template_path']);
        }
    }

    $del_stmt = $conn->prepare("DELETE FROM frequent_personnel WHERE id = ?");
    $del_stmt->bind_param("i", $personnel_id);
    
    if ($del_stmt->execute()) {
        $success_msg = "Personnel profile deleted successfully.";
        
        // Retrain facial engine after removal
        $ch = curl_init('http://127.0.0.1:5000/api/retrain');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
        curl_close($ch);
    } else {
        $error_msg = "Error deleting record: " . $conn->error;
    }
}

// 1. FETCH RECENT FREQUENT PERSONNEL FOR THE ACTIVE MONITOR MATRIX
$recent_registrations = [];
$personnel_list = $conn->query("SELECT id, full_name, role_type, face_template_path, registered_vehicle_plate FROM frequent_personnel WHERE face_template_path IS NOT NULL ORDER BY id DESC LIMIT 10");

if ($personnel_list) { 
    while($p = $personnel_list->fetch_assoc()) { 
        $recent_registrations[] = $p; 
    } 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Gate Registration Hub</title>
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
        
        .sidebar { width: 260px; min-width: 260px; background-color: #ffffff; border-right: 1px solid #e2e8f0; padding-top: 24px; display: flex; flex-direction: column; justify-content: space-between; }
        .brand-logo-area { padding: 0 24px 20px 24px; display: flex; align-items: center; gap: 12px; }
        .brand-logo-icon { color: var(--subdivision-orange); font-size: 1.6rem; }
        .brand-logo-text { color: var(--text-dark); font-size: 20px; font-weight: 700; letter-spacing: -0.5px; margin: 0; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar .nav-link { color: #4a5568; font-size: 14px; font-weight: 500; padding: 12px 20px; margin: 4px 16px; border-radius: 8px; display: flex; align-items: center; text-decoration: none; transition: all 0.2s ease; }
        .sidebar .nav-link:hover { color: var(--subdivision-orange); background-color: rgba(230, 106, 0, 0.05); }
        .sidebar .nav-link.active { color: #ffffff; background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); font-weight: 600; }
        .sidebar .nav-link i { font-size: 16px; width: 28px; }

        .main-content { flex-grow: 1; padding: 40px; box-sizing: border-box; }
        .console-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01); height: 100%; }
        .form-control, .form-select { font-size: 14px; padding: 10px; border-radius: 8px; }
        .form-control:focus, .form-select:focus { border-color: var(--subdivision-orange); box-shadow: 0 0 0 3px rgba(230, 106, 0, 0.1); }
        
        .btn-orange { background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); border: none; color: white; font-weight: 600; padding: 12px 24px; border-radius: 8px; width: 100%; transition: opacity 0.2s; }
        .btn-orange:hover { opacity: 0.95; color: #fff; }
        
        .camera-box { background: #1a202c; border-radius: 8px; overflow: hidden; position: relative; width: 100%; max-width: 340px; height: 255px; margin: 0 auto; border: 2px dashed #4a5568; }
        #webcamVideo { width: 100%; height: 100%; object-fit: cover; }
        
        /* Multi-frame grid arrays */
        .quad-preview-container { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; max-width: 340px; margin: 12px auto 0 auto; }
        .quad-box { aspect-ratio: 4/3; background: #e2e8f0; border-radius: 6px; overflow: hidden; position: relative; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px; font-weight: 600; }
        .quad-box img { width: 100%; height: 100%; object-fit: cover; display: none; }
        .quad-box.active-slot { border-color: var(--subdivision-orange); box-shadow: 0 0 0 2px rgba(230, 106, 0, 0.2); color: var(--subdivision-orange); }

        .thumbnail-preview { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; }
        .action-btn { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; }
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
                <li><a href="requests.php" class="nav-link"><i class="fa fa-file-alt"></i> Requests</a></li>
                <li><a href="billing.php" class="nav-link"><i class="fa fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link"><i class="fa fa-money-bill-transfer"></i> Expenses</a></li>
                <li><a href="guards.php" class="nav-link"><i class="fa fa-user-lock"></i> Staff Guards</a></li>
            </ul>
        </div>
        <div class="p-3"><a href="../logout.php" class="nav-link text-danger border rounded bg-light"><i class="fa fa-sign-out-alt"></i> Exit</a></div>
    </div>

    <div class="main-content">
        <div class="pb-3 mb-4 border-bottom">
            <h1 class="h3 fw-bold text-dark mb-1">External Personnel Registration</h1>
            <p class="text-muted small mb-0">Capture 4 unique spatial angles to establish highly dependable verification signatures.</p>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success small p-3 mb-4 border-0 shadow-sm" style="border-radius:8px;"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success_msg; ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger small p-3 mb-4 border-0 shadow-sm" style="border-radius:8px;"><i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-5 col-lg-6">
                <div class="console-card">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa fa-camera text-orange me-2"></i>Enroll Multi-Angle Profile</h5>
                    
                    <form action="face_registration.php" method="POST" id="enrollmentForm">
                        <input type="hidden" name="captured_images_json" id="capturedImagesJsonField">

                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary small">Profile Classification Type</label>
                            <select name="person_type" id="profileClassificationSelect" class="form-select" required>
                                <option value="Subdivision Vendor Partner">Subdivision Vendor Partner</option>
                                <option value="Delivery Courier / Rider">Delivery Courier / Rider</option>
                                <option value="Visitor / Guest">Visitor / Guest</option>
                                <option value="Service Contractor">Service Contractor (Repairs/Maintenance)</option>
                                <option value="Other">Other (Specify...)</option>
                            </select>
                        </div>

                        <div class="mb-3 d-none" id="wrapperCustomClassification">
                            <label class="form-label fw-semibold text-orange small">Specify Classification Category</label>
                            <input type="text" name="custom_person_type" id="fieldCustomClassification" class="form-control" placeholder="e.g., Landscaper, Moving Truck">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary small" id="dynamicNameLabel">Vendor Full Name</label>
                            <input type="text" name="full_name" class="form-control" placeholder="Enter complete name..." required>
                        </div>

                        <div class="mb-3" id="wrapperVehiclePlate">
                            <label class="form-label fw-semibold text-secondary small">Vehicle Plate Number (Optional)</label>
                            <input type="text" name="vehicle_plate" class="form-control" placeholder="e.g., ABC-1234">
                        </div>

                        <div class="mb-3 text-center">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold text-secondary small mb-0">Registration Frames</label>
                                <span class="badge bg-dark text-white tiny-label" id="captureProgressTracker">0 / 4 Captured</span>
                            </div>
                            
                            <div class="camera-box mb-2">
                                <video id="webcamVideo" autoplay playsinline muted></video>
                                <canvas id="processingCanvas" width="640" height="480" style="display:none;"></canvas>
                            </div>
                            
                            <div class="quad-preview-container">
                                <div class="quad-box active-slot" id="slot_0">1</div>
                                <div class="quad-box" id="slot_1">2</div>
                                <div class="quad-box" id="slot_2">3</div>
                                <div class="quad-box" id="slot_3">4</div>
                            </div>
                            
                            <div class="d-flex gap-2 justify-content-center mt-3">
                                <button type="button" id="startCamBtn" class="btn btn-sm btn-dark px-3"><i class="fa fa-video me-1.5"></i>Start Cam</button>
                                <button type="button" id="snapShotBtn" class="btn btn-sm btn-warning fw-semibold px-3" disabled><i class="fa fa-camera me-1.5"></i>Capture Angle</button>
                            </div>
                        </div>

                        <button type="submit" name="enroll_face_btn" id="submitEnrollBtn" class="btn-orange mt-2" disabled><i class="fa fa-fingerprint me-2"></i>Save & Synchronize Profile</button>
                    </form>
                </div>
            </div>

            <div class="col-xl-7 col-lg-6">
                <div class="console-card">
                    <h5 class="fw-bold text-dark mb-4"><i class="fa fa-circle-nodes text-success me-2"></i>Active Personnel Registry</h5>
                    
                    <?php if (count($recent_registrations) > 0): ?>
                        <div class="table-responsive">
                            <table class="table align-middle table-hover border-top">
                                <thead class="table-light">
                                    <tr class="small text-secondary">
                                        <th>Profile Photo</th>
                                        <th>Full Name</th>
                                        <th>Classification Role</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_registrations as $reg): ?>
                                        <tr>
                                            <td>
                                                <img src="../<?php echo htmlspecialchars($reg['face_template_path']); ?>" class="thumbnail-preview" alt="Visitor Face Image">
                                            </td>
                                            <td class="fw-semibold text-dark small"><?php echo htmlspecialchars($reg['full_name']); ?></td>
                                            <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2.5 py-1.5 small"><?php echo htmlspecialchars($reg['role_type']); ?></span></td>
                                            <td class="text-center"><span class="text-success fw-bold small"><i class="fa fa-circle-check me-1"></i> Monitored</span></td>
                                            <td class="text-center">
                                                <div class="btn-group gap-1">
                                                    <!-- View Button -->
                                                    <button type="button" class="btn btn-sm btn-outline-info action-btn" data-bs-toggle="modal" data-bs-target="#viewModal_<?php echo $reg['id']; ?>" title="View Details">
                                                        <i class="fa fa-eye"></i>
                                                    </button>
                                                    <!-- Edit Button -->
                                                    <button type="button" class="btn btn-sm btn-outline-warning action-btn" data-bs-toggle="modal" data-bs-target="#editModal_<?php echo $reg['id']; ?>" title="Edit Personnel">
                                                        <i class="fa fa-pen"></i>
                                                    </button>
                                                    <!-- Delete Button -->
                                                    <button type="button" class="btn btn-sm btn-outline-danger action-btn" data-bs-toggle="modal" data-bs-target="#deleteModal_<?php echo $reg['id']; ?>" title="Delete Personnel">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </div>

                                                <!-- ================= VIEW MODAL ================= -->
                                                <div class="modal fade text-start" id="viewModal_<?php echo $reg['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content border-0 shadow-sm" style="border-radius:12px;">
                                                            <div class="modal-header border-bottom pb-3">
                                                                <h6 class="modal-title fw-bold text-dark"><i class="fa fa-id-card text-orange me-2"></i>Personnel Identity Details</h6>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body py-4 text-center">
                                                                <img src="../<?php echo htmlspecialchars($reg['face_template_path']); ?>" class="rounded-circle shadow-sm border mb-3" style="width:110px; height:110px; object-fit:cover;" alt="Face Photo">
                                                                <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($reg['full_name']); ?></h5>
                                                                <p class="mb-3"><span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-3 py-1"><?php echo htmlspecialchars($reg['role_type']); ?></span></p>
                                                                
                                                                <div class="bg-light p-3 rounded-3 text-start small">
                                                                    <div class="row g-2">
                                                                        <div class="col-5 text-secondary fw-semibold">Database ID:</div>
                                                                        <div class="col-7 text-dark fw-bold">#<?php echo $reg['id']; ?></div>
                                                                        <div class="col-5 text-secondary fw-semibold">Vehicle Plate:</div>
                                                                        <div class="col-7 text-dark fw-bold"><?php echo !empty($reg['registered_vehicle_plate']) ? htmlspecialchars($reg['registered_vehicle_plate']) : 'N/A'; ?></div>
                                                                        <div class="col-5 text-secondary fw-semibold">Biometric Status:</div>
                                                                        <div class="col-7 text-success fw-bold"><i class="fa fa-check-circle me-1"></i> Active & Synced</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer border-top pt-2">
                                                                <button type="button" class="btn btn-sm btn-secondary px-4 rounded-3" data-bs-dismiss="modal">Close</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- ================= EDIT MODAL ================= -->
                                                <div class="modal fade text-start" id="editModal_<?php echo $reg['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content border-0 shadow-sm" style="border-radius:12px;">
                                                            <form action="face_registration.php" method="POST">
                                                                <input type="hidden" name="personnel_id" value="<?php echo $reg['id']; ?>">
                                                                <div class="modal-header border-bottom pb-3">
                                                                    <h6 class="modal-title fw-bold text-dark"><i class="fa fa-pen-to-square text-orange me-2"></i>Edit Personnel Information</h6>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body py-3">
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-semibold text-secondary small">Full Name</label>
                                                                        <input type="text" name="edit_full_name" class="form-control" value="<?php echo htmlspecialchars($reg['full_name']); ?>" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-semibold text-secondary small">Classification Role</label>
                                                                        <select name="edit_role_type" class="form-select" required>
                                                                            <option value="Subdivision Vendor Partner" <?php echo ($reg['role_type'] === 'Subdivision Vendor Partner') ? 'selected' : ''; ?>>Subdivision Vendor Partner</option>
                                                                            <option value="Delivery Courier / Rider" <?php echo ($reg['role_type'] === 'Delivery Courier / Rider') ? 'selected' : ''; ?>>Delivery Courier / Rider</option>
                                                                            <option value="Visitor / Guest" <?php echo ($reg['role_type'] === 'Visitor / Guest') ? 'selected' : ''; ?>>Visitor / Guest</option>
                                                                            <option value="Service Contractor" <?php echo ($reg['role_type'] === 'Service Contractor') ? 'selected' : ''; ?>>Service Contractor</option>
                                                                            <option value="<?php echo htmlspecialchars($reg['role_type']); ?>" <?php echo (!in_array($reg['role_type'], ['Subdivision Vendor Partner','Delivery Courier / Rider','Visitor / Guest','Service Contractor'])) ? 'selected' : ''; ?>><?php echo htmlspecialchars($reg['role_type']); ?></option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-semibold text-secondary small">Vehicle Plate Number</label>
                                                                        <input type="text" name="edit_vehicle_plate" class="form-control" value="<?php echo htmlspecialchars($reg['registered_vehicle_plate']); ?>" placeholder="e.g., ABC-1234">
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer border-top pt-2">
                                                                    <button type="button" class="btn btn-sm btn-light border rounded-3" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" name="edit_personnel_btn" class="btn btn-sm btn-warning text-dark fw-bold px-3 rounded-3">Save Changes</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- ================= DELETE MODAL ================= -->
                                                <div class="modal fade text-start" id="deleteModal_<?php echo $reg['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content border-0 shadow-sm" style="border-radius:12px;">
                                                            <form action="face_registration.php" method="POST">
                                                                <input type="hidden" name="personnel_id" value="<?php echo $reg['id']; ?>">
                                                                <div class="modal-header border-bottom pb-3">
                                                                    <h6 class="modal-title fw-bold text-danger"><i class="fa fa-triangle-exclamation me-2"></i>Confirm Removal</h6>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body py-4">
                                                                    <p class="small text-secondary mb-0">Are you sure you want to remove <strong class="text-dark"><?php echo htmlspecialchars($reg['full_name']); ?></strong> from the active personnel registry?</p>
                                                                    <p class="tiny text-muted mt-2 mb-0"><i class="fa fa-circle-info me-1"></i>This action will remove their saved face template and prompt the recognition engine to re-train.</p>
                                                                </div>
                                                                <div class="modal-footer border-top pt-2">
                                                                    <button type="button" class="btn btn-sm btn-light border rounded-3" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" name="delete_personnel_btn" class="btn btn-sm btn-danger fw-semibold px-3 rounded-3">Delete Personnel</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>

                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fa-solid fa-user-plus text-muted mb-3" style="font-size: 3rem; opacity: 0.3;"></i>
                            <p class="text-muted small mb-0">No external identities registered yet. Capture incoming snapshots to build logs.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Classification Logic Observers
    const classSelect = document.getElementById('profileClassificationSelect');
    const nameLabel = document.getElementById('dynamicNameLabel');
    const customWrapper = document.getElementById('wrapperCustomClassification');
    const customInput = document.getElementById('fieldCustomClassification');

    classSelect.addEventListener('change', function() {
        const val = this.value;
        if (val === 'Other') {
            customWrapper.classList.remove('d-none');
            customInput.required = true;
            nameLabel.innerText = "Personnel Full Name";
        } else {
            customWrapper.classList.add('d-none');
            customInput.required = false;
            customInput.value = "";
            if(val.includes('Vendor')) nameLabel.innerText = "Vendor Full Name";
            else if(val.includes('Delivery')) nameLabel.innerText = "Delivery Rider Full Name";
            else if(val.includes('Visitor')) nameLabel.innerText = "Visitor / Guest Full Name";
            else nameLabel.innerText = "Personnel / Contractor Full Name";
        }
    });

    // Multi-Angle Core Camera Mechanics
    const video = document.getElementById('webcamVideo');
    const canvas = document.getElementById('processingCanvas');
    const startBtn = document.getElementById('startCamBtn');
    const snapBtn = document.getElementById('snapShotBtn');
    const submitBtn = document.getElementById('submitEnrollBtn');
    const tracker = document.getElementById('captureProgressTracker');
    const hiddenJsonField = document.getElementById('capturedImagesJsonField');
    const ctx = canvas.getContext('2d');

    let capturedFrames = [];
    const totalRequiredFrames = 4;

    startBtn.addEventListener('click', async function() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false });
            video.srcObject = stream;
            snapBtn.disabled = false;
            
            // Reset state fields if running it back a second time
            capturedFrames = [];
            hiddenJsonField.value = "";
            submitBtn.disabled = true;
            tracker.innerText = "0 / 4 Captured";
            tracker.className = "badge bg-dark text-white tiny-label";
            
            for(let i=0; i<totalRequiredFrames; i++) {
                const box = document.getElementById(`slot_${i}`);
                box.innerHTML = i + 1;
                box.classList.remove('active-slot');
            }
            document.getElementById('slot_0').classList.add('active-slot');
            
            startBtn.innerHTML = '<i class="fa fa-refresh me-1.5"></i>Reset Array';
        } catch (err) {
            alert("Camera device access denied: " + err.message);
        }
    });

    snapBtn.addEventListener('click', function() {
        if (capturedFrames.length >= totalRequiredFrames) return;

        // Freeze frame snapshot data context
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const base64Data = canvas.toDataURL('image/jpeg', 0.85);
        
        const dynamicSlotIndex = capturedFrames.length;
        capturedFrames.push(base64Data);
        
        // Render preview context frame visually in real time 
        const activeBox = document.getElementById(`slot_${dynamicSlotIndex}`);
        activeBox.innerHTML = `<img src="${base64Data}" style="display:block;">`;
        activeBox.classList.remove('active-slot');
        
        tracker.innerText = `${capturedFrames.length} / 4 Captured`;

        if (capturedFrames.length < totalRequiredFrames) {
            // Forward pointer highlights to the next open box array spot
            document.getElementById(`slot_${capturedFrames.length}`).classList.add('active-slot');
        } else {
            // Bundle up everything as a structural JSON array string
            hiddenJsonField.value = JSON.stringify(capturedFrames);
            snapBtn.disabled = true;
            submitBtn.disabled = false;
            tracker.className = "badge bg-success text-white tiny-label";
            alert("All 4 facial profiles successfully mapped! Ready to save registry.");
        }
    });
});
</script>
</body>
</html>