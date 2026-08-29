<?php 
session_start();

// Security Gateway: Guard role verification
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

// Pagination Parameters
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) { $page = 1; }
$offset = ($page - 1) * $limit;

// Filter Parameters
$filter_date = isset($_GET['filter_date']) ? trim($_GET['filter_date']) : '';

// Dynamic Where Clause
$where_sql = "";
if (!empty($filter_date)) {
    $where_sql = " WHERE DATE(l.timestamp) = '" . $conn->real_escape_string($filter_date) . "' ";
}

// 1. Get total record count for pagination
$count_query = "SELECT COUNT(*) AS total FROM access_logs l " . $where_sql;
$count_result = $conn->query($count_query);
$total_rows = ($count_result && $count_result->num_rows > 0) ? $count_result->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_rows / $limit);

// Re-check page bound after knowing total_pages
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// 2. Fetch Paginated & Filtered Logs (Query updated to match visitors table schema)
$logs_query = "
    SELECT 
        l.id,
        l.person_type,
        l.person_id,
        l.log_type,
        l.timestamp,
        -- Direct resident info
        r_direct.full_name AS resident_name,
        r_direct.house_number AS resident_house,
        -- Visitor info
        v.visitor_name AS visitor_name,
        -- Visited Host info
        r_host.full_name AS host_resident_name,
        r_host.house_number AS host_house_number,
        -- Frequent personnel info
        fp.full_name AS fp_name,
        fp.role_type AS fp_category
    FROM access_logs l
    LEFT JOIN residents r_direct 
        ON (l.person_id = r_direct.id OR l.person_id = r_direct.user_id) 
        AND (l.person_type IS NULL OR l.person_type = '' OR LOWER(l.person_type) = 'resident')
    LEFT JOIN visitors v 
        ON (l.person_id = v.id) 
        AND LOWER(l.person_type) LIKE '%visitor%'
    LEFT JOIN residents r_host 
        ON (v.resident_id = r_host.user_id OR v.resident_id = r_host.id)
    LEFT JOIN frequent_personnel fp 
        ON l.person_id = fp.id 
        AND LOWER(l.person_type) = 'frequent_personnel'
    {$where_sql}
    ORDER BY l.id DESC 
    LIMIT {$limit} OFFSET {$offset}
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
        .pagination .page-item.active .page-link {
            background-color: var(--subdivision-orange);
            border-color: var(--subdivision-orange);
            color: #fff;
        }
        .pagination .page-link {
            color: #475569;
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
            <button onclick="location.href='dashboard.php'" class="btn btn-sm btn-outline-secondary fw-semibold px-3 py-2">
                <i class="fa-solid fa-rotate me-1"></i> Refresh Stream Feed
            </button>
        </div>

        <!-- OVERDUE VISITORS ALERT BLOCK (DYNAMIC MULTI-DAY EXIT CHECK) -->
        <?php
        $overdue_query = "
            SELECT 
                v.visitor_name, 
                v.time_in, 
                COALESCE(v.exit_date, v.visit_date) AS expected_exit,
                r.house_number, 
                r.full_name AS host_name
            FROM visitors v
            LEFT JOIN residents r ON (v.resident_id = r.id OR v.resident_id = r.user_id)
            WHERE v.time_in IS NOT NULL 
              AND v.time_out IS NULL 
              AND COALESCE(v.exit_date, v.visit_date) < CURRENT_DATE()
        ";
        $overdue_result = $conn->query($overdue_query);
        ?>

        <?php if ($overdue_result && $overdue_result->num_rows > 0): ?>
            <div class="alert alert-danger border-0 shadow-sm d-flex align-items-start mb-4 p-3" role="alert" style="border-radius: 12px; background-color: #fef2f2; border-left: 5px solid #ef4444 !important;">
                <i class="fa-solid fa-triangle-exclamation text-danger fs-4 me-3 mt-1"></i>
                <div class="w-100">
                    <strong class="text-danger d-block mb-1 fs-6">
                        <i class="fa-solid fa-clock me-1"></i> Security Alert: Overdue Visitors (Expected Exit Date Exceeded)
                    </strong>
                    <ul class="mb-0 ps-3 small text-dark">
                        <?php while ($overdue = $overdue_result->fetch_assoc()): ?>
                            <li class="mb-1">
                                <strong><?php echo htmlspecialchars($overdue['visitor_name']); ?></strong> 
                                (Visiting: <?php echo !empty($overdue['house_number']) ? 'House ' . htmlspecialchars($overdue['house_number']) : 'Host Base'; ?> 
                                <?php echo !empty($overdue['host_name']) ? ' - ' . htmlspecialchars($overdue['host_name']) : ''; ?>) 
                                — Entry: <span class="badge bg-secondary"><?php echo date('M d, Y - h:i A', strtotime($overdue['time_in'])); ?></span>
                                — Expected Exit: <span class="badge bg-danger"><?php echo date('M d, Y', strtotime($overdue['expected_exit'])); ?></span>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- TABLE CARD -->
        <div class="dashboard-card">
            <!-- HEADER WITH DATE FILTER -->
            <div class="p-3 border-bottom bg-white d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-warning fs-5"></i>
                    <h6 class="fw-bold m-0 text-dark">Real-Time Activity Logs Stream</h6>
                </div>
                
                <!-- DATE FILTER FORM -->
                <form method="GET" action="" class="d-flex align-items-center gap-2 m-0">
                    <input type="date" name="filter_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_date); ?>">
                    <button type="submit" class="btn btn-sm btn-warning text-white fw-bold px-3">
                        <i class="fa-solid fa-filter me-1"></i> Filter
                    </button>
                    <?php if (!empty($filter_date)): ?>
                        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-xmark"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table modern-table align-middle m-0">
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

                                if (strpos($personType, 'visitor') !== false) {
                                    $classification = "Visitor";
                                    $displayName = !empty($row['visitor_name']) ? $row['visitor_name'] : "Visitor #" . $row['person_id'];
                                    
                                    if (!empty($row['host_house_number']) || !empty($row['host_resident_name'])) {
                                        $visitedHost = "";
                                        if (!empty($row['host_house_number'])) {
                                            $visitedHost = preg_match('/blk|house|lot/i', $row['host_house_number']) 
                                                ? $row['host_house_number'] 
                                                : "House No. " . $row['host_house_number'];
                                        }
                                        if (!empty($row['host_resident_name'])) {
                                            $visitedHost .= (!empty($visitedHost) ? " (" . $row['host_resident_name'] . ")" : $row['host_resident_name']);
                                        }
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
                                        $visitedHost = preg_match('/blk|house|lot/i', $row['resident_house']) 
                                            ? $row['resident_house'] 
                                            : "House No. " . $row['resident_house'];
                                    }
                                }

                                $logType = strtolower($row['log_type'] ?? 'entry');
                                $isTimeIn = ($logType === 'entry' || $logType === 'time in');
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
                                    No entry/exit logs found <?php echo !empty($filter_date) ? "for selected date" : ""; ?>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION FOOTER -->
            <?php if ($total_pages > 0): ?>
            <div class="p-3 border-top bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="small text-muted">
                    Showing <strong><?php echo min($offset + 1, $total_rows); ?></strong> to <strong><?php echo min($offset + $limit, $total_rows); ?></strong> of <strong><?php echo $total_rows; ?></strong> entries
                </div>
                
                <?php if ($total_pages > 1): ?>
                <?php 
                    // Calculate dynamic sliding page window (Maximum of 5 numbered buttons)
                    $max_visible = 5;
                    $start_page = max(1, $page - floor($max_visible / 2));
                    $end_page = min($total_pages, $start_page + $max_visible - 1);

                    // Re-adjust start page if we are near the end
                    if ($end_page - $start_page + 1 < $max_visible) {
                        $start_page = max(1, $end_page - $max_visible + 1);
                    }
                    
                    $filter_param = !empty($filter_date) ? '&filter_date=' . urlencode($filter_date) : '';
                ?>
                <nav>
                    <ul class="pagination pagination-sm m-0">
                        <!-- FIRST BUTTON -->
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=1<?php echo $filter_param; ?>"><i class="fa-solid fa-angles-left me-1"></i>First</a>
                        </li>

                        <!-- PREVIOUS BUTTON -->
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?><?php echo $filter_param; ?>">Previous</a>
                        </li>

                        <!-- DYNAMIC WINDOW NUMBERS (MAX 5) -->
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $filter_param; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <!-- NEXT BUTTON -->
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo min($total_pages, $page + 1); ?><?php echo $filter_param; ?>">Next</a>
                        </li>

                        <!-- LAST BUTTON -->
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $filter_param; ?>">Last <i class="fa-solid fa-angles-right ms-1"></i></a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>