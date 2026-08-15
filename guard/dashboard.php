<?php 
session_start();

// Security Gateway: Guard role verification
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

// SQL Query: Joins access_logs -> visitors -> residents (host) & direct residents
$logs_query = "
    SELECT 
        l.id,
        l.person_type,
        l.person_id,
        l.log_type,
        l.timestamp,
        -- Direct resident info (when resident enters)
        r_direct.full_name AS resident_name,
        r_direct.house_number AS resident_house,
        -- Visitor info
        v.visitor_name AS visitor_name,
        v.resident_id AS visitor_resident_id,
        -- Visited Host info (via visitors.resident_id)
        r_host.full_name AS host_resident_name,
        r_host.house_number AS host_house_number,
        -- Frequent personnel info
        fp.full_name AS fp_name,
        fp.role_type AS fp_category
    FROM access_logs l
    LEFT JOIN residents r_direct 
        ON l.person_id = r_direct.id AND (l.person_type IS NULL OR l.person_type = '' OR l.person_type = 'resident')
    LEFT JOIN visitors v 
        ON l.person_id = v.id AND l.person_type = 'visitor'
    LEFT JOIN residents r_host 
        ON v.resident_id = r_host.id
    LEFT JOIN frequent_personnel fp 
        ON l.person_id = fp.id AND l.person_type = 'frequent_personnel'
    ORDER BY l.id DESC 
    LIMIT 50
";

