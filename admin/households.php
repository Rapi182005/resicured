<?php 
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=Unauthorized Access");
    exit();
}

if (file_exists('../config/database.php')) {
    require_once '../config/database.php';

    // Group residents by house number
    $houses_query = "
        SELECT 
            house_number, 
            COUNT(*) as member_count 
        FROM residents 
        WHERE house_number IS NOT NULL AND house_number != ''
        GROUP BY house_number 
        ORDER BY house_number ASC
    ";
    $houses_result = $conn->query($houses_query);

    // Fetch residents mapped by house number joining users for email
    $all_residents_query = "
        SELECT 
            r.full_name, 
            r.house_number, 
            r.contact_number, 
            r.resident_type,
            r.registered_vehicle_plate,
            u.email 
        FROM residents r
        LEFT JOIN users u ON r.user_id = u.id
        ORDER BY r.full_name ASC
    ";
    $all_residents_res = $conn->query($all_residents_query);
    
    $household_data = [];
    if ($all_residents_res && $all_residents_res->num_rows > 0) {
        while ($res = $all_residents_res->fetch_assoc()) {
            $h_num = $res['house_number'];
            if (!isset($household_data[$h_num])) {
                $household_data[$h_num] = [];
            }
            $household_data[$h_num][] = $res;
        }
    }
} else {
    $houses_result = false;
    $household_data = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Household Directory</title>
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
        }

        .page-wrapper {
            display: flex !important;
            flex-direction: row !important;
            min-height: 100vh;
            width: 100%;
        }

        /* Sidebar Styling Matching Image */
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

        .main-content {
            flex-grow: 1 !important;
            padding: 36px 40px !important;
            background-color: var(--bg-light) !important;
        }

        /* Search Input Styling */
        .search-box-container .input-group-text {
            border-top-left-radius: 10px;
            border-bottom-left-radius: 10px;
        }
        .search-box-container .form-control {
            border-top-right-radius: 10px;
            border-bottom-right-radius: 10px;
            box-shadow: none !important;
        }
        .search-box-container .form-control:focus {
            border-color: var(--subdivision-orange);
        }

        /* House Cards */
        .house-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }

        .house-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .house-card:hover {
            transform: translateY(-3px);
            border-color: var(--subdivision-orange);
            box-shadow: 0 8px 20px rgba(230, 106, 0, 0.12);
        }

        .house-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background-color: var(--subdivision-orange);
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .house-card:hover::before { opacity: 1; }

        .house-icon-wrapper {
            width: 42px;
            height: 42px;
            background-color: rgba(230, 106, 0, 0.08);
            color: var(--subdivision-orange);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .profile-avatar-modal {
            width: 40px;
            height: 40px;
            background-color: #f1f5f9;
            color: #334155;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="page-wrapper">
    
    <!-- Updated Sidebar -->
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
                <li><a href="dashboard.php" class="nav-link"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="households.php" class="nav-link active"><i class="fa-solid fa-house-user"></i> Household Directory</a></li>
                <li><a href="residents.php" class="nav-link"><i class="fa-solid fa-users"></i> Residents</a></li>
                <li><a href="face_registration.php" class="nav-link"><i class="fa-solid fa-user-gear"></i> Personnel</a></li>
                <li><a href="guards.php" class="nav-link"><i class="fa-solid fa-user-shield"></i> Staff Guards</a></li>
            </ul>

            <div class="sidebar-section-title">OPERATIONS</div>
            <ul class="sidebar-menu">
                <li><a href="events.php" class="nav-link"><i class="fa-solid fa-calendar-days"></i> Events</a></li>
                <li><a href="requests.php" class="nav-link"><i class="fa-solid fa-file-lines"></i> Requests & Concerns</a></li>
                <li><a href="billing.php" class="nav-link"><i class="fa-solid fa-credit-card"></i> Billing</a></li>
                <li><a href="expenses.php" class="nav-link"><i class="fa-solid fa-money-bill-transfer"></i> Expenses</a></li>
            </ul>
        </div>

        <div class="logout-container">
            <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        
        <div class="pb-3 mb-4 border-bottom">
            <h1 class="h3 fw-bold text-dark mb-1">Subdivision Households & Addresses</h1>
            <p class="text-muted small mb-0">Select any house address to view all registered co-residents and occupants.</p>
        </div>

        <!-- Toolbar: Instant Search Bar & Counter -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div class="search-box-container flex-grow-1" style="max-width: 380px;">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted">
                        <i class="fa fa-magnifying-glass"></i>
                    </span>
                    <input type="text" id="houseSearchInput" class="form-control border-start-0 ps-0" placeholder="Search house number or address..." onkeyup="filterHouses()">
                </div>
            </div>
            
            <div>
                <span class="badge bg-white text-dark border px-3 py-2 rounded-pill shadow-sm">
                    <i class="fa fa-building text-warning me-1"></i> Total Houses: <strong id="totalHouseCount"><?php echo $houses_result ? $houses_result->num_rows : 0; ?></strong>
                </span>
            </div>
        </div>

        <!-- Household Grid -->
        <div class="house-grid" id="houseGridContainer">
            <?php if ($houses_result && $houses_result->num_rows > 0): ?>
                <?php while ($house = $houses_result->fetch_assoc()): ?>
                    <?php 
                        $address = $house['house_number'];
                        $count = $house['member_count'];
                    ?>
                    <div class="house-card house-card-item" data-address="<?php echo htmlspecialchars($address); ?>" onclick="openHouseholdModal(<?php echo htmlspecialchars(json_encode($address)); ?>)">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="house-icon-wrapper">
                                <i class="fa fa-house-chimney"></i>
                            </div>
                            <span class="badge rounded-pill bg-light text-dark border">
                                <?php echo $count; ?> Occupant<?php echo $count > 1 ? 's' : ''; ?>
                            </span>
                        </div>
                        <h6 class="fw-bold text-dark mb-1 house-address-title"><?php echo htmlspecialchars($address); ?></h6>
                        <small class="text-muted"><i class="fa fa-location-dot me-1"></i> Click to view occupants</small>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5 bg-white rounded-3 border">
                    <i class="fa fa-home text-muted fs-1 mb-2"></i>
                    <p class="text-secondary mb-0">No registered households found in the database.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Empty search result fallback -->
        <div id="noMatchMessage" class="text-center py-5 bg-white rounded-3 border mt-3" style="display: none;">
            <i class="fa fa-magnifying-glass-minus text-muted fs-2 mb-2"></i>
            <p class="text-secondary fw-medium mb-0">No households match your search query.</p>
        </div>

    </div>
</div>

<!-- Modal to Display Occupants -->
<div class="modal fade" id="householdModal" tabindex="-1" aria-labelledby="householdModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom pb-3">
                <div>
                    <h5 class="modal-title fw-bold text-dark" id="householdModalLabel">Registered Household Occupants</h5>
                    <small class="text-muted" id="modalHouseAddress"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <ul class="list-group list-group-flush" id="modalOccupantsList">
                </ul>
            </div>
            <div class="modal-footer bg-light border-0" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
                <button type="button" class="btn btn-secondary btn-sm px-3 rounded-2" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const householdData = <?php echo json_encode($household_data); ?>;

// Real-time house search filter
function filterHouses() {
    const input = document.getElementById('houseSearchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.house-card-item');
    let visibleCount = 0;

    cards.forEach(card => {
        const address = card.getAttribute('data-address').toLowerCase();
        if (address.includes(input)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    const noMatch = document.getElementById('noMatchMessage');
    if (noMatch) {
        noMatch.style.display = (visibleCount === 0) ? 'block' : 'none';
    }
}

// Modal loader function
function openHouseholdModal(address) {
    const modalTitle = document.getElementById('modalHouseAddress');
    const listContainer = document.getElementById('modalOccupantsList');
    
    modalTitle.innerHTML = `<i class="fa fa-location-dot me-1 text-danger"></i> Address: <strong>${address}</strong>`;
    listContainer.innerHTML = '';

    const residents = householdData[address] || [];

    if (residents.length === 0) {
        listContainer.innerHTML = `
            <li class="list-group-item text-center py-4 text-muted">
                No residents mapped to this address.
            </li>`;
    } else {
        residents.forEach(res => {
            const initials = res.full_name ? res.full_name.substring(0, 2).toUpperCase() : 'RS';
            const phone = res.contact_number ? res.contact_number : 'N/A';
            const email = res.email ? res.email : 'N/A';
            const type = res.resident_type ? res.resident_type : 'Resident';
            const plate = res.registered_vehicle_plate ? res.registered_vehicle_plate : 'None';

            listContainer.innerHTML += `
                <li class="list-group-item p-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="profile-avatar-modal">${initials}</div>
                        <div>
                            <h6 class="mb-0 fw-bold text-dark">${res.full_name}</h6>
                            <div class="small text-muted">
                                <span class="me-3"><i class="fa fa-phone me-1"></i>${phone}</span>
                                <span><i class="fa fa-envelope me-1"></i>${email}</span>
                            </div>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill mb-1 d-block">${type}</span>
                        <small class="text-secondary font-monospace d-block" style="font-size: 11px;">
                            <i class="fa fa-car me-1"></i>Plate: ${plate}
                        </small>
                    </div>
                </li>
            `;
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('householdModal'));
    modal.show();
}
</script>
</body>
</html>