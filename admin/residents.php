<?php 
session_start();

// 1. SECURITY UTILITY GATEWAY: Kick out unauthorized sessions
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

// 2. SAFE DATABASE ACCESS INTEGRATION
require_once '../config/database.php';

$success_msg = "";
$error_msg = "";

// Helper function to process and save Base64 media data
function saveBase64Media($base64_data, $user_id, $index = 1) {
    if (empty($base64_data)) return NULL;

    $parts = explode(";base64,", $base64_data);
    if (count($parts) < 2) return NULL;

    $media_base64 = base64_decode($parts[1]);
    
    // Extract mime-type to determine extension
    $mime_part = explode(":", $parts[0]);
    $mime_type = isset($mime_part[1]) ? explode(";", $mime_part[1])[0] : '';
    
    $ext = 'jpg'; // default fallback
    if (strpos($mime_type, 'video/webm') !== false) {
        $ext = 'webm';
    } elseif (strpos($mime_type, 'video/mp4') !== false) {
        $ext = 'mp4';
    }

    $file_name = 'face_res_' . $user_id . '_img' . $index . '_' . time() . '.' . $ext;
    $target_directory = '../uploads/faces/';
    
    if (!is_dir($target_directory)) {
        mkdir($target_directory, 0755, true);
    }
    
    $file_destination = $target_directory . $file_name;
    if (file_put_contents($file_destination, $media_base64)) {
        return 'uploads/faces/' . $file_name;
    }
    return NULL;
}

