<?php 
session_start();

// 1. SECURITY GATEWAY: Ensure only the primary Admin can manage security personnel
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

$success_msg = "";
$error_msg = "";

/**
 * Helper Function: Save Base64 Camera Data URL as JPEG image file
 */
function saveBase64Image($base64_string, $output_dir, $suffix = "") {
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0755, true);
    }
    
    // Parse base64 string
    list($type, $data) = explode(';', $base64_string);
    list(, $data)      = explode(',', $data);
    $data = base64_decode($data);
    
    $filename = md5(time() . uniqid()) . ($suffix ? "_$suffix" : "") . '.jpg';
    $file_path = $output_dir . $filename;
    
    if (file_put_contents($file_path, $data)) {
        return $filename;
    }
    return false;
}

// ================= ACTION 1: PROCESS NEW SECURITY GUARD PROVISIONING =================
if (isset($_POST['add_guard_btn'])) {
    $username = trim($conn->real_escape_string($_POST['username']));
    $email = trim($conn->real_escape_string($_POST['email']));
    $raw_password = trim($_POST['password']);

    $conn->begin_transaction();
    try {
        // Enforce uniqueness constraints before inserting
        $check_user = $conn->query("SELECT id FROM users WHERE username='$username' OR email='$email'");
        if ($check_user && $check_user->num_rows > 0) { 
            throw new Exception("The Username or Email address is already registered in the system network."); 
        }

        if (empty($raw_password)) {
            throw new Exception("Please provide a valid password for the guard account.");
        }

        // --- HANDLE 4 CAMERA CAPTURED IMAGES ---
        $primary_image = "";
        $upload_dir = '../uploads/guards/';

        if (!empty($_POST['captured_image_1']) && !empty($_POST['captured_image_2']) && 
            !empty($_POST['captured_image_3']) && !empty($_POST['captured_image_4'])) {
            
            // Save all 4 photo samples for facial recognition training/dataset
            $img1 = saveBase64Image($_POST['captured_image_1'], $upload_dir, "sample1");
            $img2 = saveBase64Image($_POST['captured_image_2'], $upload_dir, "sample2");
            $img3 = saveBase64Image($_POST['captured_image_3'], $upload_dir, "sample3");
            $img4 = saveBase64Image($_POST['captured_image_4'], $upload_dir, "sample4");

            if ($img1 && $img2 && $img3 && $img4) {
                $primary_image = $img1; // Primary profile picture reference
            } else {
                throw new Exception("Failed to process and save all 4 facial recognition snapshots.");
            }
        } else {
            throw new Exception("Complete set of 4 face photos is required for face recognition setup.");
        }

        // Hash system entry password
        $hashed_password = password_hash($raw_password, PASSWORD_BCRYPT);

        // Insert into core users ledger with primary image path and role = 'guard'
        $conn->query("INSERT INTO users (username, password, email, role, image) VALUES ('$username', '$hashed_password', '$email', 'guard', '$primary_image')");

        $conn->commit();
        $success_msg = "Security Guard account & 4 face recognition samples provisioned successfully!";
    } catch (Exception $e) {
        $conn->rollback(); 
        $error_msg = $e->getMessage();
    }
}

// ================= ACTION 2: PROCESS ACCOUNT ACCESS REVOCATION (DELETE) =================
if (isset($_GET['revoke_guard_id'])) {
    $revoke_id = intval($_GET['revoke_guard_id']);
    
    if ($revoke_id === intval($_SESSION['user_id'])) {
        $error_msg = "Operational Lockout Protected: You cannot drop or modify your own active admin session token.";
    } else {
        if ($conn->query("DELETE FROM users WHERE id = $revoke_id AND role = 'guard'")) {
            header("Location: guards.php?success=Guard access tokens revoked. Account cleared from terminal network.");
            exit();
        } else {
            $error_msg = "Failed to safely disconnect user account node entry references.";
        }
    }
}

