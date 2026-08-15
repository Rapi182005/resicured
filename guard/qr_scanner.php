<?php 
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - QR Pass Scanner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --subdivision-orange: #e66a00; --subdivision-amber: #ffaa00; --text-dark: #2d3748; --bg-light: #f8fafc; }
        body { background-color: var(--bg-light); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; }
        .page-wrapper { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; min-width: 260px; background: #fff; border-right: 1px solid #e2e8f0; padding-top: 24px; display: flex; flex-direction: column; justify-content: space-between; }
        .brand-logo-area { padding: 0 24px 20px 24px; display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar .nav-link { color: #4a5568; font-size: 14px; font-weight: 500; padding: 12px 20px; margin: 4px 16px; border-radius: 8px; display: flex; align-items: center; text-decoration: none; }
        .sidebar .nav-link.active { color: #fff; background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%); font-weight: 600; }
        .sidebar .nav-link i { font-size: 16px; width: 28px; }
        .main-content { flex-grow: 1; padding: 40px; }
        .content-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
        .video-box-frame { background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 8px; min-height: 380px; padding: 16px; display: flex; align-items: center; justify-content: center; }
        #qr-reader { border: none !important; width: 100% !important; }
        #qr-reader__dashboard_section_csr button, .btn-custom-orange { background-color: var(--subdivision-orange) !important; color: white !important; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; }
        .timestamp-box { background: #f1f5f9; border-radius: 8px; padding: 10px 14px; border: 1px solid #e2e8f0; }
    </style>
</head>
<body>
<div class="page-wrapper">
    <div class="sidebar">
        <div>
            <div class="brand-logo-area border-bottom"><i class="fa fa-shield-halved text-warning fs-4"></i><h4 class="fw-bold m-0 text-dark" style="font-size:20px;">ResiCured</h4></div>
            <ul class="sidebar-menu mt-3">
                <li><a href="dashboard.php" class="nav-link"><i class="fa fa-chart-line"></i> Operational Logs</a></li>
                <li><a href="face_scanner.php" class="nav-link"><i class="fa fa-user-shield"></i> Face Scanner</a></li>
                <li><a href="qr_scanner.php" class="nav-link active"><i class="fa fa-qrcode"></i> QR Pass Scanner</a></li>
            </ul>
        </div>
        <div class="mb-4"><a href="../logout.php" class="nav-link text-danger"><i class="fa fa-sign-out-alt"></i> Logout</a></div>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 fw-bold text-dark m-0">Optical QR Code Scanner</h1>
                <p class="text-muted small mb-0">Scan guest visitor passes to log Time In and Time Out status.</p>
            </div>
        </div>

        <div id="systemAuthFeedback" class="alert d-none align-items-center small p-3 mb-4" style="border-radius: 8px;">
            <i id="feedbackIcon" class="fa-solid me-2 fs-5"></i><div id="feedbackMessage"></div>
        </div>

        <div class="row g-4">
            <div class="col-md-7">
                <div class="content-card">
                    <div class="video-box-frame">
                        <div id="qr-reader" class="w-100"></div>
                    </div>
                    <div class="d-flex justify-content-end mt-3">
                        <button id="btnStartQRScanner" class="btn btn-custom-orange fw-bold"><i class="fa-solid fa-qrcode me-1"></i> Open Optical Hardware Scanner</button>
                    </div>
                </div>
            </div>

            <div class="col-md-5">
                <div class="content-card h-100">
                    <h5 class="fw-bold mb-3"><i class="fa-solid fa-id-card text-success me-2"></i> Visitor Clearance Parameters</h5>
                    <hr>
                    <div id="clearanceDataBox" class="d-none">
                        <!-- SCAN STATUS BADGE -->
                        <div class="mb-3 text-center">
                            <span id="lblActionType" class="badge bg-success fs-6 px-3 py-2">ENTRY LOGGED (TIME IN)</span>
                        </div>

                        <p class="mb-2"><strong>Visitor Name:</strong> <br><span id="lblVerifiedName" class="fw-bold text-dark fs-5">-</span></p>
                        <p class="mb-3"><strong>Host Destination Assignment:</strong> <br><span id="lblVerifiedDest" class="text-muted small">-</span></p>

                        <!-- TIMESTAMPS DISPLAY -->
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="timestamp-box">
                                    <small class="text-muted d-block fw-bold"><i class="fa-solid fa-right-to-bracket text-success me-1"></i> Time In</small>
                                    <span id="lblTimeIn" class="fw-bold text-dark small">-</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="timestamp-box">
                                    <small class="text-muted d-block fw-bold"><i class="fa-solid fa-right-from-bracket text-danger me-1"></i> Time Out</small>
                                    <span id="lblTimeOut" class="fw-bold text-dark small">Still Inside</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="emptyDataBox" class="text-muted small text-center py-4">Scan a physical pass paper/token sheet array to parse values.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    let lastScannedToken = "", isProcessing = false, qrInstance = null;
    const btnStart = document.getElementById('btnStartQRScanner');

    btnStart.addEventListener('click', function() {
        document.getElementById("qr-reader").innerHTML = "";
        btnStart.disabled = true;
        btnStart.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Starting Cam Sensor...';

        qrInstance = new Html5QrcodeScanner("qr-reader", { fps: 12, qrbox: { width: 250, height: 250 } });
        qrInstance.render(onScanSuccess);
        
        setTimeout(() => {
            btnStart.className = "btn btn-outline-secondary fw-bold";
            btnStart.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Capture Framework Active';
        }, 1200);
    });

    function onScanSuccess(text) {
        let token = text.trim();
        if (token.includes("PassToken:")) token = token.split("PassToken:")[1].trim();
        if (!token || token === lastScannedToken || isProcessing) return;

        isProcessing = true; lastScannedToken = token;
        showFeedback("Processing security access database signature alignment verification...", "warning");

        let fd = new FormData(); fd.append('qr_token', token);
        fetch('verify_qr.php', { method: 'POST', body: fd })
        .then(res => res.json()).then(data => {
            if(data.success) {
                showFeedback(`Access Recorded: ${data.action_type} successfully logged.`, "success");
                
                // Populate Visitor Details
                document.getElementById('lblVerifiedName').textContent = data.visitor_name;
                document.getElementById('lblVerifiedDest').textContent = `House No. ${data.house_number} (Resident Host: ${data.resident_name})`;
                
                // Update Movement Status & Timestamps
                const actionBadge = document.getElementById('lblActionType');
                if (data.action_type === 'TIME IN') {
                    actionBadge.className = 'badge bg-success fs-6 px-3 py-2';
                    actionBadge.textContent = 'ENTRY LOGGED (TIME IN)';
                } else {
                    actionBadge.className = 'badge bg-danger fs-6 px-3 py-2';
                    actionBadge.textContent = 'EXIT LOGGED (TIME OUT)';
                }

                document.getElementById('lblTimeIn').textContent = data.time_in ? data.time_in : '-';
                document.getElementById('lblTimeOut').textContent = data.time_out ? data.time_out : 'Still Inside';

                document.getElementById('clearanceDataBox').classList.remove('d-none');
                document.getElementById('emptyDataBox').classList.add('d-none');
                new Audio('https://assets.mixkit.co/active_storage/sfx/2568/2568-84.wav').play().catch(()=>{});
                
                setTimeout(() => { location.reload(); }, 4000);
            } else {
                showFeedback(data.message, "danger");
                document.getElementById('clearanceDataBox').classList.add('d-none');
                document.getElementById('emptyDataBox').classList.remove('d-none');
                lastScannedToken = ""; isProcessing = false;
            }
        }).catch(() => { isProcessing = false; lastScannedToken = ""; });
    }

    function showFeedback(m, t) {
        const b = document.getElementById('systemAuthFeedback');
        b.className = `alert d-flex align-items-center small p-3 mb-4 alert-${t}`;
        document.getElementById('feedbackMessage').textContent = m;
        b.classList.remove('d-none');
    }
});
</script>
</body>
</html>