<?php 
session_start();

// 1. SECURITY GATEWAY
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

// Generate CSRF Token for Form Security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success_msg = "";
$error_msg = "";

// Detect current page filename dynamically for sidebar state
$current_page = basename($_SERVER['PHP_SELF']);

// ================= ACTION 1: MASS-GENERATE BILLS FOR MULTIPLE SELECTIONS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bill_btn'])) {
    // CSRF Protection Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_msg = "Security validation failed. Invalid CSRF token.";
    } elseif (!empty($_POST['resident_ids']) && is_array($_POST['resident_ids'])) {
        $resident_ids = $_POST['resident_ids'];
        $amount = floatval($_POST['amount']);
        $billing_month = trim($_POST['billing_month']);
        $due_date = trim($_POST['due_date']);

        if ($amount <= 0 || empty($billing_month) || empty($due_date)) {
            $error_msg = "Invalid input: Please ensure amount and billing details are completed.";
        } else {
            // Require Gmail sending script from project root
            if (file_exists('../guard/send_gmail.php')) {
                require_once '../guard/send_gmail.php';
            }

            $conn->begin_transaction();
            try {
                // Prepared statement reuse for efficient batch execution
                $stmt = $conn->prepare("INSERT INTO billings (resident_id, amount, billing_month, due_date, status) VALUES (?, ?, ?, ?, 'unpaid')");
                
                // Query to get resident email from users table
                $res_stmt = $conn->prepare("SELECT r.full_name, u.email FROM residents r JOIN users u ON r.user_id = u.id WHERE r.id = ?");

                foreach ($resident_ids as $res_id) {
                    $res_id_int = intval($res_id);
                    $stmt->bind_param("idss", $res_id_int, $amount, $billing_month, $due_date);
                    $stmt->execute();

                    // Fetch email & name to send billing notification
                    if ($res_stmt) {
                        $res_stmt->bind_param("i", $res_id_int);
                        $res_stmt->execute();
                        $res_result = $res_stmt->get_result();
                        
                        if ($res_row = $res_result->fetch_assoc()) {
                            if (!empty($res_row['email']) && function_exists('sendBillingNotification')) {
                                try {
                                    sendBillingNotification($res_row['email'], $res_row['full_name'], $billing_month, $amount, $due_date);
                                } catch (\Throwable $e) {
                                    error_log("Billing Email Alert Failed: " . $e->getMessage());
                                }
                            }
                        }
                    }
                }
                
                if ($res_stmt) {
                    $res_stmt->close();
                }
                $stmt->close();

                $conn->commit();
                $success_msg = "Successfully generated and issued " . count($resident_ids) . " statements of account with email notifications.";
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Failed to batch deploy invoice statements: " . $e->getMessage();
            }
        }
    } else {
        $error_msg = "Invalid Action: Please select at least one resident before issuing.";
    }
}

// ================= ACTION 2: PROCESS COLLECTION TOGGLE (MARK AS PAID) =================
if (isset($_GET['collect_bill_id'])) {
    $bill_id = intval($_GET['collect_bill_id']);
    
    $bill_stmt = $conn->prepare("SELECT resident_id, amount, billing_month FROM billings WHERE id = ?");
    $bill_stmt->bind_param("i", $bill_id);
    $bill_stmt->execute();
    $bill_result = $bill_stmt->get_result();
    
    if ($bill_result && $bill_result->num_rows > 0) {
        $bill_data = $bill_result->fetch_assoc();
        $amount = floatval($bill_data['amount']);
        $details = "HOA Dues - " . $bill_data['billing_month'];

        $update_stmt = $conn->prepare("UPDATE billings SET status = 'paid' WHERE id = ?");
        $update_stmt->bind_param("i", $bill_id);
        
        if ($update_stmt->execute()) {
            $table_check = $conn->query("SHOW TABLES LIKE 'cashflow'");
            if ($table_check && $table_check->num_rows > 0) {
                $cashflow_stmt = $conn->prepare("INSERT INTO cashflow (transaction_type, amount, details) VALUES ('income', ?, ?)");
                $cashflow_stmt->bind_param("ds", $amount, $details);
                $cashflow_stmt->execute();
                $cashflow_stmt->close();
            }
            
            $update_stmt->close();
            $bill_stmt->close();
            header("Location: billing.php?success=" . urlencode("Payment collected successfully! Ledger updated."));
            exit();
        } else {
            $error_msg = "Failed to execute update statement: " . $conn->error;
        }
        $update_stmt->close();
    } else {
        $error_msg = "Error: Invoice record matching ID (" . $bill_id . ") could not be found.";
    }
    $bill_stmt->close();
}

