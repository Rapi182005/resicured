<?php 
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

// ================= DATABASE CONNECTION =================
$host = "localhost";
$user = "root";
$pass = "";
$db_name = "resicured_db";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // FAIL-SAFE JOIN: Resolves profiles correctly even if person_type is blank/empty in your database
    $query = "SELECT 
                al.id,
                al.person_id,
                al.person_type,
                al.log_type,
                al.timestamp,
                CASE 
                    WHEN LOWER(TRIM(al.person_type)) = 'resident' THEN r.full_name
                    WHEN LOWER(TRIM(al.person_type)) = 'frequent_personnel' THEN fp.full_name
                    ELSE COALESCE(r.full_name, fp.full_name)
                END AS full_name,
                CASE 
                    WHEN LOWER(TRIM(al.person_type)) = 'resident' THEN 'Resident'
                    WHEN LOWER(TRIM(al.person_type)) = 'frequent_personnel' THEN fp.role_type
                    WHEN r.id IS NOT NULL THEN 'Resident'
                    WHEN fp.id IS NOT NULL THEN fp.role_type
                    ELSE 'Unknown'
                END AS display_role,
                CASE 
                    WHEN LOWER(TRIM(al.person_type)) = 'resident' THEN r.registered_vehicle_plate
                    WHEN LOWER(TRIM(al.person_type)) = 'frequent_personnel' THEN fp.vehicle_plate
                    ELSE COALESCE(r.registered_vehicle_plate, fp.vehicle_plate)
                END AS vehicle_plate
              FROM access_logs al
              LEFT JOIN residents r ON al.person_id = r.id
              LEFT JOIN frequent_personnel fp ON al.person_id = fp.id
              ORDER BY al.timestamp DESC";
              
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = [];
    $error_msg = "Database Log Stream Offline: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Operational Control Room</title>
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
        }
        .page-wrapper { display: flex; min-height: 100vh; }
        .sidebar { 
            width: 260px; 
            min-width: 260px; 
            background: #fff; 
            border-right: 1px solid #e2e8f0; 
            padding-top: 24px; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
        }
        .brand-logo-area { padding: 0 24px 20px 24px; display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
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
        }
        .sidebar .nav-link.active { 
            color: #fff; 
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); 
            font-weight: 600; 
        }
        .sidebar .nav-link i { font-size: 16px; width: 28px; }
        .main-content { flex-grow: 1; padding: 40px; }
        .content-card { 
            background: #fff; 
            border: 1px solid #e2e8f0; 
            border-radius: 12px; 
            padding: 24px; 
        }
        .table-container {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        .modern-table thead th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 18px;
            border-bottom: 1px solid #e2e8f0;
        }
        .modern-table tbody td {
            padding: 14px 18px;
            font-size: 14px;
            color: var(--text-dark);
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        .modern-table tbody tr:last-child td { border-bottom: none; }
        .badge-resident { background-color: #dcfce7; color: #15803d; }
        .badge-personnel { background-color: #e0f2fe; color: #0369a1; }
        .badge-timein { background-color: #dbeafe; color: #1d4ed8; font-weight: bold; }
        .badge-timeout { background-color: #fee2e2; color: #b91c1c; font-weight: bold; }
    </style>
</head>
<body>
<div class="page-wrapper">
    <div class="sidebar">
        <div>
            <div class="brand-logo-area border-bottom">
                <i class="fa fa-shield-halved text-warning fs-4"></i>
                <h4 class="fw-bold m-0 text-dark" style="font-size:20px;">ResiCured</h4>
            </div>
            <ul class="sidebar-menu mt-3">
                <li><a href="dashboard.php" class="nav-link active"><i class="fa fa-chart-line"></i> Operational Logs</a></li>
                <li><a href="face_scanner.php" class="nav-link"><i class="fa fa-user-shield"></i> Face Scanner</a></li>
                <li><a href="qr_scanner.php" class="nav-link"><i class="fa fa-qrcode"></i> QR Pass Scanner</a></li>
            </ul>
        </div>
        <div class="mb-4"><a href="../logout.php" class="nav-link text-danger"><i class="fa fa-sign-out-alt"></i> Logout</a></div>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 fw-bold text-dark m-0">Subdivision Access Tracking Center</h1>
                <p class="text-muted small mb-0">Monitor live perimeter entry and transit activities captured across system verification checkpoints.</p>
            </div>
            <button onclick="window.location.reload();" class="btn btn-sm btn-light border fw-semibold text-secondary">
                <i class="fa-solid fa-rotate me-1"></i> Refresh Stream Feed
            </button>
        </div>

        <?php if (isset($error_msg)): ?>
            <div class="alert alert-danger small p-3 mb-4"><i class="fa-solid fa-circle-exclamation me-2"></i> <?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <div class="content-card">
            <h5 class="fw-bold mb-3 text-dark" style="font-size: 16px;">
                <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Real-Time Activity Logs Stream
            </h5>
            <hr class="mt-0 mb-4">

            <div class="table-container">
                <table class="table modern-table table-hover m-0">
                    <thead>
                        <tr>
                            <th>Transaction Timestamp</th>
                            <th>Individual Name</th>
                            <th>Classification Group</th>
                            <th>Gate Activity</th>
                            <th>Vehicle Plate Profile</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="text-muted fw-medium" style="font-size: 13px;">
                                        <i class="fa-regular fa-calendar me-1"></i> 
                                        <?= date("M d, Y - h:i A", strtotime($log['timestamp'])) ?>
                                    </td>
                                    <td>
                                        <strong>
                                            <?= !empty($log['full_name']) ? htmlspecialchars($log['full_name']) : '<span class="text-muted fw-normal">Unknown Profile (' . htmlspecialchars($log['person_id']) . ')</span>' ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php 
                                        $displayRole = !empty($log['display_role']) ? trim($log['display_role']) : 'Personnel';
                                        if (strtolower($displayRole) === 'resident'): ?>
                                            <span class="badge badge-resident px-2.5 py-1.5 rounded"><i class="fa-solid fa-house-user me-1"></i> Resident</span>
                                        <?php else: ?>
                                            <span class="badge badge-personnel px-2.5 py-1.5 rounded"><i class="fa-solid fa-id-badge me-1"></i> <?= htmlspecialchars($displayRole) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $l_type = strtolower(trim($log['log_type']));
                                        if ($l_type === 'entry' || $l_type === 'timein' || $l_type === 'time in') {
                                            echo '<span class="badge badge-timein px-3 py-1.5 text-uppercase"><i class="fa-solid fa-right-to-bracket me-1"></i> Time In</span>';
                                        } else {
                                            echo '<span class="badge badge-timeout px-3 py-1.5 text-uppercase"><i class="fa-solid fa-right-from-bracket me-1"></i> Time Out</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['vehicle_plate'])): ?>
                                            <span class="font-monospace bg-light border px-2 py-1 rounded text-dark small fw-bold"><i class="fa-solid fa-car me-1 text-muted"></i> <?= htmlspecialchars($log['vehicle_plate']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small italic">No Vehicle Profile</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-folder-open d-block fs-2 mb-2 text-light"></i>
                                    No entry activity traces found within log frames.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>