// ================= ACTION: PROCESS NEW RESIDENT REGISTRATION WITH FACE ID =================
if (isset($_POST['add_resident_btn'])) {
    $full_name = trim($_POST['full_name']);
    $resident_type = trim($_POST['resident_type'] ?? 'Homeowner');
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);
    $house_number = trim($_POST['house_number']);
    $vehicle_plate = trim($_POST['vehicle_plate']);
    $username = trim($_POST['username']);
    $password = password_hash(trim($_POST['password']), PASSWORD_BCRYPT);
    
    // Receive 4 individual base64 snapshot strings
    $img1 = $_POST['resident_img_1'] ?? '';
    $img2 = $_POST['resident_img_2'] ?? '';
    $img3 = $_POST['resident_img_3'] ?? '';
    $img4 = $_POST['resident_img_4'] ?? '';

    if (!empty($full_name) && !empty($username) && !empty($email) && !empty($img1)) {
        $conn->begin_transaction();
        try {
            $stmt1 = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'resident')");
            $stmt1->bind_param("sss", $username, $password, $email);
            $stmt1->execute();
            $new_user_id = $conn->insert_id;

            // Save individual image records
            $saved_file_path = saveBase64Media($img1, $new_user_id, 1);
            $path2 = saveBase64Media($img2, $new_user_id, 2);
            $path3 = saveBase64Media($img3, $new_user_id, 3);
            $path4 = saveBase64Media($img4, $new_user_id, 4);

            $stmt2 = $conn->prepare("INSERT INTO residents (user_id, full_name, resident_type, house_number, face_template_path, contact_number, registered_vehicle_plate) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt2->bind_param("issssss", $new_user_id, $full_name, $resident_type, $house_number, $saved_file_path, $contact_number, $vehicle_plate);
            $stmt2->execute();

            $conn->commit();
            $success_msg = "Resident profile successfully provisioned!";

            // Connect and sync with the Python Face Recognition API Engine
            $python_url = 'http://127.0.0.1:5000/api/process-face';
            $payload = json_encode([
                'user_id' => $new_user_id,
                'image_paths' => array_filter([$saved_file_path, $path2, $path3, $path4])
            ]);

            $ch = curl_init($python_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            curl_close($ch);

        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Transaction failed: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in all mandatory core profile fields and snap images.";
    }
}

// ================= ACTION: PROCESS RESIDENT UPDATE TRANSACTION =================
if (isset($_POST['update_resident_btn'])) {
    $resident_id = intval($_POST['edit_resident_id']);
    $user_id = intval($_POST['edit_user_id']);
    $full_name = trim($_POST['edit_full_name']);
    $resident_type = trim($_POST['edit_resident_type'] ?? 'Homeowner');
    $email = trim($_POST['edit_email']);
    $contact_number = trim($_POST['edit_contact_number']);
    $house_number = trim($_POST['edit_house_number']);
    $vehicle_plate = trim($_POST['edit_vehicle_plate']);
    $username = trim($_POST['edit_username']);

    // Receive 4 individual updated snapshot strings
    $img1 = $_POST['edit_resident_img_1'] ?? '';
    $img2 = $_POST['edit_resident_img_2'] ?? '';
    $img3 = $_POST['edit_resident_img_3'] ?? '';
    $img4 = $_POST['edit_resident_img_4'] ?? '';

    if (!empty($full_name) && !empty($username) && !empty($email)) {
        $conn->begin_transaction();
        try {
            $stmt1 = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
            $stmt1->bind_param("ssi", $username, $email, $user_id);
            $stmt1->execute();

            // Handle face snapshot collection upgrades if recaptured
            if (!empty($img1)) {
                $img_check = $conn->prepare("SELECT face_template_path FROM residents WHERE id = ?");
                $img_check->bind_param("i", $resident_id);
                $img_check->execute();
                $res_img = $img_check->get_result()->fetch_assoc();
                
                if (!empty($res_img['face_template_path']) && file_exists('../' . $res_img['face_template_path'])) {
                    unlink('../' . $res_img['face_template_path']);
                }

                $new_file_path = saveBase64Media($img1, $user_id, 1);
                $path2 = saveBase64Media($img2, $user_id, 2);
                $path3 = saveBase64Media($img3, $user_id, 3);
                $path4 = saveBase64Media($img4, $user_id, 4);

                if ($new_file_path) {
                    $stmt_img = $conn->prepare("UPDATE residents SET face_template_path = ? WHERE id = ?");
                    $stmt_img->bind_param("si", $new_file_path, $resident_id);
                    $stmt_img->execute();

                    // Notify Python API of update variations
                    $python_url = 'http://127.0.0.1:5000/api/process-face';
                    $payload = json_encode([
                        'user_id' => $user_id,
                        'image_paths' => array_filter([$new_file_path, $path2, $path3, $path4])
                    ]);

                    $ch = curl_init($python_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }

            $stmt2 = $conn->prepare("UPDATE residents SET full_name = ?, resident_type = ?, house_number = ?, contact_number = ?, registered_vehicle_plate = ? WHERE id = ?");
            $stmt2->bind_param("sssssi", $full_name, $resident_type, $house_number, $contact_number, $vehicle_plate, $resident_id);
            $stmt2->execute();

            if (!empty($_POST['edit_password'])) {
                $new_pass = password_hash(trim($_POST['edit_password']), PASSWORD_BCRYPT);
                $stmt3 = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt3->bind_param("si", $new_pass, $user_id);
                $stmt3->execute();
            }

            $conn->commit();
            $success_msg = "Resident network profile successfully updated!";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Update execution error: " . $e->getMessage();
        }
    } else {
        $error_msg = "All mandatory system layout inputs must be filled.";
    }
}

// ================= ACTION: PROCESS TARGET RECORD REMOVAL ROUTE =================
if (isset($_POST['delete_resident_btn'])) {
    $resident_id = intval($_POST['delete_resident_id']);
    $user_id = intval($_POST['delete_user_id']);

    $conn->begin_transaction();
    try {
        $img_check = $conn->prepare("SELECT face_template_path FROM residents WHERE id = ?");
        $img_check->bind_param("i", $resident_id);
        $img_check->execute();
        $res_img = $img_check->get_result()->fetch_assoc();
        
        if (!empty($res_img['face_template_path']) && file_exists('../' . $res_img['face_template_path'])) {
            unlink('../' . $res_img['face_template_path']);
        }

        $stmt1 = $conn->prepare("DELETE FROM residents WHERE id = ?");
        $stmt1->bind_param("i", $resident_id);
        $stmt1->execute();

        $stmt2 = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();

        $conn->commit();
        $success_msg = "Resident data structure completely removed.";
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Record dropping operation encountered a fault: " . $e->getMessage();
    }
}

$residents_query = $conn->query("SELECT * FROM residents ORDER BY id DESC");

// Fetch Metrics for KPI Stat Bar
$total_residents = $conn->query("SELECT COUNT(*) as cnt FROM residents")->fetch_assoc()['cnt'] ?? 0;
$total_vehicles = $conn->query("SELECT COUNT(*) as cnt FROM residents WHERE registered_vehicle_plate IS NOT NULL AND registered_vehicle_plate != ''")->fetch_assoc()['cnt'] ?? 0;
$total_faces = $conn->query("SELECT COUNT(*) as cnt FROM residents WHERE face_template_path IS NOT NULL AND face_template_path != ''")->fetch_assoc()['cnt'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Residents Management Directory</title>
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

        /* UNIFIED DASHBOARD SIDEBAR STYLING */
        .sidebar {
            width: 260px;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 24px 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: sticky;
            top: 0;
            height: 100vh;
        }

        .brand-logo-area {
            padding: 0 20px 20px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .brand-icon-box {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%);
            color: white;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.25);
        }

        .brand-logo-text {
            color: var(--text-heading);
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin: 0;
        }

        .sidebar-menu { list-style: none; padding: 0; margin: 16px 0 0 0; }

        .sidebar .nav-link {
            color: #4a5568 !important;
            font-size: 14px;
            font-weight: 500;
            padding: 12px 20px;
            margin: 4px 16px;
            border-radius: 8px;
            display: flex !important;
            align-items: center;
            gap: 12px;
            text-decoration: none !important;
            transition: all 0.2s ease;
        }

        .sidebar .nav-link:not(.active):hover {
            color: var(--subdivision-orange) !important;
            background-color: rgba(234, 88, 12, 0.08) !important;
        }

        .sidebar .nav-link.active {
            color: #ffffff !important;
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%) !important;
            font-weight: 600;
            border: none !important;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.25);
        }

        .sidebar .nav-link i { font-size: 16px; width: 20px; text-align: center; }

        .logout-btn { 
            background-color: #fef2f2; 
            color: #dc2626 !important; 
            border: 1px solid #fee2e2; 
        }
        .logout-btn:hover { background-color: #dc2626 !important; color: #ffffff !important; }

        /* MAIN CONTENT AREA */
        .main-content {
            flex-grow: 1;
            padding: 32px 40px;
            background-color: var(--bg-main);
            max-width: calc(100vw - 260px);
        }

        .dashboard-title { 
            color: var(--text-heading); 
            font-weight: 800; 
            font-size: 24px;
            letter-spacing: -0.5px;
            margin: 0; 
        }

        /* GRADIENT BUTTON ACCENTS */
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

        /* STAT CARDS */
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.04);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        /* SEARCH & TOOLBAR */
        .toolbar-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 20px;
        }

        .search-box {
            position: relative;
            max-width: 380px;
            width: 100%;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
        }

        .search-box input {
            padding-left: 38px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            font-size: 14px;
            font-weight: 500;
        }

        .search-box input:focus {
            border-color: var(--subdivision-orange);
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.12);
        }

        /* MODERN TABLE CARD */
        .table-card { 
            background: #ffffff; 
            border: 1px solid var(--border-color); 
            border-radius: var(--radius-lg); 
            overflow: hidden; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .modern-table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .modern-table thead th {
            background-color: #f8fafc;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .modern-table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-body);
            font-size: 13.5px;
        }

        .modern-table tbody tr:last-child td { border-bottom: none; }
        .modern-table tbody tr { transition: background-color 0.15s ease; }
        .modern-table tbody tr:hover { background-color: #f8fafc; }

        .avatar-thumbnail { 
            width: 44px; 
            height: 44px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 2px solid #ffffff; 
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            flex-shrink: 0;
        }

        .house-badge {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #e2e8f0;
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

        /* ACTION BUTTONS */
        .btn-action-view {
            background-color: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            transition: all 0.15s ease;
        }
        .btn-action-view:hover {
            background-color: #dbeafe;
            color: #1d4ed8;
        }

        .btn-action-edit {
            background-color: #fffbebf5;
            color: #d97706;
            border: 1px solid #fde68a;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            transition: all 0.15s ease;
        }
        .btn-action-edit:hover {
            background-color: #fde68a;
            color: #b45309;
        }

        .btn-action-delete {
            background-color: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            transition: all 0.15s ease;
        }
        .btn-action-delete:hover {
            background-color: #fca5a5;
            color: #991b1b;
        }
        
        .admin-cam-box { 
            background: #0f172a; 
            border-radius: var(--radius-md); 
            overflow: hidden; 
            width: 100%; 
            height: 220px; 
            position: relative; 
            border: 2px dashed #64748b; 
        }
        #adminWebcam, #editAdminWebcam { 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            display: none; 
            position: absolute;
            top: 0;
            left: 0;
            z-index: 10;
        }

        .cam-placeholder-box {
            position: absolute; 
            width: 100%; 
            height: 100%;
            top: 0; 
            left: 0; 
            z-index: 5; 
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .current-face-preview {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e2e8f0;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }

        .photo-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 12px; }
        .grid-snap-box { width: 100%; height: 64px; background: #f1f5f9; border-radius: 6px; overflow: hidden; position: relative; display: flex; align-items: center; justify-content: center; border: 2px dashed #cbd5e1; }
        .grid-snap-box img { width: 100%; height: 100%; object-fit: cover; }
        .grid-snap-label { position: absolute; bottom: 2px; font-size: 8.5px; font-weight: 700; background: rgba(15,23,42,0.75); color: #fff; padding: 1px 4px; border-radius: 3px; z-index: 2; }
        
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
                <div class="brand-icon-box">
                    <i class="fa fa-shield-halved"></i>
                </div>
                <h4 class="brand-logo-text">ResiCured</h4>
            </div>
             <ul class="sidebar-menu mt-3">
                <li><a href="dashboard.php" class="nav-link"><i class="fa fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="events.php" class="nav-link"><i class="fa fa-calendar-alt"></i> Events</a></li>
                <li><a href="residents.php" class="nav-link active"><i class="fa fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link"><i class="fa fa-user-shield"></i> Personnel</a></li>
                <li><a href="requests.php" class="nav-link"><i class="fa fa-file-alt"></i> Requests & Concerns</a></li>
                <li><a href="billing.php" class="nav-link"><i class="fa fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link"><i class="fa fa-money-bill-transfer"></i> Expenses</a></li>
                <li><a href="guards.php" class="nav-link"><i class="fa fa-user-lock"></i> Staff Guards</a></li>
            </ul>
        </div>
        <div>
            <a href="../logout.php" class="nav-link logout-btn"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="main-content">
        <!-- HEADER TITLE -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="dashboard-title">Residents Directory</h1>
                <p class="text-muted small mb-0 mt-1">Manage subdivision resident records, access authorization, and biometric mappings.</p>
            </div>
            <button class="btn btn-gradient-orange fw-bold px-4 py-2" style="border-radius: var(--radius-md); font-size: 14px;" data-bs-toggle="modal" data-bs-target="#addResidentModal">
                <i class="fa fa-plus me-2"></i>Add Resident
            </button>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #eff6ff; color: #2563eb;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Total Residents</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $total_residents; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #fef3c7; color: #d97706;">
                        <i class="fa-solid fa-car"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Registered Vehicles</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $total_vehicles; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #f0fdf4; color: #16a34a;">
                        <i class="fa-solid fa-face-smile"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Biometric Profiles</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $total_faces; ?> / <?php echo $total_residents; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if(!empty($success_msg)): ?>
            <div class="alert alert-success border-0 shadow-sm mb-3 small fw-medium" style="border-radius: var(--radius-md);"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success_msg; ?></div>
        <?php endif; ?>
        <?php if(!empty($error_msg)): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-3 small fw-medium" style="border-radius: var(--radius-md);"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <!-- TOOLBAR WITH LIVE SEARCH -->
        <div class="toolbar-card d-flex justify-content-between align-items-center">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="residentSearchInput" class="form-control" placeholder="Search by name, house no, or email...">
            </div>
            <span class="text-muted small fw-semibold">Total Records: <span class="text-dark fw-bold" id="visibleCount"><?php echo $total_residents; ?></span></span>
        </div>

        <!-- SIMPLIFIED TABLE CARD -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table modern-table align-middle" id="residentsTable">
                    <thead>
                        <tr>
                            <th>Resident Profile</th>
                            <th>House No.</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($residents_query && $residents_query->num_rows > 0): ?>
                            <?php while($row = $residents_query->fetch_assoc()): 
                                $u_id = $row['user_id'];
                                $u_check = $conn->query("SELECT email, username FROM users WHERE id = $u_id");
                                $u_data = ($u_check && $u_check->num_rows > 0) ? $u_check->fetch_assoc() : ['email' => '', 'username' => ''];
                                $file_path = !empty($row['face_template_path']) ? htmlspecialchars($row['face_template_path']) : '';
                                $is_video = preg_match('/\.(webm|mp4)$/i', $file_path);
                                $res_type = !empty($row['resident_type']) ? htmlspecialchars($row['resident_type']) : 'Homeowner';
                            ?>
                                <tr class="resident-row">
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if ($is_video): ?>
                                                <video src="../<?php echo $file_path; ?>" class="avatar-thumbnail" muted loop onmouseover="this.play()" onmouseout="this.pause()"></video>
                                            <?php else: ?>
                                                <img src="../<?php echo !empty($file_path) ? $file_path : 'assets/images/default-avatar.png'; ?>" class="avatar-thumbnail" alt="Face Portrait">
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-bold text-dark search-target-name"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                                <span class="badge bg-light text-dark border mt-1" style="font-size: 10px;">
                                                    <i class="fa-solid fa-user-tag me-1 text-muted"></i><?php echo $res_type; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="house-badge search-target-house"><i class="fa-solid fa-house-user opacity-75"></i><?php echo htmlspecialchars($row['house_number']); ?></span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-action-view me-1 view-resident-btn"
                                                data-name="<?php echo htmlspecialchars($row['full_name']); ?>"
                                                data-type="<?php echo $res_type; ?>"
                                                data-house="<?php echo htmlspecialchars($row['house_number']); ?>"
                                                data-vehicle="<?php echo htmlspecialchars($row['registered_vehicle_plate']); ?>"
                                                data-contact="<?php echo htmlspecialchars($row['contact_number']); ?>"
                                                data-username="<?php echo htmlspecialchars($u_data['username']); ?>"
                                                data-email="<?php echo htmlspecialchars($u_data['email']); ?>"
                                                data-face="../<?php echo !empty($file_path) ? $file_path : 'assets/images/default-avatar.png'; ?>">
                                            <i class="fa-regular fa-eye me-1"></i>View
                                        </button>
                                        <button class="btn btn-action-edit me-1 edit-resident-btn" 
                                                data-id="<?php echo $row['id']; ?>"
                                                data-userid="<?php echo $row['user_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($row['full_name']); ?>"
                                                data-type="<?php echo $res_type; ?>"
                                                data-house="<?php echo htmlspecialchars($row['house_number']); ?>"
                                                data-vehicle="<?php echo htmlspecialchars($row['registered_vehicle_plate']); ?>"
                                                data-contact="<?php echo htmlspecialchars($row['contact_number']); ?>"
                                                data-username="<?php echo htmlspecialchars($u_data['username']); ?>"
                                                data-email="<?php echo htmlspecialchars($u_data['email']); ?>"
                                                data-face="../<?php echo !empty($file_path) ? $file_path : 'assets/images/default-avatar.png'; ?>">
                                            <i class="fa-regular fa-pen-to-square me-1"></i>Edit
                                        </button>
                                        <button class="btn btn-action-delete delete-resident-trigger" 
                                                data-id="<?php echo $row['id']; ?>"
                                                data-userid="<?php echo $row['user_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($row['full_name']); ?>">
                                            <i class="fa-regular fa-trash-can me-1"></i>Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr id="noResultsRow">
                                <td colspan="3" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-users-slash fs-3 d-block mb-2 opacity-50"></i>
                                    No community residents registered yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: VIEW RESIDENT DETAILS -->
<div class="modal fade" id="viewResidentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold text-dark fs-6"><i class="fa fa-id-card me-2 text-primary"></i>Resident Information</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <div class="text-center mb-4">
                    <div id="viewMediaWrapper" class="d-flex justify-content-center mb-3">
                        <img id="viewFaceImageDisplay" src="" class="current-face-preview" alt="Thumbnail" style="display: none;">
                        <video id="viewFaceVideoDisplay" src="" class="current-face-preview" muted loop autoplay style="display: none;"></video>
                    </div>
                    <h5 id="viewFullName" class="fw-bold text-dark mb-1"></h5>
                    <span id="viewUsername" class="badge bg-light text-secondary border"></span>
                </div>
                <div class="list-group list-group-flush border-top pt-2">
                    <div class="list-group-item px-0 d-flex justify-content-between align-items-center border-0 py-2">
                        <span class="text-muted small"><i class="fa-solid fa-user-tag me-2 opacity-75"></i>Resident Type</span>
                        <span id="viewResidentType" class="fw-bold text-dark small"></span>
                    </div>
                    <div class="list-group-item px-0 d-flex justify-content-between align-items-center border-0 py-2">
                        <span class="text-muted small"><i class="fa-solid fa-house-user me-2 opacity-75"></i>House Number</span>
                        <span id="viewHouseNumber" class="fw-bold text-dark small"></span>
                    </div>
                    <div class="list-group-item px-0 d-flex justify-content-between align-items-center border-0 py-2">
                        <span class="text-muted small"><i class="fa-solid fa-envelope me-2 opacity-75"></i>Email Address</span>
                        <span id="viewEmail" class="fw-semibold text-dark small"></span>
                    </div>
                    <div class="list-group-item px-0 d-flex justify-content-between align-items-center border-0 py-2">
                        <span class="text-muted small"><i class="fa-solid fa-phone me-2 opacity-75"></i>Contact Number</span>
                        <span id="viewContactNumber" class="fw-semibold text-dark small"></span>
                    </div>
                    <div class="list-group-item px-0 d-flex justify-content-between align-items-center border-0 py-2">
                        <span class="text-muted small"><i class="fa-solid fa-car me-2 opacity-75"></i>Vehicle Plate</span>
                        <span id="viewVehiclePlate"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-sm btn-secondary fw-semibold px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ADD RESIDENT -->
<div class="modal fade" id="addResidentModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold text-dark fs-6"><i class="fa fa-user-plus me-2 text-warning"></i>Provision Resident Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="stopAdminCam()"></button>
            </div>
            <form action="residents.php" method="POST" id="addResidentForm">
                <div class="modal-body p-4 bg-white">
                    <div class="row g-4">
                        <div class="col-md-6 d-flex flex-column gap-3">
                            <div>
                                <label class="form-label small fw-bold text-dark">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control" placeholder="e.g., Juan Dela Cruz" required>
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-dark">Resident Type <span class="text-danger">*</span></label>
                                <select name="resident_type" class="form-select" required>
                                    <option value="Homeowner" selected>Homeowner</option>
                                    <option value="Tenant">Tenant</option>
                                </select>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-dark">House No. <span class="text-danger">*</span></label>
                                    <input type="text" name="house_number" class="form-control" placeholder="Blk 4 Lot 12" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-dark">Vehicle Plate</label>
                                    <input type="text" name="vehicle_plate" class="form-control" placeholder="e.g., NQR-723">
                                </div>
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-dark">Contact Number</label>
                                <input type="text" name="contact_number" class="form-control" placeholder="e.g., 0917XXXXXXX">
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-dark">Username <span class="text-danger">*</span></label>
                                    <input type="text" name="username" class="form-control" placeholder="username" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-dark">Password <span class="text-danger">*</span></label>
                                    <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                                </div>
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-dark">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" placeholder="name@domain.com" required>
                            </div>
                        </div>

                        <div class="col-md-6 border-start ps-4 text-center">
                            <h6 class="fw-bold text-secondary text-start border-bottom pb-2 mb-3" style="font-size:13px;">Biometric Verification Photos (Take 4)</h6>
                            
                            <input type="hidden" name="resident_img_1" id="res_img_1">
                            <input type="hidden" name="resident_img_2" id="res_img_2">
                            <input type="hidden" name="resident_img_3" id="res_img_3">
                            <input type="hidden" name="resident_img_4" id="res_img_4">

                            <div class="admin-cam-box mb-3 mx-auto">
                                <video id="adminWebcam" autoplay playsinline muted></video>
                                <div id="camPlaceholderText" class="cam-placeholder-box text-muted small">
                                    <i class="fa fa-video fs-3 mb-2 opacity-50" style="color: var(--subdivision-orange);"></i>
                                    Webcam Stream Inactive
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="button" id="toggleCamBtn" class="btn btn-sm btn-dark fw-semibold py-2" onclick="startAdminCam()"><i class="fa fa-video me-2"></i>Turn On Device Camera</button>
                                <button type="button" id="captureSnapBtn" class="btn btn-sm btn-gradient-orange fw-bold py-2" onclick="takeSnapshotStep('add')" disabled><i class="fa fa-camera me-2"></i>Snap Photo (<span id="snapCountLabel">0</span>/4)</button>
                            </div>
                            
                            <div class="photo-grid">
                                <div class="grid-snap-box" id="box_1"><span class="grid-snap-label">Front</span><i class="fa fa-image text-muted opacity-50"></i></div>
                                <div class="grid-snap-box" id="box_2"><span class="grid-snap-label">Left</span><i class="fa fa-image text-muted opacity-50"></i></div>
                                <div class="grid-snap-box" id="box_3"><span class="grid-snap-label">Right</span><i class="fa fa-image text-muted opacity-50"></i></div>
                                <div class="grid-snap-box" id="box_4"><span class="grid-snap-label">Tilt</span><i class="fa fa-image text-muted opacity-50"></i></div>
                            </div>
                            <div id="captureSuccessStatus" class="mt-2 text-success small fw-bold" style="display:none;"><i class="fa fa-check-circle me-1"></i>All 4 snapshots mapped!</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-sm btn-secondary fw-semibold px-3" data-bs-dismiss="modal" onclick="stopAdminCam()">Discard</button>
                    <button type="submit" name="add_resident_btn" id="submitFormBtn" class="btn btn-sm btn-gradient-orange fw-bold px-4" disabled>Save Profile</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EDIT RESIDENT -->
<div class="modal fade" id="editResidentModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold text-dark fs-6"><i class="fa fa-user-pen me-2 text-warning"></i>Modify Resident Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="stopEditAdminCam()"></button>
            </div>
            <form action="residents.php" method="POST" id="editResidentForm">
                <div class="modal-body p-4 bg-white">
                    <div class="row g-4">
                        <div class="col-md-6 d-flex flex-column gap-3">
                            <input type="hidden" name="edit_resident_id" id="editResidentId">
                            <input type="hidden" name="edit_user_id" id="editUserId">

                            <div>
                                <label class="form-label small fw-bold text-dark">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="edit_full_name" id="editFullName" class="form-control" required>
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-dark">Resident Type <span class="text-danger">*</span></label>
                                <select name="edit_resident_type" id="editResidentType" class="form-select" required>
                                    <option value="Homeowner">Homeowner</option>
                                    <option value="Tenant">Tenant</option>
                                </select>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-dark">House No. <span class="text-danger">*</span></label>
                                    <input type="text" name="edit_house_number" id="editHouseNumber" class="form-control" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-dark">Vehicle Plate</label>
                                    <input type="text" name="edit_vehicle_plate" id="editVehiclePlate" class="form-control">
                                </div>
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-dark">Contact Number</label>
                                <input type="text" name="edit_contact_number" id="editContactNumber" class="form-control">
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-dark">Username <span class="text-danger">*</span></label>
                                    <input type="text" name="edit_username" id="editUsername" class="form-control" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-dark">Password (Leave blank)</label>
                                    <input type="password" name="edit_password" class="form-control" placeholder="••••••••">
                                </div>
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-dark">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="edit_email" id="editEmail" class="form-control" required>
                            </div>
                        </div>

                        <div class="col-md-6 border-start ps-4 text-center">
                            <h6 class="fw-bold text-secondary text-start border-bottom pb-2 mb-3" style="font-size:13px;">Registered Face Record</h6>
                            
                            <input type="hidden" name="edit_resident_img_1" id="edit_res_img_1">
                            <input type="hidden" name="edit_resident_img_2" id="edit_res_img_2">
                            <input type="hidden" name="edit_resident_img_3" id="edit_res_img_3">
                            <input type="hidden" name="edit_resident_img_4" id="edit_res_img_4">
                            
                            <div id="editFaceStaticState" class="mb-3">
                                <div id="editMediaWrapper" class="mb-3 d-flex justify-content-center">
                                    <img id="editFaceImageDisplay" src="" class="current-face-preview" alt="Thumbnail" style="display: none;">
                                    <video id="editFaceVideoDisplay" src="" class="current-face-preview" muted loop autoplay style="display: none;"></video>
                                </div>
                                <p class="text-muted small">Biometric matrix active and saved.</p>
                                <button type="button" class="btn btn-sm btn-outline-dark fw-bold px-3 py-2" onclick="switchToCamRecapture()">
                                    <i class="fa fa-arrows-rotate me-2"></i>Recapture Face ID
                                </button>
                            </div>

                            <div id="editFaceCamState" style="display:none;">
                                <div class="admin-cam-box mb-3 mx-auto">
                                    <video id="editAdminWebcam" autoplay playsinline muted></video>
                                    <div id="editCamPlaceholderText" class="cam-placeholder-box text-muted small">
                                        <i class="fa fa-camera fs-3 mb-2 opacity-50" style="color: var(--subdivision-orange);"></i>
                                        Camera System Idle
                                    </div>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="button" id="editCaptureSnapBtn" class="btn btn-sm btn-gradient-orange fw-bold py-2" onclick="takeSnapshotStep('edit')"><i class="fa fa-camera me-2"></i>Snap Photo (<span id="editSnapCountLabel">0</span>/4)</button>
                                    <button type="button" class="btn btn-sm btn-light border small text-secondary py-1" onclick="cancelCamRecapture()">Cancel Recapture</button>
                                </div>
                                <div class="photo-grid">
                                    <div class="grid-snap-box" id="edit_box_1"><span class="grid-snap-label">Front</span><i class="fa fa-image text-muted opacity-50"></i></div>
                                    <div class="grid-snap-box" id="edit_box_2"><span class="grid-snap-label">Left</span><i class="fa fa-image text-muted opacity-50"></i></div>
                                    <div class="grid-snap-box" id="edit_box_3"><span class="grid-snap-label">Right</span><i class="fa fa-image text-muted opacity-50"></i></div>
                                    <div class="grid-snap-box" id="edit_box_4"><span class="grid-snap-label">Tilt</span><i class="fa fa-image text-muted opacity-50"></i></div>
                                </div>
                                <div id="editCaptureSuccessStatus" class="mt-2 text-success small fw-bold" style="display:none;"><i class="fa fa-check-circle me-1"></i>New snapshots mapped!</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-sm btn-secondary fw-semibold px-3" data-bs-dismiss="modal" onclick="stopEditAdminCam()">Cancel</button>
                    <button type="submit" name="update_resident_btn" class="btn btn-sm btn-gradient-orange fw-bold px-4">Update Details</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: DELETE CONFIRMATION -->
<div class="modal fade" id="deleteResidentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold text-danger fs-6"><i class="fa fa-triangle-exclamation me-2"></i>Confirm Removal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="residents.php" method="POST">
                <div class="modal-body p-4 text-center bg-white">
                    <i class="fa fa-trash-can text-danger fs-1 mb-3 opacity-75"></i>
                    <p class="text-dark fw-semibold mb-1" style="font-size: 15px;">Delete resident permanently?</p>
                    <p class="text-muted small mb-0" id="deleteTargetText"></p>
                    
                    <input type="hidden" name="delete_resident_id" id="deleteResidentId">
                    <input type="hidden" name="delete_user_id" id="deleteUserId">
                </div>
                <div class="modal-footer bg-light border-0 justify-content-center">
                    <button type="button" class="btn btn-sm btn-secondary fw-semibold px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_resident_btn" class="btn btn-sm btn-danger fw-bold px-4">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<canvas id="hiddenCaptureCanvas" style="display:none;" width="640" height="480"></canvas>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const viewModal = new bootstrap.Modal(document.getElementById('viewResidentModal'));
    const editModal = new bootstrap.Modal(document.getElementById('editResidentModal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteResidentModal'));

    // Live Instant Search Filter
    const searchInput = document.getElementById('residentSearchInput');
    const tableRows = document.querySelectorAll('#residentsTable .resident-row');
    const visibleCount = document.getElementById('visibleCount');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            let count = 0;

            tableRows.forEach(row => {
                const name = row.querySelector('.search-target-name')?.textContent.toLowerCase() || '';
                const house = row.querySelector('.search-target-house')?.textContent.toLowerCase() || '';

                if (name.includes(query) || house.includes(query)) {
                    row.style.display = '';
                    count++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (visibleCount) visibleCount.textContent = count;
        });
    }

    // Modal Trigger Handlers: View
    document.querySelectorAll('.view-resident-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('viewFullName').textContent = this.dataset.name;
            document.getElementById('viewResidentType').textContent = this.dataset.type || 'Homeowner';
            document.getElementById('viewUsername').textContent = '@' + this.dataset.username;
            document.getElementById('viewHouseNumber').textContent = this.dataset.house || 'N/A';
            document.getElementById('viewEmail').textContent = this.dataset.email || 'N/A';
            document.getElementById('viewContactNumber').textContent = this.dataset.contact || 'N/A';
            
            const plateContainer = document.getElementById('viewVehiclePlate');
            if (this.dataset.vehicle && this.dataset.vehicle.trim() !== '') {
                plateContainer.innerHTML = `<span class="plate-badge"><i class="fa fa-car"></i>${this.dataset.vehicle}</span>`;
            } else {
                plateContainer.innerHTML = `<span class="text-muted small fst-italic">No Vehicle</span>`;
            }

            const mediaPath = this.dataset.face;
            const imgEl = document.getElementById('viewFaceImageDisplay');
            const videoEl = document.getElementById('viewFaceVideoDisplay');

            if (mediaPath.match(/\.(webm|mp4)$/i)) {
                imgEl.style.display = 'none';
                videoEl.src = mediaPath;
                videoEl.style.display = 'block';
            } else {
                videoEl.style.display = 'none';
                imgEl.src = mediaPath;
                imgEl.style.display = 'block';
            }

            viewModal.show();
        });
    });

    // Modal Trigger Handlers: Edit
    document.querySelectorAll('.edit-resident-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('editResidentId').value = this.dataset.id;
            document.getElementById('editUserId').value = this.dataset.userid;
            document.getElementById('editFullName').value = this.dataset.name;
            document.getElementById('editResidentType').value = this.dataset.type || 'Homeowner';
            document.getElementById('editHouseNumber').value = this.dataset.house;
            document.getElementById('editVehiclePlate').value = this.dataset.vehicle;
            document.getElementById('editContactNumber').value = this.dataset.contact;
            document.getElementById('editUsername').value = this.dataset.username;
            document.getElementById('editEmail').value = this.dataset.email;
            
            const mediaPath = this.dataset.face;
            const imgEl = document.getElementById('editFaceImageDisplay');
            const videoEl = document.getElementById('editFaceVideoDisplay');

            if (mediaPath.match(/\.(webm|mp4)$/i)) {
                imgEl.style.display = 'none';
                videoEl.src = mediaPath;
                videoEl.style.display = 'block';
            } else {
                videoEl.style.display = 'none';
                imgEl.src = mediaPath;
                imgEl.style.display = 'block';
            }
            
            cancelCamRecapture();
            editModal.show();
        });
    });

    // Modal Trigger Handlers: Delete
    document.querySelectorAll('.delete-resident-trigger').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('deleteResidentId').value = this.dataset.id;
            document.getElementById('deleteUserId').value = this.dataset.userid;
            document.getElementById('deleteTargetText').innerText = "Resident: " + this.dataset.name;
            deleteModal.show();
        });
    });
});