// ================= ACTION 3: PROCESS GUARD ACCOUNT EDIT / UPDATE =================
if (isset($_POST['edit_guard_btn'])) {
    $guard_id = intval($_POST['guard_id']);
    $username = trim($conn->real_escape_string($_POST['username']));
    $email = trim($conn->real_escape_string($_POST['email']));
    $new_password = trim($_POST['password']);

    $conn->begin_transaction();
    try {
        // Ensure username or email is not taken by another user
        $check_user = $conn->query("SELECT id FROM users WHERE (username='$username' OR email='$email') AND id != $guard_id");
        if ($check_user && $check_user->num_rows > 0) { 
            throw new Exception("The Username or Email address is already registered to another account."); 
        }

        // Handle Optional 4 Camera Snapshots Recapture
        $image_update_sql = "";
        $upload_dir = '../uploads/guards/';

        if (!empty($_POST['captured_image_1']) && !empty($_POST['captured_image_2']) && 
            !empty($_POST['captured_image_3']) && !empty($_POST['captured_image_4'])) {
            
            $img1 = saveBase64Image($_POST['captured_image_1'], $upload_dir, "edit1");
            $img2 = saveBase64Image($_POST['captured_image_2'], $upload_dir, "edit2");
            $img3 = saveBase64Image($_POST['captured_image_3'], $upload_dir, "edit3");
            $img4 = saveBase64Image($_POST['captured_image_4'], $upload_dir, "edit4");

            if ($img1) {
                $image_update_sql = ", image='$img1'";
            }
        }

        // Update with or without new password
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            $conn->query("UPDATE users SET username='$username', email='$email', password='$hashed_password' $image_update_sql WHERE id=$guard_id AND role='guard'");
        } else {
            $conn->query("UPDATE users SET username='$username', email='$email' $image_update_sql WHERE id=$guard_id AND role='guard'");
        }

        $conn->commit();
        $success_msg = "Security Guard account updated successfully!";
    } catch (Exception $e) {
        $conn->rollback(); 
        $error_msg = $e->getMessage();
    }
}

if (isset($_GET['success'])) { 
    $success_msg = $_GET['success']; 
}

