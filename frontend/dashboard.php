<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];

$isExportAllowed = in_array($role, ["Administrator", "User (Technical)"]);

// =====================
// CONTRACT STATS
// =====================
$totalContracts = $mysqli->query("SELECT COUNT(*) as total FROM project_inventory")->fetch_assoc()['total'];

$activeContracts = $mysqli->query("
SELECT COUNT(*) as total FROM project_inventory
WHERE CURDATE() BETWEEN contract_start AND contract_end
")->fetch_assoc()['total'];

$expiringContracts = $mysqli->query("
SELECT COUNT(*) as total FROM project_inventory
WHERE contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetch_assoc()['total'];

$expiredContracts = $mysqli->query("
SELECT COUNT(*) as total FROM project_inventory
WHERE contract_end < CURDATE()
")->fetch_assoc()['total'];


// =====================
// ASSET STATS
// =====================

// TOTAL ASSETS (everything in asset_inventory)
$totalDevices = $mysqli->query("
    SELECT COUNT(*) as total
    FROM asset_inventory
")->fetch_assoc()['total'];


// TOTAL SERVERS (from server_inventory)
$servers = $mysqli->query("
    SELECT COUNT(*) as total
    FROM server_inventory
")->fetch_assoc()['total'];


// TOTAL STORAGE (from asset_inventory only)
$storage = $mysqli->query("
    SELECT COUNT(*) as total
    FROM asset_inventory
    WHERE description LIKE '%storage%'
")->fetch_assoc()['total'];

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dashboard</title>

<link rel="icon" href="../image/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<link rel="stylesheet" href="style.css">

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<!-- BANNER -->
<div class="banner mb-4">
    <h2><strong>Crossroad Solutions Inventory Management</strong></h2>
    <p>
        Manage contracts, assets, and tenders in one centralized system.
    </p>
</div>

<!-- QUICK ACCESS -->
<div class="row g-3 mb-4">

    <div class="col-lg-4 col-md-6 col-12">
        <div class="card dashboard-card p-4 shadow-sm h-100">
            <h5><i class="fa fa-file-contract text-warning"></i> Contracts</h5>
            <p>Manage and monitor project contracts.</p>
            <a href="contracts.php" class="btn btn-warning">Open</a>
        </div>
    </div>

    <div class="col-lg-4 col-md-6 col-12">
        <div class="card dashboard-card p-4 shadow-sm h-100">
            <h5><i class="fa fa-box text-warning"></i> Asset Inventory</h5>
            <p>Track all company assets and equipment.</p>
            <a href="asset_inventory.php" class="btn btn-warning">Open</a>
        </div>
    </div>

    <div class="col-lg-4 col-md-6 col-12">
        <div class="card dashboard-card p-4 shadow-sm h-100">
            <h5><i class="fa fa-chart-line text-warning"></i> Project Tracker</h5>
            <p>Monitor tender activities and progress.</p>
            <a href="project_tracker.php" class="btn btn-warning">Open</a>
        </div>
    </div>

</div>

<!-- CONTRACT OVERVIEW -->
<h4>Contracts Overview</h4>

<div class="row text-center mb-4">

    <div class="col-lg-3 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Total Contracts</h6>
            <h2><?= $totalContracts ?></h2>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Active</h6>
            <h2 class="text-success"><?= $activeContracts ?></h2>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Upcoming Expiry</h6>
            <h2 class="text-info"><?= $expiringContracts ?></h2>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Expired</h6>
            <h2 class="text-danger"><?= $expiredContracts ?></h2>
        </div>
    </div>

</div>

<div class="section-divider"></div>

<!-- ASSET OVERVIEW -->
<h4>Asset Inventory Overview</h4>

<div class="row text-center mb-4">

    <div class="col-lg-3 col-md-6 col-12 mb-3">
       <div class="stat-card <?php echo $isExportAllowed ? 'clickable' : 'disabled-card'; ?>"
            <?php if($isExportAllowed): ?>
               onclick="openExportModal('asset')"
            <?php endif; ?>>
            <h6>Total Assets</h6>
            <h2><?= $totalDevices ?></h2>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-12 mb-3">
        <div class="stat-card <?php echo $isExportAllowed ? 'clickable' : 'disabled-card'; ?>"
             <?php if($isExportAllowed): ?>
                onclick="openExportModal('server')"
             <?php endif; ?>>
            <h6>Servers</h6>
            <h2><?= $servers ?></h2>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Storage Devices</h6>
            <h2><?= $storage ?></h2>
        </div>
    </div>



</div>

</div>
<div class="modal fade" id="exportModal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">Export Data</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body text-center">

        <p id="exportText" class="mb-3"></p>

        <button class="btn btn-success w-100 mb-2" onclick="exportData('excel')">
            <i class="fa fa-file-excel"></i> Excel
        </button>

        <button class="btn btn-danger w-100 mb-2" onclick="exportData('pdf')">
            <i class="fa fa-file-pdf"></i> PDF
        </button>

        <button class="btn btn-primary w-100" onclick="exportData('print')">
            <i class="fa fa-print"></i> Print
        </button>

      </div>

    </div>
  </div>
</div>
<?php include "layout/footer.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar(){
    const sidebar = document.getElementById("sidebar");
    const main = document.getElementById("main");
    const btn = document.querySelector(".menu-btn");

    sidebar.classList.toggle("collapsed");
    main.classList.toggle("expanded");
    btn.classList.toggle("active");
}
</script>

<script>
let exportType = "";

let isExportAllowed = <?= json_encode($isExportAllowed) ?>;

function openExportModal(type){

    if(!isExportAllowed){
        return; // 🚫 do nothing for unauthorized roles
    }

    exportType = type;

    let text = (type === "asset")
        ? "Export TOTAL ASSETS (Inventory + Stock Out)"
        : "Export SERVERS (Inventory + Stock Out)";

    document.getElementById("exportText").innerText = text;

    new bootstrap.Modal(document.getElementById('exportModal')).show();
}
function exportData(format){

    let url = "";

    if(exportType === "asset"){
        url = "../backend/export_assets.php?format=" + format;
    }
    else if(exportType === "server"){
        url = "../backend/export_servers.php?format=" + format;
    }

    // 🔥 OPEN IN NEW TAB (PDF & PRINT)
    if(format === "pdf" || format === "print"){
        window.open(url, "_blank");
    }
    else{
        // Excel stays download (same tab)
        window.location.href = url;
    }

}
</script>
<style>
.clickable{
    cursor: pointer;
}

.disabled-card{
    cursor: not-allowed;
}
</style>
</body>
</html>