// Webcam and Snapshot Management
let localStream = null;
let editLocalStream = null;
let currentPhotosCount = 0;
let editPhotosCount = 0;
const labels = ["Front", "Left", "Right", "Tilt"];

async function startAdminCam() {
    const video = document.getElementById('adminWebcam');
    const placeholder = document.getElementById('camPlaceholderText');
    const captureBtn = document.getElementById('captureSnapBtn');

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert("Camera API is not supported by your browser.");
        return;
    }

    try {
        localStream = await navigator.mediaDevices.getUserMedia({ 
            video: { width: { ideal: 640 }, height: { ideal: 480 } }, 
            audio: false 
        });
        video.srcObject = localStream;
        await video.play();
        
        placeholder.style.setProperty('display', 'none', 'important');
        video.style.display = 'block';
        captureBtn.disabled = false;
        
        currentPhotosCount = 0;
        document.getElementById('snapCountLabel').innerText = "0";
        document.getElementById('submitFormBtn').disabled = true;
        document.getElementById('captureSuccessStatus').style.display = 'none';
        for(let i=1; i<=4; i++) {
            document.getElementById(`box_${i}`).innerHTML = `<span class="grid-snap-label">${labels[i-1]}</span><i class="fa fa-image text-muted opacity-50"></i>`;
            document.getElementById(`res_img_${i}`).value = "";
        }
    } catch(err) {
        alert("Camera stream access denied: " + err.message);
    }
}