$result = $conn->query($logs_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Operational Logs</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { 
            --subdivision-orange: #ea580c; 
            --bg-light: #f8fafc; 
            --border-color: #e2e8f0; 
        }
        body { 
            background-color: var(--bg-light); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: #334155; 
        }
        .page-wrapper { display: flex; min-height: 100vh; }
        .sidebar { 
            width: 260px; 
            min-width: 260px; 
            background: #ffffff; 
            border-right: 1px solid var(--border-color); 
            padding: 24px 16px; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
        }
        .brand-logo-area { 
            padding: 0 12px 20px 12px; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            border-bottom: 1px solid #f1f5f9; 
        }
        .sidebar .nav-link { 
            color: #475569; 
            font-size: 14px; 
            font-weight: 600; 
            padding: 11px 16px; 
            margin: 4px 0; 
            border-radius: 10px; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            text-decoration: none; 
        }
        .sidebar .nav-link.active { 
            color: #ffffff; 
            background: linear-gradient(135deg, #ea580c 0%, #f97316 100%); 
        }
        .main-content { flex-grow: 1; padding: 32px 40px; }
        .dashboard-card { 
            background: #ffffff; 
            border: 1px solid var(--border-color); 
            border-radius: 14px; 
            overflow: hidden; 
        }
        .modern-table thead th { 
            background-color: #f8fafc; 
            color: #64748b; 
            font-size: 11px; 
            font-weight: 700; 
            text-transform: uppercase; 
            padding: 14px 18px; 
            border-bottom: 1px solid var(--border-color); 
        }
        .modern-table tbody td { 
            padding: 14px 18px; 
            vertical-align: middle; 
            border-bottom: 1px solid #f1f5f9; 
            font-size: 13.5px; 
        }
        .badge-time-in { 
            background-color: #dcfce7; 
            color: #15803d; 
            border: 1px solid #bbf7d0; 
            font-weight: 700; 
            font-size: 11px; 
            padding: 5px 10px; 
            border-radius: 6px; 
        }
        .badge-time-out { 
            background-color: #fee2e2; 
            color: #b91c1c; 
            border: 1px solid #fecaca; 
            font-weight: 700; 
            font-size: 11px; 
            padding: 5px 10px; 
            border-radius: 6px; 
        }
        .visited-badge { 
            background-color: #eff6ff; 
            color: #1d4ed8; 
            border: 1px solid #bfdbfe; 
            font-weight: 600; 
            font-size: 12px; 
            padding: 4px 10px; 
            border-radius: 6px; 
        }
    </style>
</head>
<body>

<div class="page-wrapper">
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div>
            <div class="brand-logo-area">
                <i class="fa fa-shield-halved text-warning fs-4"></i>
                <h4 class="fw-bold m-0 text-dark" style="font-size:20px;">ResiCured</h4>
            </div>
            <ul class="nav flex-column mt-3">
                <li class="nav-item"><a href="dashboard.php" class="nav-link active"><i class="fa fa-chart-line"></i> Operational Logs</a></li>
                <li class="nav-item"><a href="face_scanner.php" class="nav-link"><i class="fa fa-user-shield"></i> Face Scanner</a></li>
                <li class="nav-item"><a href="qr_scanner.php" class="nav-link"><i class="fa fa-qrcode"></i> QR Pass Scanner</a></li>
            </ul>
        </div>
        <div>
            <a href="../logout.php" class="nav-link text-danger fw-bold"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 fw-bold text-dark m-0">Subdivision Access Tracking Center</h1>
                <p class="text-muted small mb-0 mt-1">Monitor live perimeter entry, visitor movements, and destination logs.</p>
            </div>
            <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary fw-semibold px-3 py-2">
                <i class="fa-solid fa-rotate me-1"></i> Refresh Stream Feed
            </button>
        </div>

        <!-- TABLE CARD -->
        <div class="dashboard-card">
            <div class="p-3 border-bottom bg-white d-flex align-items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-warning fs-5"></i>
                <h6 class="fw-bold m-0 text-dark">Real-Time Activity Logs Stream</h6>
            </div>
            <div class="table-responsive">
                <table class="table modern-table align-middle">
                    <thead>
                        <tr>
                            <th>Transaction Timestamp</th>
                            <th>Individual Name</th>
                            <th>Classification Group</th>
                            <th>Visited Resident / Host</th>
                            <th>Gate Activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): 
                                $personType = strtolower($row['person_type'] ?? 'resident');
                                
                                $displayName = "Unknown Profile (" . $row['person_id'] . ")";
                                $classification = "Resident";
                                $visitedHost = "Home Base";

                                if ($personType === 'visitor') {
                                    $classification = "Visitor";
                                    // Set Visitor Name
                                    $displayName = !empty($row['visitor_name']) ? $row['visitor_name'] : "Visitor #" . $row['person_id'];
                                    
                                    // Set Host Resident Details
                                    if (!empty($row['host_house_number']) || !empty($row['host_resident_name'])) {
                                        $visitedHost = "House No. " . ($row['host_house_number'] ?? 'N/A');
                                        if (!empty($row['host_resident_name'])) {
                                            $visitedHost .= " (" . $row['host_resident_name'] . ")";
                                        }
                                    } elseif (!empty($row['visitor_resident_id'])) {
                                        $visitedHost = "Resident Host ID #" . $row['visitor_resident_id'];
                                    } else {
                                        $visitedHost = "Subdivision Guest";
                                    }

                                } elseif ($personType === 'frequent_personnel') {
                                    $classification = !empty($row['fp_category']) ? $row['fp_category'] : "Delivery Courier / Rider";
                                    $displayName = !empty($row['fp_name']) ? $row['fp_name'] : "Personnel #" . $row['person_id'];
                                    $visitedHost = "Subdivision Access";

                                } else { // Resident
                                    if (!empty($row['resident_name'])) {
                                        $displayName = $row['resident_name'];
                                    }
                                    if (!empty($row['resident_house'])) {
                                        $visitedHost = "House No. " . $row['resident_house'];
                                    }
                                }

                                // Gate Activity (entry -> TIME IN, exit -> TIME OUT)
                                $logType = strtolower($row['log_type'] ?? 'entry');
                                $isTimeIn = ($logType === 'entry' || $logType === 'time in');
                                
                                // Format Timestamp
                                $timestamp = date("M d, Y - h:i A", strtotime($row['timestamp']));
                            ?>
                                <tr>
                                    <td class="text-secondary font-monospace small">
                                        <i class="fa-regular fa-calendar-check me-1 opacity-75"></i><?php echo $timestamp; ?>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($displayName); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($classification); ?></span>
                                    </td>
                                    <td>
                                        <span class="visited-badge">
                                            <i class="fa-solid fa-house-user opacity-75 me-1"></i>
                                            <?php echo htmlspecialchars($visitedHost); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isTimeIn): ?>
                                            <span class="badge-time-in"><i class="fa-solid fa-right-to-bracket me-1"></i>TIME IN</span>
                                        <?php else: ?>
                                            <span class="badge-time-out"><i class="fa-solid fa-right-from-bracket me-1"></i>TIME OUT</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-list-check fs-3 d-block mb-2 opacity-50"></i>
                                    No entry/exit logs found.
                                </td>
                            </tr>
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