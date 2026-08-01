<?php 
session_start();

// 1. SECURITY GATEWAY
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

require_once '../config/database.php';

$success_msg = "";
$error_msg = "";

// ================= ACTION 1: RECORD NEW SUBDIVISION EXPENSE =================
if (isset($_POST['record_expense_btn'])) {
    $amount = floatval($_POST['amount']);
    $details = $conn->real_escape_string($_POST['details']);
    $expense_date = $conn->real_escape_string($_POST['expense_date']);

    // Direct, clean insert now that our database 'created_at' column is active!
    $sql = "INSERT INTO cashflow (transaction_type, amount, details, created_at) 
            VALUES ('expense', $amount, '$details', '$expense_date 00:00:00')";
            
    if ($conn->query($sql)) {
        $success_msg = "Operational expense recorded successfully! Dashboard graphs synchronized.";
    } else {
        $error_msg = "Failed to log expense item: " . $conn->error;
    }
}

// ================= ACTION 2: PROCESS EXPENSE RECORD DELETION =================
if (isset($_GET['delete_expense_id'])) {
    $delete_id = intval($_GET['delete_expense_id']);
    
    if ($conn->query("DELETE FROM cashflow WHERE id = $delete_id AND transaction_type = 'expense'")) {
        header("Location: expenses.php?success=Expense item successfully deleted.");
        exit();
    } else {
        $error_msg = "Failed to clear expense record node.";
    }
}

if (isset($_GET['success'])) { $success_msg = $_GET['success']; }

// 3. FETCH TOTAL OUTFLOW METRIC FOR ANALYTICS CARD
$total_outflow = $conn->query("SELECT SUM(amount) as total FROM cashflow WHERE transaction_type='expense'")->fetch_assoc()['total'] ?? 0;