async function startEditAdminCam() {
    const video = document.getElementById('editAdminWebcam');
    const placeholder = document.getElementById('editCamPlaceholderText');
    const editCaptureBtn = document.getElementById('editCaptureSnapBtn');

    try {
        editLocalStream = await navigator.mediaDevices.getUserMedia({ 
            video: { width: { ideal: 640 }, height: { ideal: 480 } }, 
            audio: false 
        });
        video.srcObject = editLocalStream;
        await video.play();

        placeholder.style.setProperty('display', 'none', 'important');
        video.style.display = 'block';
        editCaptureBtn.disabled = false;
        
        editPhotosCount = 0;
        document.getElementById('editSnapCountLabel').innerText = "0";
        document.getElementById('editCaptureSuccessStatus').style.display = 'none';
        for(let i=1; i<=4; i++) {
            document.getElementById(`edit_box_${i}`).innerHTML = `<span class="grid-snap-label">${labels[i-1]}</span><i class="fa fa-image text-muted opacity-50"></i>`;
            document.getElementById(`edit_res_img_${i}`).value = "";
        }
    } catch(err) {
        alert("Camera stream access denied: " + err.message);
    }
}

function takeSnapshotStep(mode) {
    const video = document.getElementById(mode === 'add' ? 'adminWebcam' : 'editAdminWebcam');
    const canvas = document.getElementById('hiddenCaptureCanvas');
    const ctx = canvas.getContext('2d');
    
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const base64Data = canvas.toDataURL('image/jpeg');

    if (mode === 'add') {
        if (currentPhotosCount >= 4) return;
        currentPhotosCount++;
        
        document.getElementById(`res_img_${currentPhotosCount}`).value = base64Data;
        document.getElementById(`box_${currentPhotosCount}`).innerHTML = `<span class="grid-snap-label">${labels[currentPhotosCount-1]}</span><img src="${base64Data}">`;
        document.getElementById('snapCountLabel').innerText = currentPhotosCount;
        
        if (currentPhotosCount === 4) {
            document.getElementById('captureSnapBtn').disabled = true;
            document.getElementById('submitFormBtn').disabled = false;
            document.getElementById('captureSuccessStatus').style.display = 'block';
        }
    } else {
        if (editPhotosCount >= 4) return;
        editPhotosCount++;
        
        document.getElementById(`edit_res_img_${editPhotosCount}`).value = base64Data;
        document.getElementById(`edit_box_${editPhotosCount}`).innerHTML = `<span class="grid-snap-label">${labels[editPhotosCount-1]}</span><img src="${base64Data}">`;
        document.getElementById('editSnapCountLabel').innerText = editPhotosCount;
        
        if (editPhotosCount === 4) {
            document.getElementById('editCaptureSnapBtn').disabled = true;
            document.getElementById('editCaptureSuccessStatus').style.display = 'block';
        }
    }
}

