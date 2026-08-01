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
    <title>ResiCured - Biometric Terminal</title>
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
            height: 100%;
        }
        .video-box-frame { 
            background: #1e2229; 
            border-radius: 8px; 
            position: relative; 
            overflow: hidden; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 600px; 
            margin-bottom: 16px; 
            border: 1px solid #cbd5e1;
        }
        .native-video-tag { 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            background: #000; 
            position: absolute; 
        }
        .btn-custom-orange { 
            background-color: var(--subdivision-orange) !important; 
            color: white !important; 
            border: none; 
            padding: 10px 24px; 
            border-radius: 6px; 
            font-weight: 600; 
        }
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
                <li><a href="dashboard.php" class="nav-link"><i class="fa fa-chart-line"></i> Operational Logs</a></li>
                <li><a href="face_scanner.php" class="nav-link active"><i class="fa fa-user-shield"></i> Face Scanner</a></li>
                <li><a href="qr_scanner.php" class="nav-link"><i class="fa fa-qrcode"></i> QR Pass Scanner</a></li>
            </ul>
        </div>
        <div class="mb-4"><a href="../logout.php" class="nav-link text-danger"><i class="fa fa-sign-out-alt"></i> Logout</a></div>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <div>
                <h1 class="h3 fw-bold text-dark m-0">Biometric Identity Console</h1>
                <p class="text-muted small mb-0">Verify perimeter entrance authorization using direct face recognition mapping checks.</p>
            </div>
            <div id="engineStatusIndicator" class="badge bg-secondary p-2">Checking Engine status...</div>
        </div>

        <div id="systemAuthFeedback" class="alert d-none align-items-center small p-3 mb-4" style="border-radius: 8px;">
            <i id="feedbackIcon" class="fa-solid me-2 fs-5"></i>
            <div id="feedbackMessage"></div>
        </div>

        <div class="row g-4">
            <div class="col-xl-9 col-lg-8 col-md-12">
                <div class="content-card">
                    <div class="video-box-frame" id="biometricVideoContainer">
                        <div id="faceScanOverlay" class="text-center text-white-50 p-4">
                            <i class="fa-solid fa-video-slash d-block mb-3 text-muted" style="font-size: 3rem;"></i>
                            <span class="fw-bold d-block mb-1 text-white">Camera Tracking Module Offline</span>
                            <span class="small text-muted">Click the button below to initialize the high-definition perimeter stream feed.</span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button id="btnToggleFaceCam" class="btn btn-dark fw-bold px-4"><i class="fa-solid fa-camera me-2"></i> Start Face Camera</button>
                        <button id="btnVerifyFace" class="btn btn-custom-orange fw-bold" disabled><i class="fa-solid fa-user-check me-2"></i> Verify Identity</button>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-12">
                <div class="content-card d-flex flex-column justify-content-start">
                    <h5 class="fw-bold mb-3 text-dark" style="font-size: 16px;"><i class="fa-solid fa-id-card text-success me-2"></i> Identity Clearance</h5>
                    <hr class="mt-0 mb-4">
                    
                    <div id="clearanceDataBox" class="d-none">
                        <div class="mb-4">
                            <label class="text-muted small text-uppercase fw-bold d-block mb-1" style="font-size:11px;">Identified Target</label>
                            <span id="lblVerifiedName" class="fw-bold text-success h5 d-block m-0">-</span>
                        </div>
                        
                        <div class="mb-4">
                            <label class="text-muted small text-uppercase fw-bold d-block mb-1" style="font-size:11px;">Classification Group</label>
                            <span id="lblVerifiedRole" class="badge bg-primary px-2.5 py-1.5 fw-semibold" style="font-size:11px;">-</span>
                        </div>

                        <div class="mb-4 bg-light border p-3 rounded shadow-sm">
                            <label class="text-muted small text-uppercase fw-bold d-block mb-2" style="font-size:11px;">Gate Transaction Activity</label>
                            <div class="d-flex align-items-center justify-content-between">
                                <span id="lblLogActionBadge" class="badge px-3 py-2 fs-6 fw-bold">-</span>
                                <span id="lblLogTimeText" class="text-dark small fw-bold">-</span>
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <label class="text-muted small text-uppercase fw-bold d-block mb-1" style="font-size:11px;">Verification Matrix Metadata</label>
                            <span id="lblVerifiedDest" class="text-dark small d-block fw-medium bg-light p-2.5 border rounded" style="line-height: 1.4;">-</span>
                        </div>
                    </div>
                    
                    <div id="emptyDataBox" class="text-muted small text-center py-5 my-auto border rounded bg-light border-dashed">
                        <i class="fa-solid fa-fingerprint d-block fs-3 mb-2 text-muted"></i>
                        Awaiting local capture frame parsing matrices.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    let isEngineBackendOnline = false, localStreamMedia = null;
    let personnelLogTracker = JSON.parse(localStorage.getItem('personnelLogTracker')) || {};

    const videoContainer = document.getElementById('biometricVideoContainer');
    const btnToggleFaceCam = document.getElementById('btnToggleFaceCam');
    const btnVerifyFace = document.getElementById('btnVerifyFace');

    function checkEngine() {
        fetch('http://127.0.0.1:5000/status', { method: 'GET', mode: 'cors' })
        .then(res => { if(!res.ok) throw new Error(); return res.json(); })
        .then(() => {
            isEngineBackendOnline = true;
            document.getElementById('engineStatusIndicator').className = "badge bg-success p-2";
            document.getElementById('engineStatusIndicator').innerHTML = "<i class='fa-solid fa-shield'></i> Engine Link Active";
            if (localStreamMedia) btnVerifyFace.disabled = false;
        })
        .catch(() => {
            isEngineBackendOnline = false;
            btnVerifyFace.disabled = true;
            document.getElementById('engineStatusIndicator').className = "badge bg-danger p-2";
            document.getElementById('engineStatusIndicator').innerHTML = "<i class='fa-solid fa-xmark'></i> Engine Offline";
        });
    }
    checkEngine(); 
    setInterval(checkEngine, 5000);

    btnToggleFaceCam.addEventListener('click', function() {
        if (!isEngineBackendOnline) { 
            showFeedback("Cannot initialize hardware tracking: Python script service connection refused.", "danger"); 
            return; 
        }
        
        if (!localStreamMedia) {
            navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 } } })
            .then(stream => {
                localStreamMedia = stream;
                videoContainer.innerHTML = '<video id="nativeWebcamView" class="native-video-tag" autoplay playsinline></video>';
                document.getElementById('nativeWebcamView').srcObject = stream;
                btnToggleFaceCam.className = "btn btn-danger fw-bold px-4";
                btnToggleFaceCam.innerHTML = '<i class="fa-solid fa-stop me-2"></i> Stop Camera';
                btnVerifyFace.disabled = false;
                showFeedback("Webcam capturing framework connected successfully.", "warning");
            })
            .catch(err => {
                showFeedback("Hardware Error: Browser failed to secure capture permission handle.", "danger");
            });
        } else {
            stopCameraHardware();
        }
    });

    function stopCameraHardware() {
        if (localStreamMedia) {
            localStreamMedia.getTracks().forEach(t => t.stop()); 
            localStreamMedia = null;
        }
        videoContainer.innerHTML = `
            <div id="faceScanOverlay" class="text-center text-white-50 p-4">
                <i class="fa-solid fa-video-slash d-block mb-3 text-muted" style="font-size: 3rem;"></i>
                <span class="fw-bold d-block mb-1 text-white">Camera Tracking Module Offline</span>
                <span class="small text-muted">Click the button below to initialize the high-definition perimeter stream feed.</span>
            </div>`;
        btnToggleFaceCam.className = "btn btn-dark fw-bold px-4";
        btnToggleFaceCam.innerHTML = '<i class="fa-solid fa-camera me-2"></i> Start Face Camera';
        btnVerifyFace.disabled = true;
    }

    btnVerifyFace.addEventListener('click', function() {
        const v = document.getElementById('nativeWebcamView');
        if (!v || !localStreamMedia) return;

        const canvas = document.createElement('canvas');
        canvas.width = v.videoWidth; 
        canvas.height = v.videoHeight;
        canvas.getContext('2d').drawImage(v, 0, 0);
        
        showFeedback("Analyzing vector alignments and cross-referencing system database...", "warning");

        fetch('http://127.0.0.1:5000/api/scan', {
            method: 'POST', 
            mode: 'cors',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ image: canvas.toDataURL('image/jpeg') })
        })
        .then(res => res.json())
        .then(data => {
            if(data.status === "verified" && data.data) {
                document.getElementById('lblVerifiedName').textContent = data.data.full_name;
                
                let rawRole = data.data.role_type ? data.data.role_type.toString().trim() : 'Service Contractor';
                document.getElementById('lblVerifiedRole').textContent = rawRole;
                
                let actionBadge = document.getElementById('lblLogActionBadge');
                let personIdKey = rawRole + "_" + data.data.id;
                let logTypeString = "entry"; 

                if (rawRole.toLowerCase() === 'resident') {
                    actionBadge.className = "badge bg-success px-3 py-2 fs-6";
                    actionBadge.textContent = "VERIFIED RESIDENT";
                    document.getElementById('lblVerifiedDest').textContent = "Subdivision Resident verified. Entry authorized.";
                    logTypeString = "entry";
                } else {
                    if (!personnelLogTracker[personIdKey] || personnelLogTracker[personIdKey] === 'TIMEOUT') {
                        personnelLogTracker[personIdKey] = 'TIMEIN';
                        actionBadge.className = "badge bg-primary px-3 py-2 fs-6";
                        actionBadge.textContent = "TIME IN";
                        logTypeString = "entry";
                    } else {
                        personnelLogTracker[personIdKey] = 'TIMEOUT';
                        actionBadge.className = "badge bg-danger px-3 py-2 fs-6";
                        actionBadge.textContent = "TIME OUT";
                        logTypeString = "exit";
                    }
                    localStorage.setItem('personnelLogTracker', JSON.stringify(personnelLogTracker));
                    
                    let vehicleDetails = data.data.registered_vehicle_plate || data.data.vehicle_plate;
                    document.getElementById('lblVerifiedDest').textContent = vehicleDetails ? `Vehicle Plate: ${vehicleDetails}` : 'Personnel profile logs processed successfully.';
                }

                let now = new Date();
                document.getElementById('lblLogTimeText').textContent = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                document.getElementById('clearanceDataBox').classList.remove('d-none');
                document.getElementById('emptyDataBox').classList.add('d-none');
                
                // BACKEND PROPAGATION CALL: Insert record into the SQL access_logs table
                fetch('save_log.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `person_type=${encodeURIComponent(rawRole)}&person_id=${encodeURIComponent(data.data.id)}&log_type=${encodeURIComponent(logTypeString)}`
                })
                .then(logRes => logRes.json())
                .then(logData => {
                    if(logData.status === "success") {
                        showFeedback(`Access Clear: Entry saved into database.`, "success");
                    } else {
                        showFeedback(`Identity Verified, but logging failed: ${logData.message}`, "warning");
                    }
                })
                .catch(() => {
                    showFeedback(`Identity Verified, but server log pipe is unreachable.`, "warning");
                });

                new Audio('https://assets.mixkit.co/active_storage/sfx/2568/2568-84.wav').play().catch(()=>{});
            } else {
                showFeedback(data.message || "Access Denied: Biometric parameters signature does not match.", "danger");
                document.getElementById('clearanceDataBox').classList.add('d-none');
                document.getElementById('emptyDataBox').classList.remove('d-none');
            }
        })
        .catch(err => {
            showFeedback("Engine Failure: Dropped connectivity data stream framework with Flask backend.", "danger");
        });
    });

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