// 4. FETCH ALL ACTIVE SECURITY GUARDS CURRENTLY PROVISIONED ON NETWORK
$guards_result = $conn->query("SELECT id, username, email, image, created_at FROM users WHERE role = 'guard' ORDER BY id DESC");
$guards = [];
if ($guards_result && $guards_result->num_rows > 0) {
    while ($row = $guards_result->fetch_assoc()) {
        $guards[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Guard Management</title>
    
    <!-- External UI Framework Dependencies -->
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
        }
        
        .page-wrapper { 
            display: flex; 
            min-height: 100vh; 
            width: 100%; 
        }

        /* Sidebar Navigation Layout */
        .sidebar {
            width: 260px; 
            min-width: 260px; 
            background-color: #ffffff; 
            border-right: 1px solid #e2e8f0; 
            padding-top: 24px;
            display: flex; 
            flex-direction: column; 
            justify-content: space-between;
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
            list-style: none; 
            padding: 0; 
            margin: 0; 
        }
        .sidebar .nav-link { 
            color: #4a5568; 
            font-size: 14px; 
            font-weight: 500; 
            padding: 12px 20px; 
            margin: 4px 16px; 
            border-radius: 8px; 
            display: flex; 
            align-items: center; 
            text-decoration: none; 
            transition: all 0.2s ease; 
        }
        .sidebar .nav-link:hover { 
            color: var(--subdivision-orange); 
            background-color: rgba(230, 106, 0, 0.05); 
        }
        .sidebar .nav-link.active { 
            color: #ffffff; 
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); 
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
            background-color: #fff5f5; 
            color: #c53030 !important; 
            border: 1px solid #fed7d7; 
        }
        .logout-btn:hover { 
            background-color: #e53e3e !important; 
            color: #ffffff !important; 
        }

        /* Main Content Layout */
        .main-content { 
            flex-grow: 1; 
            padding: 40px; 
            background-color: var(--bg-light); 
            box-sizing: border-box; 
        }
        .page-title { 
            color: var(--text-dark); 
            font-weight: 700; 
            letter-spacing: -0.5px; 
            margin: 0; 
        }
        .btn-orange { 
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); 
            border: none; 
            color: white; 
            font-weight: 600; 
            font-size: 14px; 
            padding: 10px 20px; 
            border-radius: 6px; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            cursor: pointer; 
        }
        .btn-orange:hover { 
            opacity: 0.95; 
            color: white; 
        }

        /* Table Architecture Styles */
        .table-card { 
            background: #ffffff; 
            border: 1px solid #e2e8f0; 
            border-radius: 12px; 
            padding: 24px; 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01); 
        }
        .custom-table { 
            width: 100%; 
            margin-bottom: 0; 
        }
        .custom-table th { 
            background-color: #f8fafc; 
            color: #718096; 
            font-size: 11px; 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            border-bottom: 2px solid #e2e8f0; 
            padding: 12px 16px; 
        }
        .custom-table td { 
            color: var(--text-dark); 
            font-size: 14px; 
            padding: 16px; 
            vertical-align: middle; 
            border-bottom: 1px solid #edf2f7; 
        }
        
        .guard-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e2e8f0;
        }
        .guard-avatar-lg {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--subdivision-orange);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .form-control { 
            font-size: 14px; 
        }
        .form-control:focus { 
            border-color: var(--subdivision-orange); 
            box-shadow: 0 0 0 3px rgba(230, 106, 0, 0.1); 
        }
        .form-label { 
            display: block; 
            margin-bottom: 6px; 
            font-size: 13px; 
            color: #4a5568; 
            font-weight: 500; 
        }
        
        .action-link { 
            padding: 6px 10px; 
            border-radius: 6px; 
            font-size: 12px; 
            text-decoration: none; 
            font-weight: 600; 
            display: inline-flex; 
            align-items: center; 
            gap: 4px; 
            transition: all 0.15s ease;
        }
        
        .btn-view-link { background-color: #e0f2fe; color: #0369a1; }
        .btn-view-link:hover { background-color: #bae6fd; color: #0284c7; }
        
        .btn-edit-link { background-color: #fef3c7; color: #b45309; }
        .btn-edit-link:hover { background-color: #fde68a; color: #d97706; }

        .btn-delete-link { background-color: #fee2e2; color: #dc2626; }
        .btn-delete-link:hover { background-color: #fca5a5; }

        /* FIXED MODAL OVERFLOW & CUTOFF BUTTONS */
        .custom-modal-backdrop { 
            position: fixed !important; 
            top: 0 !important; 
            left: 0 !important; 
            right: 0 !important; 
            bottom: 0 !important; 
            background-color: rgba(30, 34, 41, 0.6) !important; 
            z-index: 99999 !important; 
            display: none; 
            align-items: center; 
            justify-content: center; 
            padding: 15px; 
        }
        .custom-modal-backdrop:target { display: flex !important; }
        .custom-popup-window { 
            background-color: #ffffff !important; 
            width: 100% !important; 
            max-width: 520px !important; 
            max-height: 90vh !important; 
            border-radius: 14px !important; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.25) !important; 
            overflow: hidden; 
            display: flex !important;
            flex-direction: column !important;
            animation: popIn 0.2s ease-out; 
        }
        .popup-header { 
            flex-shrink: 0;
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 16px 20px; 
            border-bottom: 1px solid #edf2f7; 
            background-color: #ffffff;
        }
        .popup-body { 
            flex: 1 1 auto;
            overflow-y: auto !important; 
            padding: 20px; 
            box-sizing: border-box; 
        }
        .popup-footer { 
            flex-shrink: 0;
            padding: 14px 20px; 
            background-color: #f8fafc; 
            border-top: 1px solid #edf2f7; 
            display: flex; 
            justify-content: flex-end; 
            gap: 10px; 
        }
        .close-popup-btn { text-decoration: none; color: #a0aec0; font-size: 22px; font-weight: bold; line-height: 1; }
        @keyframes popIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        /* Camera Viewport & 4-Photo Matrix Grid */
        .camera-viewport {
            width: 100%;
            height: 180px;
            background-color: #1a202c;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .camera-viewport video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .photo-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-top: 10px;
        }
        .photo-slot {
            width: 100%;
            height: 65px;
            border: 2px dashed #cbd5e1;
            border-radius: 6px;
            background-color: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        .photo-slot img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-slot-label {
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
        }
        .photo-slot.active-slot {
            border-color: var(--subdivision-orange);
            background-color: rgba(230, 106, 0, 0.05);
        }
        .photo-slot.completed-slot {
            border: 2px solid #22c55e;
        }
    </style>
</head>
<body>

<div class="page-wrapper">
    
    <!-- Sidebar -->
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
                <li><a href="requests.php" class="nav-link"><i class="fa fa-file-alt"></i> Requests</a></li>
                <li><a href="billing.php" class="nav-link"><i class="fa fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link"><i class="fa fa-money-bill-transfer"></i> Expenses</a></li>
                <li><a href="guards.php" class="nav-link active"><i class="fa fa-user-lock"></i> Staff Guards</a></li>
            </ul>
        </div>
        <div class="logout-btn-container">
            <hr class="mx-3 text-muted">
            <a href="../logout.php" class="nav-link logout-btn"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Main Workspace -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 page-title">Security Personnel Accounts</h1>
                <p class="text-muted small mb-0">Authorize guard terminals, deploy active system access credentials, or terminate expired personnel keys.</p>
            </div>
            <a href="#provisionGuardModal" onclick="startCamera('add')" class="btn btn-orange"><i class="fa fa-user-plus me-2"></i> Provision Staff Guard</a>
        </div>

        <!-- Alert System Notifications -->
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success d-flex align-items-center small p-3 mb-4" style="border-radius:8px;">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i> <div><?php echo $success_msg; ?></div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger d-flex align-items-center small p-3 mb-4" style="border-radius:8px;">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i> <div><strong>Provisioning Warning:</strong> <?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <!-- Active Guards Listing Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table custom-table">
                    <thead>
                        <tr>
                            <th>Guard Photo</th>
                            <th>Account Terminal User ID</th>
                            <th>Station Login Username</th>
                            <th>Email Address</th>
                            <th>Date Provisioned</th>
                            <th class="text-end" style="padding-right:24px;">Action Controls</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($guards)): ?>
                            <?php foreach ($guards as $row): ?>
                                <?php 
                                    $imgPath = !empty($row['image']) && file_exists('../uploads/guards/' . $row['image']) 
                                        ? '../uploads/guards/' . $row['image'] 
                                        : 'https://via.placeholder.com/100?text=Guard';
                                ?>
                                <tr>
                                    <td>
                                        <img src="<?php echo $imgPath; ?>" alt="Guard Photo" class="guard-avatar">
                                    </td>
                                    <td><span class="badge bg-light text-secondary border px-2.5 py-1.5 fw-bold">GUARD-0<?php echo $row['id']; ?></span></td>
                                    <td><strong class="text-dark"><i class="fa fa-shield me-1.5 text-secondary" style="font-size:12px;"></i><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td class="text-muted small"><?php echo date('M d, Y, g:i A', strtotime($row['created_at'])); ?></td>
                                    <td class="text-end" style="padding-right:24px;">
                                        <div class="d-inline-flex gap-1">
                                            <a href="#viewGuardModal_<?php echo $row['id']; ?>" class="action-link btn-view-link"><i class="fa fa-eye"></i> View</a>
                                            <a href="#editGuardModal_<?php echo $row['id']; ?>" onclick="startCamera('edit_<?php echo $row['id']; ?>')" class="action-link btn-edit-link"><i class="fa fa-pen-to-square"></i> Edit</a>
                                            <a href="guards.php?revoke_guard_id=<?php echo $row['id']; ?>" onclick="return confirm('Completely revoke terminal access rights for <?php echo htmlspecialchars($row['username']); ?>? This completely drops their login keys from Checkpoint gates.');" class="action-link btn-delete-link"><i class="fa fa-user-slash"></i> Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-user-shield d-block mb-2 fs-3 text-secondary"></i>No customized security guard terminal nodes found active on the network framework.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ================= PROVISION GUARD MODAL WITH 4-PHOTO FACE RECOGNITION ================= -->
<div id="provisionGuardModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark" style="font-size:17px;"><i class="fa-solid fa-camera text-orange me-1.5"></i>Provision Security Guard Account</h5>
            <a href="#" class="close-popup-btn" onclick="stopAllCameras()">&times;</a>
        </div>
        <form action="guards.php" method="POST" onsubmit="return validateCameraSubmission('add')">
            <div class="popup-body">
                
                <!-- Live Camera Feed Container -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-bold m-0">Face Recognition Scan (4 Photos) <span class="text-danger">*</span></label>
                        <span id="add_status_badge" class="badge bg-warning text-dark">Captured: 0 / 4</span>
                    </div>

                    <div class="camera-viewport mb-2 border">
                        <video id="add_guard_video" autoplay playsinline></video>
                        <canvas id="add_guard_canvas" style="display:none;"></canvas>
                    </div>

                    <!-- 4 Thumbnail Slots -->
                    <div class="photo-grid-4 mb-2">
                        <div class="photo-slot active-slot" id="add_slot_1">
                            <span class="photo-slot-label">1</span>
                        </div>
                        <div class="photo-slot" id="add_slot_2">
                            <span class="photo-slot-label">2</span>
                        </div>
                        <div class="photo-slot" id="add_slot_3">
                            <span class="photo-slot-label">3</span>
                        </div>
                        <div class="photo-slot" id="add_slot_4">
                            <span class="photo-slot-label">4</span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-center gap-2 mb-1">
                        <button type="button" id="snap_add_cam_btn" onclick="takeSnapshot('add')" class="btn btn-sm btn-orange w-100 py-1.5"><i class="fa fa-camera me-1"></i> Capture Photo (<span id="add_snap_num">1</span>/4)</button>
                        <button type="button" id="retake_add_cam_btn" onclick="resetSnapshots('add')" class="btn btn-sm btn-secondary text-dark" style="display:none;"><i class="fa fa-rotate-right me-1"></i> Retake All</button>
                    </div>

                    <!-- 4 Hidden Inputs -->
                    <input type="hidden" name="captured_image_1" id="add_guard_image_1">
                    <input type="hidden" name="captured_image_2" id="add_guard_image_2">
                    <input type="hidden" name="captured_image_3" id="add_guard_image_3">
                    <input type="hidden" name="captured_image_4" id="add_guard_image_4">
                </div>

                <div class="mb-2.5">
                    <label class="form-label">Station Login Username Handle</label>
                    <input type="text" name="username" class="form-control p-2" required placeholder="e.g., guard_alpha">
                </div>

                <div class="mb-2.5">
                    <label class="form-label">Corporate Email Address</label>
                    <input type="email" name="email" class="form-control p-2" required placeholder="e.g., alpha_gate@resicured.com">
                </div>

                <div class="mb-1">
                    <label class="form-label">Access Terminal Password</label>
                    <input type="password" name="password" class="form-control p-2" required placeholder="Enter terminal password">
                </div>

            </div>
            <div class="popup-footer">
                <a href="#" class="btn btn-secondary btn-sm px-3 py-2 text-dark" onclick="stopAllCameras()" style="text-decoration:none; background-color:#edf2f7; border:1px solid #cbd5e1;">Cancel</a>
                <button type="submit" name="add_guard_btn" id="add_submit_btn" class="btn btn-orange btn-sm px-4 py-2" disabled>Provision Account</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= DYNAMIC VIEW & EDIT MODALS FOR EACH GUARD ================= -->
<?php foreach ($guards as $row): ?>
    <?php 
        $imgPath = !empty($row['image']) && file_exists('../uploads/guards/' . $row['image']) 
            ? '../uploads/guards/' . $row['image'] 
            : 'https://via.placeholder.com/150?text=Guard';
        $guardKey = "edit_" . $row['id'];
    ?>
    
    <!-- VIEW GUARD MODAL -->
    <div id="viewGuardModal_<?php echo $row['id']; ?>" class="custom-modal-backdrop">
        <div class="custom-popup-window">
            <div class="popup-header">
                <h5 class="m-0 fw-bold text-dark" style="font-size:17px;">
                    <i class="fa-solid fa-id-card text-primary me-2"></i>Guard Profile Card
                </h5>
                <a href="#" class="close-popup-btn">&times;</a>
            </div>
            <div class="popup-body">
                <div class="text-center mb-4">
                    <img src="<?php echo $imgPath; ?>" alt="Guard Photo" class="guard-avatar-lg mb-2">
                    <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($row['username']); ?></h5>
                    <span class="badge bg-light text-secondary border mt-1">GUARD-0<?php echo $row['id']; ?></span>
                </div>

                <div class="mb-3 p-3 bg-light rounded border">
                    <div class="text-muted small fw-bold text-uppercase mb-1">Email Address</div>
                    <div class="text-dark"><?php echo htmlspecialchars($row['email']); ?></div>
                </div>
                <div class="p-3 bg-light rounded border">
                    <div class="text-muted small fw-bold text-uppercase mb-1">Date Provisioned</div>
                    <div class="text-dark"><?php echo date('F d, Y, g:i A', strtotime($row['created_at'])); ?></div>
                </div>
            </div>
            <div class="popup-footer">
                <a href="#" class="btn btn-secondary btn-sm px-4 py-2 text-dark" style="text-decoration:none; background-color:#edf2f7; border:1px solid #cbd5e1;">Close</a>
            </div>
        </div>
    </div>

    <!-- EDIT GUARD MODAL -->
    <div id="editGuardModal_<?php echo $row['id']; ?>" class="custom-modal-backdrop">
        <div class="custom-popup-window">
            <div class="popup-header">
                <h5 class="m-0 fw-bold text-dark" style="font-size:17px;">
                    <i class="fa-solid fa-user-pen text-warning me-2"></i>Edit Guard Account
                </h5>
                <a href="#" class="close-popup-btn" onclick="stopAllCameras()">&times;</a>
            </div>
            <form action="guards.php" method="POST">
                <input type="hidden" name="guard_id" value="<?php echo $row['id']; ?>">
                <div class="popup-body">
                    
                    <!-- 4 Photo Recapture Area -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label fw-bold m-0">Recapture Face Snapshots <span class="text-muted fw-normal">(Optional)</span></label>
                            <span id="<?php echo $guardKey; ?>_status_badge" class="badge bg-secondary text-white">Captured: 0 / 4</span>
                        </div>

                        <div class="camera-viewport mb-2 border">
                            <video id="<?php echo $guardKey; ?>_guard_video" autoplay playsinline></video>
                            <canvas id="<?php echo $guardKey; ?>_guard_canvas" style="display:none;"></canvas>
                        </div>

                        <!-- 4 Slots -->
                        <div class="photo-grid-4 mb-2">
                            <div class="photo-slot active-slot" id="<?php echo $guardKey; ?>_slot_1">
                                <img src="<?php echo $imgPath; ?>" />
                            </div>
                            <div class="photo-slot" id="<?php echo $guardKey; ?>_slot_2">
                                <span class="photo-slot-label">2</span>
                            </div>
                            <div class="photo-slot" id="<?php echo $guardKey; ?>_slot_3">
                                <span class="photo-slot-label">3</span>
                            </div>
                            <div class="photo-slot" id="<?php echo $guardKey; ?>_slot_4">
                                <span class="photo-slot-label">4</span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-center gap-2 mb-1">
                            <button type="button" id="snap_<?php echo $guardKey; ?>_cam_btn" onclick="takeSnapshot('<?php echo $guardKey; ?>')" class="btn btn-sm btn-orange w-100 py-1.5"><i class="fa fa-camera me-1"></i> Capture Photo (<span id="<?php echo $guardKey; ?>_snap_num">1</span>/4)</button>
                            <button type="button" id="retake_<?php echo $guardKey; ?>_cam_btn" onclick="resetSnapshots('<?php echo $guardKey; ?>')" class="btn btn-sm btn-secondary text-dark" style="display:none;"><i class="fa fa-rotate-right me-1"></i> Retake All</button>
                        </div>

                        <!-- Hidden Inputs -->
                        <input type="hidden" name="captured_image_1" id="<?php echo $guardKey; ?>_guard_image_1">
                        <input type="hidden" name="captured_image_2" id="<?php echo $guardKey; ?>_guard_image_2">
                        <input type="hidden" name="captured_image_3" id="<?php echo $guardKey; ?>_guard_image_3">
                        <input type="hidden" name="captured_image_4" id="<?php echo $guardKey; ?>_guard_image_4">
                    </div>

                    <div class="mb-2.5">
                        <label class="form-label">Station Login Username Handle</label>
                        <input type="text" name="username" class="form-control p-2" value="<?php echo htmlspecialchars($row['username']); ?>" required>
                    </div>

                    <div class="mb-2.5">
                        <label class="form-label">Corporate Email Address</label>
                        <input type="email" name="email" class="form-control p-2" value="<?php echo htmlspecialchars($row['email']); ?>" required>
                    </div>

                    <div class="mb-1">
                        <label class="form-label">New Password Key <span class="text-muted fw-normal">(Optional)</span></label>
                        <input type="password" name="password" class="form-control p-2" placeholder="Leave blank to keep current password">
                    </div>

                </div>
                <div class="popup-footer">
                    <a href="#" class="btn btn-secondary btn-sm px-3 py-2 text-dark" onclick="stopAllCameras()" style="text-decoration:none; background-color:#edf2f7; border:1px solid #cbd5e1;">Cancel</a>
                    <button type="submit" name="edit_guard_btn" class="btn btn-orange btn-sm px-4 py-2">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

<?php endforeach; ?>

<!-- 4-PHOTO FACE RECOGNITION CAMERA JAVASCRIPT ENGINE -->
<script>
    const cameraStreams = {};
    const captureState = {};

    /**
     * Start WebRTC Camera stream
     */
    async function startCamera(mode) {
        const video = document.getElementById(mode + '_guard_video');
        if (!video) return;

        // Reset capture tracker for modal
        captureState[mode] = { count: 0, photos: [] };
        updateSlotUI(mode);

        try {
            stopCamera(mode);
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: "user" }, 
                audio: false 
            });
            
            cameraStreams[mode] = stream;
            video.srcObject = stream;
        } catch (err) {
            alert("Camera Access Error: Unable to access camera device. Please check browser permissions.");
            console.error(err);
        }
    }

    /**
     * Take a snapshot up to 4 photos
     */
    function takeSnapshot(mode) {
        const video = document.getElementById(mode + '_guard_video');
        const canvas = document.getElementById(mode + '_guard_canvas');

        if (!video || !video.srcObject) {
            alert("Camera feed is not active. Please allow camera permissions.");
            return;
        }

        if (!captureState[mode]) captureState[mode] = { count: 0, photos: [] };
        let currentCount = captureState[mode].count;

        if (currentCount >= 4) return;

        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;
        const context = canvas.getContext('2d');
        context.drawImage(video, 0, 0, canvas.width, canvas.height);

        const dataURL = canvas.toDataURL('image/jpeg', 0.9);
        currentCount++;
        
        captureState[mode].count = currentCount;
        captureState[mode].photos.push(dataURL);

        // Store into corresponding hidden input
        document.getElementById(mode + '_guard_image_' + currentCount).value = dataURL;

        // Render preview inside corresponding slot box
        const slot = document.getElementById(mode + '_slot_' + currentCount);
        if (slot) {
            slot.innerHTML = `<img src="${dataURL}" />`;
            slot.classList.remove('active-slot');
            slot.classList.add('completed-slot');
        }

        updateSlotUI(mode);
    }

    /**
     * Update labels, status badges, and button states for 4-photo progress
     */
    function updateSlotUI(mode) {
        const state = captureState[mode] || { count: 0 };
        const count = state.count;

        const snapNumSpan = document.getElementById(mode + '_snap_num');
        const statusBadge = document.getElementById(mode + '_status_badge');
        const snapBtn = document.getElementById('snap_' + mode + '_cam_btn');
        const retakeBtn = document.getElementById('retake_' + mode + '_cam_btn');
        const submitBtn = document.getElementById(mode + '_submit_btn');

        if (snapNumSpan) snapNumSpan.innerText = Math.min(count + 1, 4);

        if (statusBadge) {
            statusBadge.innerText = `Captured: ${count} / 4`;
            if (count === 4) {
                statusBadge.className = "badge bg-success text-white";
            } else {
                statusBadge.className = "badge bg-warning text-dark";
            }
        }

        // Highlight next active slot
        for (let i = 1; i <= 4; i++) {
            const slot = document.getElementById(mode + '_slot_' + i);
            if (slot && i === count + 1 && count < 4) {
                slot.classList.add('active-slot');
            }
        }

        if (count >= 4) {
            if (snapBtn) snapBtn.style.display = 'none';
            if (retakeBtn) retakeBtn.style.display = 'inline-block';
            if (submitBtn) submitBtn.disabled = false; // Enable submit button once all 4 are taken
        } else {
            if (snapBtn) snapBtn.style.display = 'inline-block';
            if (retakeBtn) retakeBtn.style.display = 'none';
            if (submitBtn) submitBtn.disabled = true;
        }
    }

    /**
     * Reset 4 captured snapshots and restart sequence
     */
    function resetSnapshots(mode) {
        captureState[mode] = { count: 0, photos: [] };

        for (let i = 1; i <= 4; i++) {
            const hiddenInput = document.getElementById(mode + '_guard_image_' + i);
            if (hiddenInput) hiddenInput.value = '';

            const slot = document.getElementById(mode + '_slot_' + i);
            if (slot) {
                slot.className = 'photo-slot';
                slot.innerHTML = `<span class="photo-slot-label">${i}</span>`;
            }
        }

        updateSlotUI(mode);
    }

    /**
     * Stop webcam feed
     */
    function stopCamera(mode) {
        if (cameraStreams[mode]) {
            cameraStreams[mode].getTracks().forEach(track => track.stop());
            delete cameraStreams[mode];
        }
    }

    /**
     * Stop all webcam feeds
     */
    function stopAllCameras() {
        Object.keys(cameraStreams).forEach(key => stopCamera(key));
    }

    /**
     * Validation check before submitting provision modal
     */
    function validateCameraSubmission(mode) {
        const state = captureState[mode];
        if (!state || state.count < 4) {
            alert("Please complete all 4 photo captures for face recognition accuracy.");
            return false;
        }
        stopAllCameras();
        return true;
    }

    window.addEventListener('hashchange', stopAllCameras);
</script>

</body>
</html>