if (isset($_GET['success'])) { 
    $success_msg = htmlspecialchars($_GET['success']); 
}

// ================= FILTER & SEARCH PREPARATION =================
$filter_resident = isset($_GET['resident_id']) ? intval($_GET['resident_id']) : 0;
$filter_status   = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_year     = isset($_GET['year']) ? trim($_GET['year']) : '';
$search_query    = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_clauses = [];
$params = [];
$types = "";

if ($filter_resident > 0) {
    $where_clauses[] = "b.resident_id = ?";
    $params[] = $filter_resident;
    $types .= "i";
}
if (!empty($filter_status) && in_array($filter_status, ['paid', 'unpaid'])) {
    $where_clauses[] = "b.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}
if (!empty($filter_year) && is_numeric($filter_year)) {
    $where_clauses[] = "YEAR(b.due_date) = ?";
    $params[] = $filter_year;
    $types .= "s";
}
if (!empty($search_query)) {
    $where_clauses[] = "(r.full_name LIKE ? OR r.house_number LIKE ? OR b.billing_month LIKE ?)";
    $search_param = "%" . $search_query . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// 3. FETCH METRICS AGGREGATIONS
$collected_total = $conn->query("SELECT SUM(amount) as total FROM billings WHERE status='paid'")->fetch_assoc()['total'] ?? 0;
$receivables_total = $conn->query("SELECT SUM(amount) as total FROM billings WHERE status='unpaid'")->fetch_assoc()['total'] ?? 0;
$total_invoices_count = $conn->query("SELECT COUNT(id) as total FROM billings")->fetch_assoc()['total'] ?? 0;

// Distinct years for filter dropdown
$years_pool = $conn->query("SELECT DISTINCT YEAR(due_date) as billing_year FROM billings ORDER BY billing_year DESC");

// Fetch active residents for filters and modal
$residents_pool = $conn->query("SELECT id, full_name, house_number FROM residents ORDER BY house_number ASC");
$residents_filter_pool = $conn->query("SELECT id, full_name, house_number FROM residents ORDER BY full_name ASC");

// 4. FETCH INVOICES WITH RESIDENT PROFILES & FILTERS
$ledger_query = "SELECT b.id as bill_id, b.amount, b.billing_month, b.due_date, b.status, r.id as resident_id, r.full_name, r.house_number 
                 FROM billings b 
                 JOIN residents r ON b.resident_id = r.id 
                 $where_sql
                 ORDER BY b.id DESC";

if (!empty($params)) {
    $ledger_stmt = $conn->prepare($ledger_query);
    $ledger_stmt->bind_param($types, ...$params);
    $ledger_stmt->execute();
    $ledger_result = $ledger_stmt->get_result();
} else {
    $ledger_result = $conn->query($ledger_query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Account Billing & Ledger</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root {
            --brand-orange: #f97316;
            --brand-orange-hover: #ea580c;
            --brand-dark: #0f172a;
            --bg-light: #f8fafc;
            --border-color: #e2e8f0;
            --text-muted: #64748b;
        }

        body { 
            background-color: var(--bg-light); 
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; 
            margin: 0; 
            padding: 0; 
            color: #1e293b;
        }

        .page-wrapper { display: flex; min-height: 100vh; width: 100%; }

        /* SIDEBAR STYLING (MATCHED TO HOUSEHOLD & RESIDENTS) */
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
            z-index: 1000;
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

        /* Main Workspace */
        .main-content { flex-grow: 1; padding: 36px 40px; background-color: var(--bg-light); box-sizing: border-box; max-width: calc(100vw - 250px); }
        .page-title { color: var(--brand-dark); font-weight: 800; letter-spacing: -0.5px; margin: 0; }
        
        .btn-brand { 
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); 
            border: none; color: white; font-weight: 700; font-size: 14px; padding: 11px 22px; 
            border-radius: 10px; text-decoration: none; display: inline-flex; align-items: center; 
            cursor: pointer; box-shadow: 0 4px 12px rgba(234, 88, 12, 0.25); transition: all 0.2s ease;
        }
        .btn-brand:hover { opacity: 0.95; color: white; transform: translateY(-1px); }

        /* Analytics Metric Row */
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 28px; }
        .mini-stat-card { background: #ffffff; border: 1px solid var(--border-color); border-radius: 14px; padding: 20px 24px; display: flex; align-items: center; gap: 18px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .stat-icon-wrapper { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .icon-success { background-color: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .icon-warn { background-color: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; }
        .icon-info { background-color: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; }

        /* Filter Toolbar Card */
        .filter-card { background: #ffffff; border: 1px solid var(--border-color); border-radius: 14px; padding: 18px 20px; margin-bottom: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .form-select-custom, .form-control-custom { font-size: 13px; font-weight: 600; border-radius: 8px; border: 1px solid #cbd5e1; padding: 9px 12px; color: #334155; }
        .form-select-custom:focus, .form-control-custom:focus { border-color: var(--brand-orange); box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15); outline: none; }

        /* Table Card Layout */
        .table-card { background: #ffffff; border: 1px solid var(--border-color); border-radius: 14px; padding: 0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03); overflow: hidden; }
        .custom-table { width: 100%; margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
        .custom-table th { background-color: #f8fafc; color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.6px; border-bottom: 1px solid var(--border-color); padding: 14px 20px; }
        .custom-table td { color: var(--brand-dark); font-size: 14px; padding: 16px 20px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
        .custom-table tr:last-child td { border-bottom: none; }
        
        .action-link { padding: 7px 14px; border-radius: 8px; font-size: 12px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-collect { background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .btn-collect:hover { background-color: #16a34a; color: #ffffff; }

        .resident-avatar-badge { width: 36px; height: 36px; border-radius: 50%; background-color: #ffedd5; color: var(--brand-orange); font-weight: 800; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; margin-right: 12px; }

        /* PURE CSS MODAL BACKDROP */
        .custom-modal-backdrop { 
            position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; 
            background-color: rgba(15, 23, 42, 0.6) !important; backdrop-filter: blur(4px); z-index: 99999 !important; 
            display: none; align-items: center; justify-content: center; padding: 20px; 
        }
        .custom-modal-backdrop:target { display: flex !important; }
        
        .custom-popup-window { 
            background-color: #ffffff !important; width: 100% !important; max-width: 560px !important; 
            border-radius: 18px !important; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25) !important; 
            overflow: hidden !important; animation: popIn 0.2s ease-out; 
        }
        
        @keyframes popIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .popup-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 28px; border-bottom: 1px solid var(--border-color); background-color: #ffffff; }
        .popup-body { padding: 28px; box-sizing: border-box; }
        .popup-footer { padding: 18px 28px; background-color: #f8fafc; border-top: 1px solid var(--border-color); display: flex !important; justify-content: flex-end !important; align-items: center !important; gap: 12px !important; }
        
        .close-popup-btn { text-decoration: none; color: #94a3b8; font-size: 22px; font-weight: bold; line-height: 1; transition: color 0.15s; }
        .close-popup-btn:hover { color: #0f172a; }

        .modal-field-block { width: 100% !important; margin-bottom: 18px !important; display: block !important; }
        .modal-field-label { display: block !important; margin-bottom: 6px !important; font-size: 12px !important; color: #334155 !important; font-weight: 700 !important; text-transform: uppercase; letter-spacing: 0.3px; }
        .modal-input-item { display: block !important; width: 100% !important; box-sizing: border-box !important; padding: 11px 14px !important; font-size: 14px !important; border: 1px solid #cbd5e1 !important; border-radius: 10px !important; font-weight: 500; }
        .modal-input-item:focus { border-color: var(--brand-orange) !important; outline: none !important; box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15) !important; }

        .modal-form-split-row { display: flex !important; flex-direction: row !important; gap: 16px !important; width: 100% !important; margin-bottom: 4px !important; }
        .modal-form-column { flex: 1 !important; }

        /* SMART DROPDOWN DESIGN */
        .dropdown-container { position: relative; width: 100%; }
        .dropdown-select-btn { background-color: #ffffff; border: 1px solid #cbd5e1; padding: 11px 14px; font-size: 14px; font-weight: 500; color: #334155; border-radius: 10px; width: 100%; text-align: left; display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; box-sizing: border-box; }
        .dropdown-select-btn:after { content: ""; border-top: 5px solid #64748b; border-left: 5px solid transparent; border-right: 5px solid transparent; display: inline-block; }

        .dropdown-checklist-menu { position: absolute; top: 100%; left: 0; right: 0; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); margin-top: 6px; max-height: 220px; overflow-y: auto; z-index: 99999; padding: 8px 0; display: none; }
        .dropdown-checklist-menu.open-toggle { display: block !important; }
        .dropdown-master-action { padding: 8px 16px; border-bottom: 1px solid #f1f5f9; margin-bottom: 4px; }

        .checklist-item { padding: 9px 16px; display: flex; align-items: center; gap: 12px; font-size: 13px; font-weight: 600; color: #1e293b; cursor: pointer; transition: background 0.15s; user-select: none; }
        .checklist-item:hover { background-color: #f8fafc; }
        .checklist-item input { width: 16px; height: 16px; accent-color: var(--brand-orange); cursor: pointer; margin: 0; }
        
        .btn-modal-cancel { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 10px 20px; font-size: 13px; font-weight: 700; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-modal-cancel:hover { background-color: #e2e8f0; color: #0f172a; }
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
                <li><a href="dashboard.php" class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="households.php" class="nav-link <?= ($current_page == 'households.php') ? 'active' : ''; ?>"><i class="fa-solid fa-house-user"></i> Household Directory</a></li>
                <li><a href="residents.php" class="nav-link <?= ($current_page == 'residents.php') ? 'active' : ''; ?>"><i class="fa-solid fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link <?= ($current_page == 'face_registration.php') ? 'active' : ''; ?>"><i class="fa-solid fa-user-gear"></i> Personnel</a></li>
                <li><a href="guards.php" class="nav-link <?= ($current_page == 'guards.php') ? 'active' : ''; ?>"><i class="fa-solid fa-user-shield"></i> Staff Guards</a></li>
            </ul>

            <div class="sidebar-section-title">OPERATIONS</div>
            <ul class="sidebar-menu">
                <li><a href="events.php" class="nav-link <?= ($current_page == 'events.php') ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-days"></i> Events</a></li>
                <li><a href="requests.php" class="nav-link <?= ($current_page == 'requests.php') ? 'active' : ''; ?>"><i class="fa-solid fa-file-lines"></i> Requests & Concerns</a></li>
                <li><a href="billing.php" class="nav-link <?= ($current_page == 'billing.php') ? 'active' : ''; ?>"><i class="fa-solid fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link <?= ($current_page == 'expenses.php') ? 'active' : ''; ?>"><i class="fa-solid fa-money-bill-transfer"></i> Expenses</a></li>
            </ul>
        </div>

        <div class="logout-container">
            <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a>
        </div>
    </div>

    <!-- MAIN WORKSPACE -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 page-title">Billing & Resident Accounts</h1>
                <p class="text-muted small mb-0 fw-medium">Manage individual statements, review payment history, and track monthly dues.</p>
            </div>
            <a href="#issueAssessmentModal" class="btn-brand"><i class="fa fa-file-invoice-dollar me-2"></i> Issue New Statement</a>
        </div>

        <!-- ANALYTICS CARDS -->
        <div class="analytics-grid">
            <div class="mini-stat-card">
                <div class="stat-icon-wrapper icon-success"><i class="fa-solid fa-wallet"></i></div>
                <div>
                    <span class="text-muted d-block" style="font-size:11px; text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Gross Revenue Collected</span>
                    <strong class="text-dark fs-4">₱<?php echo number_format($collected_total, 2); ?></strong>
                </div>
            </div>
            <div class="mini-stat-card">
                <div class="stat-icon-wrapper icon-warn"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div>
                    <span class="text-muted d-block" style="font-size:11px; text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Outstanding Receivables</span>
                    <strong class="text-danger fs-4">₱<?php echo number_format($receivables_total, 2); ?></strong>
                </div>
            </div>
            <div class="mini-stat-card">
                <div class="stat-icon-wrapper icon-info"><i class="fa-solid fa-file-lines"></i></div>
                <div>
                    <span class="text-muted d-block" style="font-size:11px; text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Total Statements Compiled</span>
                    <strong class="text-dark fs-4"><?php echo number_format($total_invoices_count); ?></strong>
                </div>
            </div>
        </div>

        <!-- NOTIFICATION ALERTS -->
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success d-flex align-items-center small p-3 mb-4 shadow-sm border-0" style="border-radius:10px;">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i> <div><strong>Success:</strong> <?php echo $success_msg; ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger d-flex align-items-center small p-3 mb-4 shadow-sm border-0" style="border-radius:10px;">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i> <div><strong>Billing Error:</strong> <?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <!-- FILTER TOOLBAR -->
        <div class="filter-card">
            <form method="GET" action="billing.php" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" name="search" class="form-control form-control-custom border-start-0" placeholder="Search resident or lot..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="resident_id" class="form-select form-select-custom">
                        <option value="">All Resident Accounts</option>
                        <?php if ($residents_filter_pool && $residents_filter_pool->num_rows > 0): ?>
                            <?php while($rf = $residents_filter_pool->fetch_assoc()): ?>
                                <option value="<?php echo $rf['id']; ?>" <?php echo $filter_resident == $rf['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rf['full_name']); ?> (<?php echo htmlspecialchars($rf['house_number']); ?>)
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-custom">
                        <option value="">All Statuses</option>
                        <option value="unpaid" <?php echo $filter_status == 'unpaid' ? 'selected' : ''; ?>>Unpaid / Outstanding</option>
                        <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>Paid / Cleared</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="year" class="form-select form-select-custom">
                        <option value="">All Years</option>
                        <?php if ($years_pool && $years_pool->num_rows > 0): ?>
                            <?php while($yr = $years_pool->fetch_assoc()): ?>
                                <option value="<?php echo $yr['billing_year']; ?>" <?php echo $filter_year == $yr['billing_year'] ? 'selected' : ''; ?>>
                                    Year <?php echo $yr['billing_year']; ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 fw-bold" style="font-size: 13px; border-radius: 8px;"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                    <?php if ($filter_resident || $filter_status || $filter_year || $search_query): ?>
                        <a href="billing.php" class="btn btn-outline-secondary px-3" style="font-size: 13px; border-radius: 8px;" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- STATEMENTS LEDGER TABLE -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table custom-table align-middle">
                    <thead>
                        <tr>
                            <th>Household / Resident</th>
                            <th>Billing Month & Purpose</th>
                            <th>Amount Due</th>
                            <th>Payment Deadline</th>
                            <th>Status</th>
                            <th class="text-end" style="padding-right:24px;">Action Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ledger_result && $ledger_result->num_rows > 0): ?>
                            <?php while($row = $ledger_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="resident-avatar-badge">
                                                <?php 
                                                    $words = explode(" ", $row['full_name']);
                                                    echo strtoupper(substr($words[0], 0, 1));
                                                ?>
                                            </div>
                                            <div>
                                                <a href="billing.php?resident_id=<?php echo $row['resident_id']; ?>" class="text-decoration-none text-dark fw-bold hover-orange d-block">
                                                    <?php echo htmlspecialchars($row['full_name']); ?>
                                                </a>
                                                <small class="text-muted fw-semibold"><i class="fa-solid fa-house-user me-1 text-orange"></i><?php echo htmlspecialchars($row['house_number']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-3 py-2 fw-semibold" style="font-size:12px; border-radius: 6px;">
                                            <i class="fa-regular fa-calendar-check me-1.5 text-muted"></i><?php echo htmlspecialchars($row['billing_month']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="<?php echo $row['status'] == 'unpaid' ? 'text-danger' : 'text-success'; ?>" style="font-size: 15px;">
                                            ₱<?php echo number_format($row['amount'], 2); ?>
                                        </strong>
                                    </td>
                                    <td class="text-secondary small fw-medium">
                                        <?php echo date('M d, Y', strtotime($row['due_date'])); ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $today = date('Y-m-d');
                                            $dueDate = date('Y-m-d', strtotime($row['due_date']));
                                        ?>

                                        <?php if ($row['status'] === 'paid'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5 fw-bold" style="font-size:11px; text-transform: uppercase; border-radius: 6px;">
                                                <i class="fa-solid fa-check me-1"></i> Paid
                                            </span>
                                        <?php elseif ($dueDate < $today): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1.5 fw-bold" style="font-size:11px; text-transform: uppercase; border-radius: 6px;">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> Overdue
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-3 py-1.5 fw-bold" style="font-size:11px; text-transform: uppercase; border-radius: 6px;">
                                                <i class="fa-solid fa-hourglass-half me-1"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" style="padding-right:24px;">
                                        <?php if($row['status'] == 'unpaid'): ?>
                                            <a href="billing.php?collect_bill_id=<?php echo $row['bill_id']; ?>" 
                                               onclick="return confirm('Confirm processing cash remittance payment of ₱<?php echo number_format($row['amount'], 2); ?> for <?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>?');" 
                                               class="action-link btn-collect">
                                                <i class="fa-solid fa-cash-register"></i> Collect Payment
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small fw-bold"><i class="fa-solid fa-circle-check text-success me-1"></i> Cleared</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-receipt d-block mb-3 fs-2 text-secondary opacity-50"></i>
                                    <span class="fw-semibold">No statement records found matching your filters.</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ISSUE STATEMENT POPUP MODAL -->
<div id="issueAssessmentModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark" style="font-size:17px;"><i class="fa-solid fa-file-invoice-dollar text-orange me-2"></i>Issue Statement of Account</h5>
            <a href="#" class="close-popup-btn">&times;</a>
        </div>
        <form action="billing.php" method="POST">
            <!-- CSRF TOKEN -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="popup-body">
                
                <div class="modal-field-block">
                    <label class="modal-field-label">Target Resident Account(s)</label>
                    <div class="dropdown-container">
                        <div id="multiDropdownBtn" class="dropdown-select-btn">Select residents...</div>
                        <div id="multiDropdownMenu" class="dropdown-checklist-menu">
                            <div class="dropdown-master-action">
                                <label class="checklist-item p-0 fw-bold text-secondary">
                                    <input type="checkbox" id="selectAllToggle">
                                    <span>Select All Residents</span>
                                </label>
                            </div>
                            <?php if ($residents_pool && $residents_pool->num_rows > 0): ?>
                                <?php while($res = $residents_pool->fetch_assoc()): ?>
                                    <label class="checklist-item">
                                        <input type="checkbox" name="resident_ids[]" class="res-checkbox" value="<?php echo $res['id']; ?>" data-name="<?php echo htmlspecialchars($res['house_number']); ?>">
                                        <span><strong><?php echo htmlspecialchars($res['house_number']); ?></strong> — <?php echo htmlspecialchars($res['full_name']); ?></span>
                                    </label>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-muted small text-center py-2">No active resident profiles registered.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="modal-field-block">
                    <label class="modal-field-label">Billing Month / Purpose Description</label>
                    <input type="text" name="billing_month" class="modal-input-item" required placeholder="e.g., June 2026 HOA Monthly Dues">
                </div>
                
                <div class="modal-form-split-row">
                    <div class="modal-form-column">
                        <div class="modal-field-block" style="margin-bottom:0 !important;">
                            <label class="modal-field-label">Amount Due (PHP)</label>
                            <input type="number" step="0.01" min="1" name="amount" class="modal-input-item" required placeholder="1000.00">
                        </div>
                    </div>
                    <div class="modal-form-column">
                        <div class="modal-field-block" style="margin-bottom:0 !important;">
                            <label class="modal-field-label">Due Date</label>
                            <input type="date" name="due_date" class="modal-input-item" required value="<?php echo date('Y-m-d', strtotime('+15 days')); ?>">
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="popup-footer">
                <a href="#" class="btn-modal-cancel">Cancel</a>
                <button type="submit" name="generate_bill_btn" class="btn-brand">Issue Statements</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const dropdownBtn = document.getElementById('multiDropdownBtn');
    const dropdownMenu = document.getElementById('multiDropdownMenu');
    const masterToggle = document.getElementById('selectAllToggle');
    const individualCheckboxes = document.querySelectorAll('.res-checkbox');

    if (!dropdownBtn || !dropdownMenu) return;

    dropdownBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdownMenu.classList.toggle('open-toggle');
    });

    dropdownMenu.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    document.addEventListener('click', function() {
        dropdownMenu.classList.remove('open-toggle');
    });

    function updateDropdownButtonLabel() {
        const checkedBoxes = document.querySelectorAll('.res-checkbox:checked');
        if (checkedBoxes.length === 0) {
            dropdownBtn.innerText = "Select residents...";
        } else if (checkedBoxes.length === individualCheckboxes.length) {
            dropdownBtn.innerText = "All Residents Selected";
        } else if (checkedBoxes.length <= 2) {
            const selectedLabels = Array.from(checkedBoxes).map(box => box.getAttribute('data-name'));
            dropdownBtn.innerText = selectedLabels.join(', ');
        } else {
            dropdownBtn.innerText = `${checkedBoxes.length} Residents Selected`;
        }
    }

    if(masterToggle) {
        masterToggle.addEventListener('change', function() {
            individualCheckboxes.forEach(box => {
                box.checked = masterToggle.checked;
            });
            updateDropdownButtonLabel();
        });
    }

    individualCheckboxes.forEach(box => {
        box.addEventListener('change', function() {
            if (!this.checked && masterToggle) {
                masterToggle.checked = false;
            } else if (masterToggle) {
                const totalChecked = document.querySelectorAll('.res-checkbox:checked').length;
                if (totalChecked === individualCheckboxes.length) {
                    masterToggle.checked = true;
                }
            }
            updateDropdownButtonLabel();
        });
    });
});
</script>

</body>
</html>