function stopAdminCam() {
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }
    const video = document.getElementById('adminWebcam');
    const placeholder = document.getElementById('camPlaceholderText');
    
    if (video) video.style.display = 'none';
    if (placeholder) placeholder.style.removeProperty('display');
    
    document.getElementById('captureSuccessStatus').style.display = 'none';
    document.getElementById('captureSnapBtn').disabled = true;
    document.getElementById('addResidentForm').reset();
}

function stopEditAdminCam() {
    if (editLocalStream) {
        editLocalStream.getTracks().forEach(track => track.stop());
        editLocalStream = null;
    }
    const video = document.getElementById('editAdminWebcam');
    const placeholder = document.getElementById('editCamPlaceholderText');

    if (video) video.style.display = 'none';
    if (placeholder) placeholder.style.removeProperty('display');

    document.getElementById('editCaptureSuccessStatus').style.display = 'none';
}

function switchToCamRecapture() {
    document.getElementById('editFaceStaticState').style.display = 'none';
    document.getElementById('editFaceCamState').style.display = 'block';
    startEditAdminCam();
}

function cancelCamRecapture() {
    stopEditAdminCam();
    document.getElementById('editFaceCamState').style.display = 'none';
    document.getElementById('editFaceStaticState').style.display = 'block';
}
</script>
</body>
</html>