// 4. FETCH ALL RECORDED EXPENSES FOR THE LEDGER VIEW
$ledger_query = "SELECT id, amount, details, created_at FROM cashflow WHERE transaction_type = 'expense' ORDER BY id DESC";
$ledger_result = $conn->query($ledger_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Expense Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/theme/bootstrap.min.css" rel="stylesheet">
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

        /* Left Sidebar Layout */
        .sidebar {
            width: 260px; min-width: 260px; background-color: #ffffff; border-right: 1px solid #e2e8f0; padding-top: 24px;
            display: flex; flex-direction: column; justify-content: space-between;
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

        /* Main Workspace Content Area */
        .main-content { flex-grow: 1; padding: 40px; background-color: var(--bg-light); box-sizing: border-box; }
        .page-title { color: var(--text-dark); font-weight: 700; letter-spacing: -0.5px; margin: 0; }
        .btn-orange { background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); border: none; color: white; font-weight: 600; font-size: 14px; padding: 10px 20px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
        .btn-orange:hover { opacity: 0.95; color: white; }

        /* Analytics Cards */
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
        .mini-stat-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; max-width: 320px; }
        .stat-icon-wrapper { width: 44px; height: 44px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
        .icon-danger { background-color: #fef2f2; color: #dc2626; }
        
        /* Table Layout Design Elements */
        .table-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01); }
        .custom-table { width: 100%; margin-bottom: 0; }
        .custom-table th { background-color: #f8fafc; color: #718096; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding: 12px 16px; }
        .custom-table td { color: var(--text-dark); font-size: 14px; padding: 16px; vertical-align: middle; border-bottom: 1px solid #edf2f7; }
        
        .action-link { padding: 6px 12px; border-radius: 6px; font-size: 13px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .btn-delete { background-color: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background-color: #fca5a5; }

        /* Pop-up Overlay CSS Engine */
        .custom-modal-backdrop { position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; background-color: rgba(30, 34, 41, 0.6) !important; z-index: 99999 !important; display: none; align-items: center; justify-content: center; padding: 20px; }
        .custom-modal-backdrop:target { display: flex !important; }
        .custom-popup-window { background-color: #ffffff !important; width: 100% !important; max-width: 500px !important; border-radius: 14px !important; box-shadow: 0 15px 35px rgba(0,0,0,0.2) !important; overflow: visible !important; animation: popIn 0.2s ease-out; }
        
        @keyframes popIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .popup-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid #edf2f7; }
        .popup-body { padding: 24px; box-sizing: border-box; }
        .popup-footer { padding: 18px 24px; background-color: #f8fafc; border-top: 1px solid #edf2f7; display: flex !important; justify-content: flex-end !important; align-items: center !important; gap: 12px !important; }
        .close-popup-btn { text-decoration: none; color: #a0aec0; font-size: 20px; font-weight: bold; line-height: 1; }

        .modal-field-block { width: 100% !important; margin-bottom: 20px !important; display: block !important; }
        .modal-field-label { display: block !important; margin-bottom: 6px !important; font-size: 13px !important; color: #4a5568 !important; font-weight: 600 !important; text-align: left !important; }
        .modal-input-item { display: block !important; width: 100% !important; box-sizing: border-box !important; padding: 10px 14px !important; font-size: 14px !important; border: 1px solid #cbd5e1 !important; border-radius: 8px !important; }
        .modal-input-item:focus { border-color: var(--subdivision-orange) !important; outline: none !important; box-shadow: 0 0 0 3px rgba(230, 106, 0, 0.1) !important; }

        .modal-form-split-row { display: flex !important; flex-direction: row !important; gap: 16px !important; width: 100% !important; margin-bottom: 4px !important; box-sizing: border-box !important; min-height: auto !important; }
        .modal-form-column { flex: 1 !important; padding: 0 !important; margin: 0 !important; box-sizing: border-box !important; }
        
        .btn-modal-cancel { background-color: #edf2f7; color: #4a5568; border: 1px solid #cbd5e1; padding: 9px 18px; font-size: 14px; font-weight: 600; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-modal-cancel:hover { background-color: #e2e8f0; color: #2d3748; }
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
                <li><a href="dashboard.php" class="nav-link"><i class="fa fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="residents.php" class="nav-link"><i class="fa fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link"><i class="fa fa-user-shield"></i> Personnel</a></li>
                <li><a href="requests.php" class="nav-link"><i class="fa fa-file-alt"></i> Requests</a></li>
                <li><a href="billing.php" class="nav-link"><i class="fa fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link active"><i class="fa fa-money-bill-transfer"></i> Expenses</a></li>
                <li><a href="guards.php" class="nav-link"><i class="fa fa-user-lock"></i> Staff Guards</a></li>
            </ul>
        </div>
        <div class="logout-btn-container">
            <hr class="mx-3 text-muted">
            <a href="../logout.php" class="nav-link logout-btn"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 page-title">Subdivision Outflows & Expenses</h1>
                <p class="text-muted small mb-0">Log operational maintenance costs, utility payments, and staff payroll.</p>
            </div>
            <a href="#recordExpenseModal" class="btn btn-orange"><i class="fa fa-plus me-2"></i> Log Expense</a>
        </div>

        <div class="analytics-grid">
            <div class="mini-stat-card">
                <div class="stat-icon-wrapper icon-danger"><i class="fa fa-arrow-trend-down"></i></div>
                <div>
                    <span class="text-muted d-block" style="font-size:11px; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">Total Disbursed Expenses</span>
                    <strong class="text-dark fs-5">₱<?php echo number_format($total_outflow, 2); ?></strong>
                </div>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success d-flex align-items-center small p-3 mb-4" style="border-radius:8px;">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i> <div><?php echo $success_msg; ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger d-flex align-items-center small p-3 mb-4" style="border-radius:8px;">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i> <div><strong>Outflow Error:</strong> <?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <div class="table-card">
            <div class="table-responsive">
                <table class="table custom-table">
                    <thead>
                        <tr>
                            <th>Expense Record ID</th>
                            <th>Description / Purpose</th>
                            <th>Amount Disbursed</th>
                            <th>Date Logged</th>
                            <th class="text-end" style="padding-right:24px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ledger_result && $ledger_result->num_rows > 0): ?>
                            <?php while($row = $ledger_result->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge bg-light text-secondary border px-2 py-1">EXP-<?php echo $row['id']; ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($row['details']); ?></strong></td>
                                    <td><strong class="text-danger">₱<?php echo number_format($row['amount'], 2); ?></strong></td>
                                    <td class="text-secondary small"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    <td class="text-end" style="padding-right:24px;">
                                        <a href="expenses.php?delete_expense_id=<?php echo $row['id']; ?>" onclick="return confirm('Permanently delete this expense item entry? This will reverse the graph value calculations.');" class="action-link btn-delete"><i class="fa fa-trash-can"></i> Remove</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fa-solid fa-receipt d-block mb-2 fs-3 text-secondary"></i>No operational expense outlays have been logged yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="recordExpenseModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark" style="font-size:18px;"><i class="fa-solid fa-receipt text-orange me-1.5"></i>Log Operational Expense</h5>
            <a href="#" class="close-popup-btn">&times;</a>
        </div>
        <form action="expenses.php" method="POST">
            <div class="popup-body">
                
                <div class="modal-field-block">
                    <label class="modal-field-label">Expense Description / Purpose</label>
                    <input type="text" name="details" class="modal-input-item" required placeholder="e.g., Security Staff Salaries / Streetlamp Electrical Bill">
                </div>
                
                <div class="modal-form-split-row">
                    <div class="modal-form-column">
                        <div class="modal-field-block" style="margin-bottom:0 !important;">
                            <label class="modal-field-label">Amount Paid (PHP)</label>
                            <input type="number" step="0.01" min="1" name="amount" class="modal-input-item" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="modal-form-column">
                        <div class="modal-field-block" style="margin-bottom:0 !important;">
                            <label class="modal-field-label">Date of Payment</label>
                            <input type="date" name="expense_date" class="modal-input-item" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
                
            </div>
            <div class="popup-footer">
                <a href="#" class="btn-modal-cancel">Cancel</a>
                <button type="submit" name="record_expense_btn" class="btn btn-orange btn-modal-save">Record Expense</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>