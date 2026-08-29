<?php 
session_start();

// 1. SECURITY UTILITY GATEWAY
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

// 2. SAFE DATABASE ACCESS INTEGRATION
if (file_exists('../config/database.php')) {
    require_once '../config/database.php';
    
    // Safely retrieve summary metrics with error checking
    $res_res = $conn->query("SELECT COUNT(*) as total FROM residents");
    $total_residents = ($res_res && $row = $res_res->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    $res_req = $conn->query("SELECT COUNT(*) as total FROM requests WHERE status='pending'");
    $pending_requests = ($res_req && $row = $res_req->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    $res_bill = $conn->query("SELECT COUNT(*) as total FROM billings WHERE status='unpaid'");
    $unpaid_bills = ($res_bill && $row = $res_bill->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    $res_inc = $conn->query("SELECT SUM(amount) as total FROM cashflow WHERE transaction_type='income'");
    $income_data = ($res_inc && $row = $res_inc->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    $res_exp = $conn->query("SELECT SUM(amount) as total FROM cashflow WHERE transaction_type='expense'");
    $expense_data = ($res_exp && $row = $res_exp->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    // CAPTURE OVERDUE VISITORS (TIMED IN BUT NO TIME OUT PAST EXPECTED EXIT DATE ACROSS MULTIPLE DAYS)
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

    // CAPTURE DATE FILTER SELECTION (Defaults to empty for "All Dates")
    $filter_date = isset($_GET['filter_date']) ? trim($_GET['filter_date']) : '';
    
    $logs_query = "SELECT al.timestamp, al.log_type, al.person_type,
                   COALESCE(
                       r.full_name, 
                       fp.full_name,
                       v.visitor_name, 
                       u.username,
                       'Unrecognized / Guest'
                   ) as person_name,
                   CASE 
                       WHEN r.id IS NOT NULL THEN 'Resident'
                       WHEN v.id IS NOT NULL THEN 'Visitor'
                       WHEN fp.role_type IS NOT NULL AND fp.role_type != '' THEN fp.role_type
                       WHEN u.role IS NOT NULL THEN UPPER(u.role)
                       ELSE 'Personnel'
                   END as display_group
                   FROM access_logs al
                   LEFT JOIN residents r ON (al.person_id = r.id OR al.person_id = r.user_id) 
                        AND (LOWER(al.person_type) = 'resident' OR al.person_type IS NULL OR al.person_type = '')
                   LEFT JOIN frequent_personnel fp ON al.person_id = fp.id 
                        AND (LOWER(al.person_type) NOT IN ('resident', 'visitor') OR al.person_type IS NULL OR al.person_type = '')
                   LEFT JOIN visitors v ON al.person_id = v.id 
                        AND (LOWER(al.person_type) LIKE '%visitor%' OR al.person_type IS NULL OR al.person_type = '')
                   LEFT JOIN users u ON al.person_id = u.id";
                   
    // If a specific date is selected, filter rows strictly matching that calendar day
    if (!empty($filter_date)) {
        $safe_date = $conn->real_escape_string($filter_date);
        $logs_query .= " WHERE DATE(al.timestamp) = '$safe_date'";
    }
    
    $logs_query .= " ORDER BY al.timestamp DESC LIMIT 10";
    $logs_result = $conn->query($logs_query);
} else {
    $total_residents = 0; $pending_requests = 0; $unpaid_bills = 0;
    $income_data = 0; $expense_data = 0; $logs_result = false; $overdue_result = false; $filter_date = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Admin Control Panel</title>
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
            box-sizing: border-box;
        }

        .page-wrapper {
            display: flex !important;
            flex-direction: row !important;
            min-height: 100vh;
            width: 100%;
        }

        .sidebar {
            width: 260px !important;
            min-width: 260px !important;
            background-color: #ffffff !important;
            border-right: 1px solid #e2e8f0 !important;
            padding-top: 24px;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            box-sizing: border-box !important;
        }

        .main-content {
            flex-grow: 1 !important;
            padding: 40px !important;
            box-sizing: border-box !important;
            background-color: var(--bg-light) !important;
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
            list-style: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .sidebar .nav-link {
            color: #4a5568 !important;
            font-size: 14px;
            font-weight: 500;
            padding: 12px 20px;
            margin: 4px 16px;
            border-radius: 8px;
            display: flex !important;
            align-items: center;
            text-decoration: none !important;
            transition: all 0.2s ease;
        }

        .sidebar .nav-link:not(.active):hover {
            color: var(--subdivision-orange) !important;
            background-color: rgba(230, 106, 0, 0.08) !important;
        }

        .sidebar .nav-link.active {
            color: #ffffff !important;
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%) !important;
            font-weight: 600;
        }

        .sidebar .nav-link.active:hover {
            opacity: 0.92;
            color: #ffffff !important;
        }

        .sidebar .nav-link i {
            font-size: 16px;
            width: 28px;
        }

        .logout-btn-container {
            padding-bottom: 24px;
        }

        .logout-btn {
            background-color: #fff5f5 !important;
            color: #c53030 !important;
            border: 1px solid #fed7d7 !important;
        }

        .logout-btn:hover {
            background-color: #e53e3e !important;
            color: #ffffff !important;
        }

        .dashboard-title {
            color: var(--text-dark);
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0 0 4px 0;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .card-counter {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(230, 106, 0, 0.01);
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: transform 0.2s ease;
        }

        .card-counter:hover {
            transform: translateY(-2px);
        }

        .stat-label {
            color: #718096;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.75px;
            display: block;
        }

        .stat-value {
            color: var(--text-dark);
            font-size: 28px;
            font-weight: 700;
            margin-top: 4px;
        }

        .icon-badge {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 18px;
        }

        .badge-orange { background-color: rgba(230, 106, 0, 0.08); color: var(--subdivision-orange); }
        .badge-amber { background-color: rgba(255, 170, 0, 0.1); color: #d97706; }
        .badge-danger { background-color: #fef2f2; color: #dc2626; }

        .workspace-grid {
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        .content-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            box-sizing: border-box;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .content-card-title {
            color: var(--text-dark);
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }

        .custom-log-table {
            margin-bottom: 0;
            vertical-align: middle;
        }
        
        .custom-log-table thead th {
            background-color: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 16px;
            border-bottom: 2px solid #edf2f7;
        }

        .custom-log-table tbody tr {
            transition: background-color 0.2s ease;
        }

        .custom-log-table tbody tr:hover {
            background-color: #fdfdfd;
        }

        .custom-log-table tbody td {
            padding: 16px;
            color: #334155;
            font-size: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .profile-avatar-sub {
            width: 32px;
            height: 32px;
            background-color: #f1f5f9;
            color: #475569;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
        }

        .badge-status-pill {
            padding: 6px 12px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: 0.25px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
        }

        .badge-pill-entry { background-color: #dcfce7; color: #15803d; }
        .badge-pill-exit { background-color: #fee2e2; color: #b91c1c; }
        
        .classification-tag {
            background-color: #f0fdfa;
            color: #0f766e;
            border: 1px solid #ccfbf1;
            font-size: 12px;
            padding: 3px 10px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .filter-input-date {
            font-size: 13px;
            font-weight: 500;
            color: #475569;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 12px;
            transition: all 0.2s;
        }

        .filter-input-date:focus {
            border-color: var(--subdivision-orange);
            box-shadow: 0 0 0 3px rgba(230, 106, 0, 0.15);
            outline: none;
        }
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
                <li><a href="dashboard.php" class="nav-link active"><i class="fa fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="events.php" class="nav-link"><i class="fa fa-calendar-alt"></i> Events</a></li>
                <li><a href="residents.php" class="nav-link"><i class="fa fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link"><i class="fa fa-user-shield"></i> Personnel</a></li>
                <li><a href="requests.php" class="nav-link"><i class="fa fa-file-alt"></i> Requests & Concerns</a></li>
                <li><a href="billing.php" class="nav-link"><i class="fa fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link"><i class="fa fa-money-bill-transfer"></i> Expenses</a></li>
                <li><a href="guards.php" class="nav-link"><i class="fa fa-user-lock"></i> Staff Guards</a></li>
            </ul>
        </div>

        <div class="logout-btn-container">
            <hr class="mx-3 text-muted">
            <a href="../logout.php" class="nav-link logout-btn"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main-content">
        
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom" style="width: 100%;">
            <div>
                <h1 class="h3 dashboard-title">System Overview Dashboard</h1>
                <p class="text-muted small mb-0">Operational control hub for ResiCured security infrastructure.</p>
            </div>
        </div>

        <!-- OVERDUE VISITORS ALERT BLOCK (DYNAMIC MULTI-DAY EXIT CHECK) -->
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

        <div class="metrics-grid">
            <div class="card-counter">
                <div>
                    <span class="stat-label">Total Registered Residents</span>
                    <div class="stat-value"><?php echo $total_residents; ?></div>
                </div>
                <div class="icon-badge badge-orange"><i class="fa fa-home"></i></div>
            </div>
            
            <div class="card-counter">
                <div>
                    <span class="stat-label">Pending Access Forms</span>
                    <div class="stat-value text-warning"><?php echo $pending_requests; ?></div>
                </div>
                <div class="icon-badge badge-amber"><i class="fa fa-hourglass-half"></i></div>
            </div>
            
            <div class="card-counter">
                <div>
                    <span class="stat-label">Outstanding Balances</span>
                    <div class="stat-value text-danger"><?php echo $unpaid_bills; ?></div>
                </div>
                <div class="icon-badge badge-danger"><i class="fa fa-credit-card"></i></div>
            </div>
        </div>

        <div class="workspace-grid">
            
            <div class="content-card">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                    <h5 class="content-card-title d-flex align-items-center">
                        <i class="fa fa-list-check me-2 text-primary" style="font-size: 18px;"></i> 
                        Live Gate Access Management Logs
                    </h5>
                    
                    <form method="GET" id="dateFilterForm" class="d-flex align-items-center gap-2">
                        <label for="filter_date" class="text-secondary small fw-semibold mb-0">Filter by Date:</label>
                        <input type="date" 
                               id="filter_date" 
                               name="filter_date" 
                               class="filter-input-date" 
                               value="<?php echo htmlspecialchars($filter_date); ?>" 
                               onchange="document.getElementById('dateFilterForm').submit();">
                        
                        <?php if(!empty($filter_date)): ?>
                            <a href="dashboard.php" class="btn btn-sm btn-light border" title="Clear Date Filter">
                                <i class="fa fa-times text-muted"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-responsive" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
                    <table class="table custom-log-table">
                        <thead>
                            <tr>
                                <th>Individual Profile</th>
                                <th>Classification Group</th>
                                <th>Gate Action Status</th>
                                <th>Verification Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($logs_result && $logs_result->num_rows > 0): ?>
                                <?php while($log = $logs_result->fetch_assoc()): ?>
                                    <?php 
                                        $display_name = !empty($log['person_name']) ? htmlspecialchars($log['person_name']) : "Unrecognized / Guest";
                                        $classification = !empty($log['display_group']) ? htmlspecialchars($log['display_group']) : "Visitor";
                                        $action = strtolower($log['log_type'] ?? 'entry');
                                        $initials = strtoupper(substr($display_name, 0, 2));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="profile-avatar-sub"><?php echo $initials; ?></div>
                                                <span class="fw-bold text-dark"><?php echo $display_name; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="classification-tag">
                                                <i class="fa-regular fa-id-badge me-1"></i><?php echo $classification; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($action == 'entry' || $action == 'time in' || $action == 'in'): ?>
                                                <span class="badge-status-pill badge-pill-entry">
                                                    <i class="fa-solid fa-right-to-bracket"></i> Entry
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-status-pill badge-pill-exit">
                                                    <i class="fa-solid fa-right-from-bracket"></i> Exit
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-secondary font-monospace" style="font-size: 13px;">
                                            <i class="fa-regular fa-calendar-days me-1 text-muted"></i> 
                                            <?php echo date('M d, Y — g:i A', strtotime($log['timestamp'])); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-5 text-muted small">
                                        <i class="fa fa-folder-open d-block mb-2 text-secondary" style="font-size: 28px;"></i>
                                        No checkpoint log records map cleanly within your selected timeline configuration.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="content-card">
                <h5 class="content-card-title mb-4">
                    <i class="fa fa-chart-line me-2" style="color: var(--subdivision-orange);"></i> 
                    Subdivision Cashflow Analytics
                </h5>
                <div style="position: relative; height:280px; width:100%">
                    <canvas id="cashflowChart"></canvas>
                </div>
            </div>
        </div> 
    </div> 
</div> 

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const canvasElement = document.getElementById('cashflowChart');
    if (!canvasElement) return;
    
    const ctx = canvasElement.getContext('2d');
    
    const grossIncome = <?php echo !empty($income_data) ? (float)$income_data : 42000; ?>;
    const grossExpenses = <?php echo !empty($expense_data) ? (float)$expense_data : 16000; ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Collected Income', 'Operational Expenses'],
            datasets: [{
                data: [grossIncome, grossExpenses],
                backgroundColor: [
                    'rgba(230, 106, 0, 0.85)',
                    'rgba(255, 170, 0, 0.85)'
                ],
                borderWidth: 0,
                borderRadius: 6,
                barThickness: 45
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9' },
                    ticks: { color: '#94a3b8', font: { size: 11 } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#4a5568', font: { size: 12, weight: '500' } }
                }
            }
        }
    });
});
</script>
</body>
</html>