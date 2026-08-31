<?php 
session_start();

// 1. SECURITY UTILITY GATEWAY: Kicks out unauthorized sessions
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

// 2. SAFE DATABASE ACCESS INTEGRATION
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
                $all_faces_json = mysqli_real_escape_string($conn, json_encode($saved_paths)); 
                
                $sql = "INSERT INTO frequent_personnel (full_name, role_type, face_template_path, registered_vehicle_plate) 
                        VALUES ('$full_name', '$person_type', '$all_faces_json', '$vehicle_plate')";
                
                if ($conn->query($sql)) {
                    $success_msg = "Successfully registered $full_name with 4 biometric snapshots!";
                    
                    // REAL-TIME ENGINE SYNCHRONIZATION
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

    $stmt = $conn->prepare("SELECT face_template_path FROM frequent_personnel WHERE id = ?");
    $stmt->bind_param("i", $personnel_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        if (!empty($row['face_template_path'])) {
            $decoded_paths = json_decode($row['face_template_path'], true);
            if (is_array($decoded_paths)) {
                foreach ($decoded_paths as $file_path) {
                    if (file_exists("../" . $file_path)) {
                        @unlink("../" . $file_path);
                    }
                }
            } elseif (file_exists("../" . $row['face_template_path'])) {
                @unlink("../" . $row['face_template_path']);
            }
        }
    }

    $del_stmt = $conn->prepare("DELETE FROM frequent_personnel WHERE id = ?");
    $del_stmt->bind_param("i", $personnel_id);
    
    if ($del_stmt->execute()) {
        $success_msg = "Personnel profile deleted successfully.";
        
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

// FETCH RECENT FREQUENT PERSONNEL & METRICS
$recent_registrations = [];
$personnel_list = $conn->query("SELECT id, full_name, role_type, face_template_path, registered_vehicle_plate FROM frequent_personnel WHERE face_template_path IS NOT NULL ORDER BY id DESC");

if ($personnel_list) { 
    while($p = $personnel_list->fetch_assoc()) { 
        $recent_registrations[] = $p; 
    } 
}

$total_personnel = count($recent_registrations);
$total_vehicles = $conn->query("SELECT COUNT(*) as cnt FROM frequent_personnel WHERE registered_vehicle_plate IS NOT NULL AND registered_vehicle_plate != ''")->fetch_assoc()['cnt'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Personnel Registration Hub</title>
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
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
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
            display: flex !important;
            flex-direction: row !important;
            min-height: 100vh;
            width: 100%;
        }

        /* Sidebar Styling Matching Households */
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
            z-index: 100;
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

        .btn-gradient-orange {
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%);
            color: #ffffff !important;
            border: none;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.2);
            transition: all 0.2s ease;
        }
        .btn-gradient-orange:hover {
            opacity: 0.92;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(234, 88, 12, 0.3);
        }

        /* CONSOLE & STAT CARDS */
        .console-card { 
            background: #ffffff; 
            border: 1px solid var(--border-color); 
            border-radius: var(--radius-lg); 
            padding: 24px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.02); 
            height: 100%; 
        }

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

        /* CAMERA & SNAPSHOT PREVIEW */
        .camera-box { 
            background: #0f172a; 
            border-radius: var(--radius-md); 
            overflow: hidden; 
            position: relative; 
            width: 100%; 
            height: 240px; 
            margin: 0 auto; 
            border: 2px dashed #64748b; 
        }
        #webcamVideo { width: 100%; height: 100%; object-fit: cover; }
        
        .quad-preview-container { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 12px; }
        .quad-box { 
            aspect-ratio: 4/3; 
            background: #f1f5f9; 
            border-radius: var(--radius-sm); 
            overflow: hidden; 
            position: relative; 
            border: 1px solid #cbd5e1; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: var(--text-muted); 
            font-size: 11px; 
            font-weight: 700; 
        }
        .quad-box img { width: 100%; height: 100%; object-fit: cover; display: none; }
        .quad-box.active-slot { 
            border-color: var(--subdivision-orange); 
            box-shadow: 0 0 0 2px rgba(234, 88, 12, 0.2); 
            color: var(--subdivision-orange); 
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
            padding: 14px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-body);
            font-size: 13.5px;
        }

        .thumbnail-preview { 
            width: 44px; 
            height: 44px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 2px solid #ffffff; 
            box-shadow: 0 2px 6px rgba(0,0,0,0.08); 
        }

        .plate-badge {
            background-color: #0f172a;
            color: #fbbf24;
            font-family: 'Courier New', monospace;
            font-weight: 700;
            font-size: 11px;
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .form-control, .form-select {
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            padding: 9px 13px;
            font-size: 13.5px;
        }
        .form-control:focus, .form-select:focus {
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
                <li><a href="dashboard.php" class="nav-link"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="households.php" class="nav-link"><i class="fa-solid fa-house-user"></i> Household Directory</a></li>
                <li><a href="residents.php" class="nav-link"><i class="fa-solid fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link active"><i class="fa-solid fa-user-gear"></i> Personnel</a></li>
                <li><a href="guards.php" class="nav-link"><i class="fa-solid fa-user-shield"></i> Staff Guards</a></li>
            </ul>

            <div class="sidebar-section-title">OPERATIONS</div>
            <ul class="sidebar-menu">
                <li><a href="events.php" class="nav-link"><i class="fa-solid fa-calendar-days"></i> Events</a></li>
                <li><a href="requests.php" class="nav-link"><i class="fa-solid fa-file-lines"></i> Requests & Concerns</a></li>
                <li><a href="billing.php" class="nav-link"><i class="fa-solid fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link"><i class="fa-solid fa-money-bill-transfer"></i> Expenses</a></li>
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
                <h1 class="dashboard-title">External Personnel Registry</h1>
                <p class="text-muted small mb-0 mt-1">Enroll contractors, delivery riders, and visitors with multi-angle biometric profiles.</p>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #eff6ff; color: #2563eb;">
                        <i class="fa-solid fa-id-badge"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Registered Personnel</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $total_personnel; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #fef3c7; color: #d97706;">
                        <i class="fa-solid fa-truck-ramp-box"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Registered Vehicles</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $total_vehicles; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4 small fw-medium" style="border-radius: var(--radius-md);"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success_msg; ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4 small fw-medium" style="border-radius: var(--radius-md);"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- LEFT COLUMN: MULTI-ANGLE ENROLLMENT FORM -->
            <div class="col-xl-5 col-lg-6">
                <div class="console-card">
                    <h5 class="fw-bold text-dark mb-3 fs-6"><i class="fa fa-camera text-warning me-2"></i>Enroll Biometric Profile</h5>
                    
                    <form action="face_registration.php" method="POST" id="enrollmentForm">
                        <input type="hidden" name="captured_images_json" id="capturedImagesJsonField">

                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary small">Profile Classification</label>
                            <select name="person_type" id="profileClassificationSelect" class="form-select" required>
                                <option value="Subdivision Vendor Partner">Subdivision Vendor Partner</option>
                                <option value="Delivery Courier / Rider">Delivery Courier / Rider</option>
                                <option value="Visitor / Guest">Visitor / Guest</option>
                                <option value="Service Contractor">Service Contractor (Repairs/Maintenance)</option>
                                <option value="Other">Other (Specify...)</option>
                            </select>
                        </div>

                        <div class="mb-3 d-none" id="wrapperCustomClassification">
                            <label class="form-label fw-semibold text-warning small">Specify Custom Role</label>
                            <input type="text" name="custom_person_type" id="fieldCustomClassification" class="form-control" placeholder="e.g., Landscaper, Maintenance">
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
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-semibold text-secondary small mb-0">Biometric Snapshots (4 Angles)</label>
                                <span class="badge bg-dark text-white tiny-label" id="captureProgressTracker">Angle 1: Look Front</span>
                            </div>
                            
                            <div class="camera-box mb-2">
                                <video id="webcamVideo" autoplay playsinline muted></video>
                                <canvas id="processingCanvas" width="1280" height="720" style="display:none;"></canvas>
                            </div>
                            
                            <div class="quad-preview-container">
                                <div class="quad-box active-slot" id="slot_0">1</div>
                                <div class="quad-box" id="slot_1">2</div>
                                <div class="quad-box" id="slot_2">3</div>
                                <div class="quad-box" id="slot_3">4</div>
                            </div>
                            
                            <div class="d-flex gap-2 justify-content-center mt-3">
                                <button type="button" id="startCamBtn" class="btn btn-sm btn-dark px-3 fw-semibold"><i class="fa fa-video me-1.5"></i>Start Cam</button>
                                <button type="button" id="snapShotBtn" class="btn btn-sm btn-gradient-orange fw-bold px-3" disabled><i class="fa fa-camera me-1.5"></i>Capture Angle</button>
                            </div>
                        </div>

                        <button type="submit" name="enroll_face_btn" id="submitEnrollBtn" class="btn btn-gradient-orange w-100 fw-bold py-2 mt-2" disabled><i class="fa fa-fingerprint me-2"></i>Save & Synchronize Profile</button>
                    </form>
                </div>
            </div>

            <!-- RIGHT COLUMN: ACTIVE PERSONNEL DIRECTORY -->
            <div class="col-xl-7 col-lg-6">
                <div class="table-card p-0">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold text-dark fs-6 mb-0"><i class="fa fa-circle-nodes text-success me-2"></i>Active Personnel Registry</h5>
                        <input type="text" id="personnelSearchInput" class="form-control form-control-sm" style="max-width:200px;" placeholder="Search name...">
                    </div>
                    
                    <?php if (count($recent_registrations) > 0): ?>
                        <div class="table-responsive">
                            <table class="table modern-table align-middle" id="personnelTable">
                                <thead>
                                    <tr>
                                        <th>Personnel Profile</th>
                                        <th>Classification</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_registrations as $reg): 
                                        $parsed_paths = json_decode($reg['face_template_path'], true);
                                        $primary_img = is_array($parsed_paths) ? $parsed_paths[0] : $reg['face_template_path'];
                                    ?>
                                        <tr class="personnel-row">
                                            <td>
                                                <div class="d-flex align-items-center gap-3">
                                                    <img src="../<?php echo htmlspecialchars($primary_img); ?>" class="thumbnail-preview" alt="Face Snapshot">
                                                    <div>
                                                        <div class="fw-bold text-dark target-name"><?php echo htmlspecialchars($reg['full_name']); ?></div>
                                                        <?php if(!empty($reg['registered_vehicle_plate'])): ?>
                                                            <span class="plate-badge mt-1"><i class="fa fa-car"></i><?php echo htmlspecialchars($reg['registered_vehicle_plate']); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted small">No vehicle registered</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border fw-medium px-2 py-1" style="font-size:11px;">
                                                    <?php echo htmlspecialchars($reg['role_type']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group gap-1">
                                                    <button type="button" class="btn btn-sm btn-outline-primary fw-semibold px-2" data-bs-toggle="modal" data-bs-target="#viewModal_<?php echo $reg['id']; ?>">
                                                        <i class="fa fa-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-warning text-dark fw-semibold px-2" data-bs-toggle="modal" data-bs-target="#editModal_<?php echo $reg['id']; ?>">
                                                        <i class="fa fa-pen"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger fw-semibold px-2" data-bs-toggle="modal" data-bs-target="#deleteModal_<?php echo $reg['id']; ?>">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </div>

                                                <!-- VIEW MODAL -->
                                                <div class="modal fade text-start" id="viewModal_<?php echo $reg['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content border-0">
                                                            <div class="modal-header border-bottom py-3">
                                                                <h6 class="modal-title fw-bold text-dark fs-6"><i class="fa fa-id-card text-warning me-2"></i>Personnel Identity Details</h6>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body p-4 text-center bg-white">
                                                                <img src="../<?php echo htmlspecialchars($primary_img); ?>" class="rounded-circle shadow-sm border mb-3" style="width:100px; height:100px; object-fit:cover;" alt="Face Image">
                                                                
                                                                <?php if (is_array($parsed_paths) && count($parsed_paths) > 1): ?>
                                                                    <div class="d-flex justify-content-center gap-2 mb-3">
                                                                        <?php foreach ($parsed_paths as $idx => $p): ?>
                                                                            <img src="../<?php echo htmlspecialchars($p); ?>" class="rounded border shadow-sm" style="width:48px; height:48px; object-fit:cover;" title="Angle <?php echo $idx + 1; ?>">
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>

                                                                <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($reg['full_name']); ?></h5>
                                                                <p class="mb-3"><span class="badge bg-light text-dark border px-3 py-1"><?php echo htmlspecialchars($reg['role_type']); ?></span></p>
                                                                
                                                                <div class="bg-light p-3 rounded-3 text-start small border">
                                                                    <div class="row g-2">
                                                                        <div class="col-5 text-muted fw-semibold">Database ID:</div>
                                                                        <div class="col-7 text-dark fw-bold">#<?php echo $reg['id']; ?></div>
                                                                        <div class="col-5 text-muted fw-semibold">Vehicle Plate:</div>
                                                                        <div class="col-7 text-dark fw-bold"><?php echo !empty($reg['registered_vehicle_plate']) ? htmlspecialchars($reg['registered_vehicle_plate']) : 'N/A'; ?></div>
                                                                        <div class="col-5 text-muted fw-semibold">Biometric Snapshots:</div>
                                                                        <div class="col-7 text-dark fw-bold"><?php echo is_array($parsed_paths) ? count($parsed_paths) : '1'; ?> Saved</div>
                                                                        <div class="col-5 text-muted fw-semibold">Engine Status:</div>
                                                                        <div class="col-7 text-success fw-bold"><i class="fa fa-check-circle me-1"></i> Synchronized</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer bg-light border-0">
                                                                <button type="button" class="btn btn-sm btn-secondary fw-semibold px-4" data-bs-dismiss="modal">Close</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- EDIT MODAL -->
                                                <div class="modal fade text-start" id="editModal_<?php echo $reg['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content border-0">
                                                            <form action="face_registration.php" method="POST">
                                                                <input type="hidden" name="personnel_id" value="<?php echo $reg['id']; ?>">
                                                                <div class="modal-header border-bottom py-3">
                                                                    <h6 class="modal-title fw-bold text-dark fs-6"><i class="fa fa-pen-to-square text-warning me-2"></i>Edit Personnel Information</h6>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body p-4 bg-white">
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold text-dark small">Full Name</label>
                                                                        <input type="text" name="edit_full_name" class="form-control" value="<?php echo htmlspecialchars($reg['full_name']); ?>" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold text-dark small">Classification Role</label>
                                                                        <select name="edit_role_type" class="form-select" required>
                                                                            <option value="Subdivision Vendor Partner" <?php echo ($reg['role_type'] === 'Subdivision Vendor Partner') ? 'selected' : ''; ?>>Subdivision Vendor Partner</option>
                                                                            <option value="Delivery Courier / Rider" <?php echo ($reg['role_type'] === 'Delivery Courier / Rider') ? 'selected' : ''; ?>>Delivery Courier / Rider</option>
                                                                            <option value="Visitor / Guest" <?php echo ($reg['role_type'] === 'Visitor / Guest') ? 'selected' : ''; ?>>Visitor / Guest</option>
                                                                            <option value="Service Contractor" <?php echo ($reg['role_type'] === 'Service Contractor') ? 'selected' : ''; ?>>Service Contractor</option>
                                                                            <option value="<?php echo htmlspecialchars($reg['role_type']); ?>" <?php echo (!in_array($reg['role_type'], ['Subdivision Vendor Partner','Delivery Courier / Rider','Visitor / Guest','Service Contractor'])) ? 'selected' : ''; ?>><?php echo htmlspecialchars($reg['role_type']); ?></option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold text-dark small">Vehicle Plate Number</label>
                                                                        <input type="text" name="edit_vehicle_plate" class="form-control" value="<?php echo htmlspecialchars($reg['registered_vehicle_plate']); ?>" placeholder="e.g., ABC-1234">
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer bg-light border-0">
                                                                    <button type="button" class="btn btn-sm btn-secondary fw-semibold px-3" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" name="edit_personnel_btn" class="btn btn-sm btn-gradient-orange fw-bold px-4">Save Changes</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- DELETE MODAL -->
                                                <div class="modal fade text-start" id="deleteModal_<?php echo $reg['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                                                        <div class="modal-content border-0">
                                                            <form action="face_registration.php" method="POST">
                                                                <input type="hidden" name="personnel_id" value="<?php echo $reg['id']; ?>">
                                                                <div class="modal-header border-bottom py-3">
                                                                    <h6 class="modal-title fw-bold text-danger fs-6"><i class="fa fa-triangle-exclamation me-2"></i>Confirm Removal</h6>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body p-4 text-center bg-white">
                                                                    <i class="fa fa-trash-can text-danger fs-1 mb-3 opacity-75"></i>
                                                                    <p class="text-dark fw-semibold mb-1" style="font-size: 15px;">Delete personnel profile?</p>
                                                                    <p class="text-muted small mb-0">Are you sure you want to remove <strong class="text-dark"><?php echo htmlspecialchars($reg['full_name']); ?></strong> from the active registry?</p>
                                                                </div>
                                                                <div class="modal-footer bg-light border-0 justify-content-center">
                                                                    <button type="button" class="btn btn-sm btn-secondary fw-semibold px-4" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" name="delete_personnel_btn" class="btn btn-sm btn-danger fw-bold px-4">Delete</button>
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
                            <i class="fa-solid fa-user-plus text-muted mb-3" style="font-size: 2.5rem; opacity: 0.3;"></i>
                            <p class="text-muted small mb-0">No external personnel registered yet.</p>
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

    // Table Instant Filter
    const searchInput = document.getElementById('personnelSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            document.querySelectorAll('#personnelTable .personnel-row').forEach(row => {
                const name = row.querySelector('.target-name')?.textContent.toLowerCase() || '';
                row.style.display = name.includes(query) ? '' : 'none';
            });
        });
    }

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
    
    const anglePrompts = [
        "Angle 1: Look Front",
        "Angle 2: Turn Head 30° Left",
        "Angle 3: Turn Head 30° Right",
        "Angle 4: Tilt Head Upwards"
    ];

    startBtn.addEventListener('click', async function() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { width: { ideal: 1280 }, height: { ideal: 720 } }, 
                audio: false 
            });
            video.srcObject = stream;
            snapBtn.disabled = false;
            
            canvas.width = 1280;
            canvas.height = 720;
            
            capturedFrames = [];
            hiddenJsonField.value = "";
            submitBtn.disabled = true;
            tracker.innerText = anglePrompts[0];
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

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const base64Data = canvas.toDataURL('image/jpeg', 1.0);
        
        const dynamicSlotIndex = capturedFrames.length;
        capturedFrames.push(base64Data);
        
        const activeBox = document.getElementById(`slot_${dynamicSlotIndex}`);
        activeBox.innerHTML = `<img src="${base64Data}" style="display:block;">`;
        activeBox.classList.remove('active-slot');

        if (capturedFrames.length < totalRequiredFrames) {
            document.getElementById(`slot_${capturedFrames.length}`).classList.add('active-slot');
            tracker.innerText = anglePrompts[capturedFrames.length];
        } else {
            hiddenJsonField.value = JSON.stringify(capturedFrames);
            snapBtn.disabled = true;
            submitBtn.disabled = false;
            tracker.innerText = "All 4 HD Angles Mapped!";
            tracker.className = "badge bg-success text-white tiny-label";
        }
    });
});
</script>
</body>
</html>