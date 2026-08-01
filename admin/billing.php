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

// ================= ACTION 1: MASS-GENERATE BILLS FOR MULTIPLE SELECTIONS =================
if (isset($_POST['generate_bill_btn'])) {
    if (!empty($_POST['resident_ids']) && is_array($_POST['resident_ids'])) {
        $resident_ids = $_POST['resident_ids'];
        $amount = floatval($_POST['amount']);
        $billing_month = $conn->real_escape_string($_POST['billing_month']);
        $due_date = $conn->real_escape_string($_POST['due_date']);

        $conn->begin_transaction();
        try {
            foreach ($resident_ids as $res_id) {
                $res_id = intval($res_id);
                $conn->query("INSERT INTO billings (resident_id, amount, billing_month, due_date, status) 
                              VALUES ($res_id, $amount, '$billing_month', '$due_date', 'unpaid')");
            }

            $conn->commit();
            $success_msg = "Successfully generated and issued " . count($resident_ids) . " statements of account updates.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Failed to completely batch deploy invoice rows.";
        }
    } else {
        $error_msg = "Invalid Action: Please select at least one resident before saving.";
    }
}

// ================= ACTION 2: PROCESS COLLECTION TOGGLE (MARK AS PAID) =================
if (isset($_GET['collect_bill_id'])) {
    $bill_id = intval($_GET['collect_bill_id']);
    
    // Safely query the database to prevent a silent error if the query itself encounters an issue
    $bill_query = $conn->query("SELECT resident_id, amount, billing_month FROM billings WHERE id = $bill_id");
    
    if ($bill_query && $bill_query->num_rows > 0) {
        $bill_data = $bill_query->fetch_assoc();
        $resident_id = $bill_data['resident_id'];
        $amount = $bill_data['amount'];
        $details = $conn->real_escape_string("HOA Dues - " . $bill_data['billing_month']);

        // Execute direct standalone updates instead of transaction nesting to bypass strict offline MySQL engine limitations
        $update_status = $conn->query("UPDATE billings SET status = 'paid' WHERE id = $bill_id");
        
        if ($update_status) {
            // Check if cashflow analytics module table exists before injecting data logs
            $table_check = $conn->query("SHOW TABLES LIKE 'cashflow'");
            if ($table_check && $table_check->num_rows > 0) {
                $conn->query("INSERT INTO cashflow (transaction_type, amount, details) VALUES ('income', $amount, '$details')");
            }
            
            header("Location: billing.php?success=Payment collected successfully! Ledger updated.");
            exit();
        } else {
            $error_msg = "Failed to execute update statement: " . $conn->error;
        }
    } else {
        $error_msg = "Error: Invoice record matching ID (" . $bill_id . ") could not be found or verified.";
    }
}

if (isset($_GET['success'])) { $success_msg = $_GET['success']; }

// 3. FETCH METRICS AGGREGATIONS FOR ANALYTICS HEADER CARDS
$collected_total = $conn->query("SELECT SUM(amount) as total FROM billings WHERE status='paid'")->fetch_assoc()['total'] ?? 0;
$receivables_total = $conn->query("SELECT SUM(amount) as total FROM billings WHERE status='unpaid'")->fetch_assoc()['total'] ?? 0;

// 4. FETCH ALL INVOICES WITH RESIDENT PROFILES COUPLING
$ledger_query = "SELECT b.id as bill_id, b.amount, b.billing_month, b.due_date, b.status, r.full_name, r.house_number 
                 FROM billings b 
                 JOIN residents r ON b.resident_id = r.id 
                 ORDER BY b.id DESC";
$ledger_result = $conn->query($ledger_query);

// Fetch active selection pool lists to feed the dropdown checkboxes
$residents_pool = $conn->query("SELECT id, full_name, house_number FROM residents ORDER BY house_number ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Financial Management</title>
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

        /* Main Content Workspace Layout */
        .main-content { flex-grow: 1; padding: 40px; background-color: var(--bg-light); box-sizing: border-box; }
        .page-title { color: var(--text-dark); font-weight: 700; letter-spacing: -0.5px; margin: 0; }
        .btn-orange { background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); border: none; color: white; font-weight: 600; font-size: 14px; padding: 10px 20px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
        .btn-orange:hover { opacity: 0.95; color: white; }

        /* Analytics Metric Row Blocks */
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
        .mini-stat-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; }
        .stat-icon-wrapper { width: 44px; height: 44px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
        .icon-success { background-color: #f0fdf4; color: #16a34a; }
        .icon-warn { background-color: #fff7ed; color: #ea580c; }
        
        /* Table Layout Design Elements */
        .table-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01); }
        .custom-table { width: 100%; margin-bottom: 0; }
        .custom-table th { background-color: #f8fafc; color: #718096; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding: 12px 16px; }
        .custom-table td { color: var(--text-dark); font-size: 14px; padding: 16px; vertical-align: middle; border-bottom: 1px solid #edf2f7; }
        
        .action-link { padding: 6px 12px; border-radius: 6px; font-size: 13px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .btn-collect { background-color: #dcfce7; color: #15803d; }
        .btn-collect:hover { background-color: #bbf7d0; }

        /* ================= PURE CSS POP-UP OVERLAY ENGINE ================= */
        .custom-modal-backdrop { 
            position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; 
            background-color: rgba(30, 34, 41, 0.6) !important; z-index: 99999 !important; 
            display: none; align-items: center; justify-content: center; padding: 20px; 
        }
        .custom-modal-backdrop:target { display: flex !important; }
        
        .custom-popup-window { 
            background-color: #ffffff !important; width: 100% !important; max-width: 540px !important; 
            border-radius: 14px !important; box-shadow: 0 15px 35px rgba(0,0,0,0.2) !important; 
            overflow: visible !important; animation: popIn 0.2s ease-out; 
        }
        
        @keyframes popIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .popup-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid #edf2f7; }
        .popup-body { padding: 24px; box-sizing: border-box; }
        
        .popup-footer { 
            padding: 18px 24px; background-color: #f8fafc; border-top: 1px solid #edf2f7; 
            display: flex !important; justify-content: flex-end !important; align-items: center !important; gap: 12px !important; 
        }
        
        .close-popup-btn { text-decoration: none; color: #a0aec0; font-size: 20px; font-weight: bold; line-height: 1; }
        .close-popup-btn:hover { color: #4a5568; }

        .modal-field-block { width: 100% !important; margin-bottom: 20px !important; display: block !important; }
        .modal-field-label { display: block !important; margin-bottom: 6px !important; font-size: 13px !important; color: #4a5568 !important; font-weight: 600 !important; text-align: left !important; }
        .modal-input-item { display: block !important; width: 100% !important; box-sizing: border-box !important; padding: 10px 14px !important; font-size: 14px !important; border: 1px solid #cbd5e1 !important; border-radius: 8px !important; }
        .modal-input-item:focus { border-color: var(--subdivision-orange) !important; outline: none !important; box-shadow: 0 0 0 3px rgba(230, 106, 0, 0.1) !important; }

        .modal-form-split-row { display: flex !important; flex-direction: row !important; gap: 16px !important; width: 100% !important; margin-bottom: 4px !important; box-sizing: border-box !important; min-height: auto !important; }
        .modal-form-column { flex: 1 !important; padding: 0 !important; margin: 0 !important; box-sizing: border-box !important; }

        /* SMART DROPDOWN DESIGN HANDLES */
        .dropdown-container { position: relative; width: 100%; }
        .dropdown-select-btn { background-color: #ffffff; border: 1px solid #cbd5e1; padding: 11px 14px; font-size: 14px; color: #4a5568; border-radius: 8px; width: 100%; text-align: left; display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; box-sizing: border-box; }
        .dropdown-select-btn:after { content: ""; border-top: 5px solid #4a5568; border-left: 5px solid transparent; border-right: 5px solid transparent; display: inline-block; }
        .dropdown-select-btn.open-toggle:after { content: ""; border-bottom: 5px solid #4a5568; border-top: none; display: inline-block; }

        .dropdown-checklist-menu { position: absolute; top: 100%; left: 0; right: 0; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); margin-top: 6px; max-height: 200px; overflow-y: auto; z-index: 99999; padding: 8px 0; display: none; }
        .dropdown-checklist-menu.open-toggle { display: block !important; }
        .dropdown-master-action { padding: 6px 16px 8px 16px; border-bottom: 1px solid #edf2f7; margin-bottom: 6px; }

        .checklist-item { padding: 8px 16px; display: flex; align-items: center; gap: 12px; font-size: 14px; color: var(--text-dark); cursor: pointer; transition: background 0.15s; user-select: none; }
        .checklist-item:hover { background-color: var(--bg-light); }
        .checklist-item input { width: 16px; height: 16px; accent-color: var(--subdivision-orange); cursor: pointer; margin: 0; }
        
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
                <li><a href="billing.php" class="nav-link active"><i class="fa fa-credit-card"></i> Billing</a></li>
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
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 page-title">Billing & Collections</h1>
                <p class="text-muted small mb-0">Assess community accounts, issue electronic statements, and handle collection dues.</p>
            </div>
            <a href="#issueAssessmentModal" class="btn btn-orange"><i class="fa fa-file-invoice-dollar me-2"></i> Create Invoice</a>
        </div>

        <div class="analytics-grid">
            <div class="mini-stat-card">
                <div class="stat-icon-wrapper icon-success"><i class="fa fa-money-bill-wave"></i></div>
                <div>
                    <span class="text-muted d-block" style="font-size:11px; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">Gross Collected Revenue</span>
                    <strong class="text-dark fs-5">₱<?php echo number_format($collected_total, 2); ?></strong>
                </div>
            </div>
            <div class="mini-stat-card">
                <div class="stat-icon-wrapper icon-warn"><i class="fa fa-hand-holding-dollar"></i></div>
                <div>
                    <span class="text-muted d-block" style="font-size:11px; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">Outstanding Receivables</span>
                    <strong class="text-danger fs-5">₱<?php echo number_format($receivables_total, 2); ?></strong>
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
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i> <div><strong>Billing Error:</strong> <?php echo $error_msg; ?></div>
            </div>
        <?php endif; ?>

        <div class="table-card">
            <div class="table-responsive">
                <table class="table custom-table" id="billingLedgerTable">
                    <thead>
                        <tr>
                            <th>Household / Owner</th>
                            <th>Assessment Description</th>
                            <th>Amount Due</th>
                            <th>Payment Deadline</th>
                            <th>State Status</th>
                            <th class="text-end" style="padding-right:24px;">Action Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ledger_result && $ledger_result->num_rows > 0): ?>
                            <?php while($row = $ledger_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                        <small class="text-muted fw-bold"><i class="fa fa-home me-1"></i><?php echo htmlspecialchars($row['house_number']); ?></small>
                                    </td>
                                    <td><span class="badge bg-light text-secondary border px-2.5 py-1.5 fw-semibold" style="font-size:12px;"><?php echo htmlspecialchars($row['billing_month']); ?> Dues</span></td>
                                    <td><strong class="<?php echo $row['status'] == 'unpaid' ? 'text-danger' : 'text-success'; ?>">₱<?php echo number_format($row['amount'], 2); ?></strong></td>
                                    <td class="text-secondary small"><?php echo date('M d, Y', strtotime($row['due_date'])); ?></td>
                                    <td>
                                        <?php if($row['status'] == 'paid'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5" style="font-size:11px; text-transform: uppercase;">Cleared & Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5" style="font-size:11px; text-transform: uppercase;">Outstanding</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" style="padding-right:24px;">
                                        <?php if($row['status'] == 'unpaid'): ?>
                                            <a href="billing.php?collect_bill_id=<?php echo $row['bill_id']; ?>" onclick="return confirm('Confirm processing cash remittance payment of ₱<?php echo number_format($row['amount'], 2); ?> for <?php echo htmlspecialchars($row['full_name']); ?>?');" class="action-link btn-collect"><i class="fa fa-cash-register"></i> Collect Payment</a>
                                        <?php else: ?>
                                            <span class="text-muted small fw-semibold"><i class="fa-solid fa-check-double text-success me-1"></i> Logged to Charts</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fa-solid fa-scale-unbalanced d-block mb-2 fs-3 text-secondary"></i>No account invoices have been compiled yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="issueAssessmentModal" class="custom-modal-backdrop">
    <div class="custom-popup-window">
        <div class="popup-header">
            <h5 class="m-0 fw-bold text-dark" style="font-size:18px;"><i class="fa-solid fa-file-invoice-dollar text-orange me-1.5"></i>Create Assessment Invoice</h5>
            <a href="#" class="close-popup-btn">&times;</a>
        </div>
        <form action="billing.php" method="POST">
            <div class="popup-body">
                
                <div class="modal-field-block">
                    <label class="modal-field-label">Target Household Profiles</label>
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
                                <div class="text-muted small text-center py-2">No profiles registered.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="modal-field-block">
                    <label class="modal-field-label">Assessment Month / Purpose Description</label>
                    <input type="text" name="billing_month" class="modal-input-item" required placeholder="e.g., June 2026 Monthly Dues">
                </div>
                
                <div class="modal-form-split-row">
                    <div class="modal-form-column">
                        <div class="modal-field-block" style="margin-bottom:0 !important;">
                            <label class="modal-field-label">Amount Due (PHP)</label>
                            <input type="number" step="0.01" min="1" name="amount" class="modal-input-item" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="modal-form-column">
                        <div class="modal-field-block" style="margin-bottom:0 !important;">
                            <label class="modal-field-label">Payment Deadline Date</label>
                            <input type="date" name="due_date" class="modal-input-item" required value="<?php echo date('Y-m-d', strtotime('+15 days')); ?>">
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="popup-footer">
                <a href="#" class="btn-modal-cancel">Cancel Request</a>
                <button type="submit" name="generate_bill_btn" class="btn btn-orange btn-modal-save">Issue Statement</button>
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
        this.classList.toggle('open-toggle');
        dropdownMenu.classList.toggle('open-toggle');
    });

    dropdownMenu.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    document.addEventListener('click', function() {
        dropdownBtn.classList.remove('open-toggle');
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

    masterToggle.addEventListener('change', function() {
        individualCheckboxes.forEach(box => {
            box.checked = masterToggle.checked;
        });
        updateDropdownButtonLabel();
    });

    individualCheckboxes.forEach(box => {
        box.addEventListener('change', function() {
            if (!this.checked) {
                masterToggle.checked = false;
            } else {
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