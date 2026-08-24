<?php 
session_start();

// SECURITY GATEWAY: Ensure only logged-in Residents can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

// Fixed directory path to step out of the resident/ folder safely
require_once '../config/database.php';

$resident_user_id = $_SESSION['user_id'];

// FETCH RESIDENT'S ID PROFILE TO MATCH FOREIGN KEYS
$profile_query = "SELECT id, full_name, house_number FROM residents WHERE user_id = '$resident_user_id'";
$profile_result = $conn->query($profile_query);
$resident = $profile_result->fetch_assoc();
$resident_profile_id = $resident['id'] ?? 0;

// FETCH ALL HISTORICAL ASSESSMENTS ISSUED TO THIS HOUSEHOLD
$billings_query = "SELECT id, amount, billing_month, status, due_date, paid_at 
                   FROM billings 
                   WHERE resident_id = '$resident_profile_id' 
                   ORDER BY due_date DESC";
$billings_result = $conn->query($billings_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - My Billings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root { --subdivision-orange: #e66a00; --subdivision-amber: #ffaa00; --text-dark: #2d3748; --bg-light: #f8fafc; }
        body { background-color: var(--bg-light); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 0; }
        .page-wrapper { display: flex; flex-direction: row; min-height: 100vh; width: 100%; }
        .sidebar { width: 260px; min-width: 260px; background-color: #ffffff; border-right: 1px solid #e2e8f0; padding-top: 24px; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box; }
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
        .main-content { flex-grow: 1; padding: 40px; box-sizing: border-box; }
        .portal-title { color: var(--text-dark); font-weight: 700; letter-spacing: -0.5px; margin: 0; }
        .content-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
        .content-card-title { color: var(--text-dark); font-size: 16px; font-weight: 600; margin: 0 0 20px 0; }
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
                <li><a href="dashboard.php" class="nav-link"><i class="fa fa-chart-pie"></i> My Dashboard</a></li>
                <li><a href="dashboard.php#requestPassModal" class="nav-link"><i class="fa fa-qrcode"></i> Create QR Pass</a></li>
                <li><a href="requests.php" class="nav-link"><i class="fa fa-file-invoice"></i> File Request & Concern</a></li>
                <li><a href="resident_billing.php" class="nav-link active"><i class="fa fa-credit-card"></i> My Billings</a></li>
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
                <h1 class="h3 portal-title">Account Ledger & Statements</h1>
                <p class="text-muted small mb-0">Review assessed subdivision statements for <strong><?php echo htmlspecialchars($resident['full_name'] ?? 'Resident'); ?></strong></p>
            </div>
        </div>

        <div class="content-card">
            <h5 class="content-card-title"><i class="fa fa-calculator me-2" style="color: var(--subdivision-orange);"></i> Detailed Billing History</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:14px;">
                    <thead class="table-light" style="font-size:11px; text-transform:uppercase; color:#718096;">
                        <tr>
                            <th>Invoice Reference ID</th>
                            <th>Assessment Description / Month</th>
                            <th>Amount Due</th>
                            <th>Payment Deadline</th>
                            <th>Status State</th>
                            <th>Settled Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($billings_result && $billings_result->num_rows > 0): ?>
                            <?php while($bill = $billings_result->fetch_assoc()): ?>
                                <tr>
                                    <td><code class="text-dark font-monospace fw-bold">#INV-<?php echo str_pad($bill['id'], 5, '0', STR_PAD_LEFT); ?></code></td>
                                    <td><strong><?php echo htmlspecialchars($bill['billing_month']); ?></strong></td>
                                    <td class="fw-bold text-dark">₱<?php echo number_format($bill['amount'], 2); ?></td>
                                    <td><span class="text-secondary"><i class="fa-regular fa-calendar-clock me-1"></i><?php echo date('M d, Y', strtotime($bill['due_date'])); ?></span></td>
                                    <td>
                                        <?php if(strtolower($bill['status']) === 'unpaid'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 text-uppercase" style="font-size:10px;">Unpaid</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 text-uppercase" style="font-size:10px;">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?php echo (!empty($bill['paid_at'])) ? date('M d, Y - h:i A', strtotime($bill['paid_at'])) : '<em class="text-black-50">Pending Settlement</em>'; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4"><i class="fa-solid fa-scale-balanced d-block fs-3 mb-2 text-black-50"></i>No billing assessments generated for your profile identity.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>