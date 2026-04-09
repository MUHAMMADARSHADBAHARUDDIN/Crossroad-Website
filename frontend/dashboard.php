<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];


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
$totalDevices = $mysqli->query("SELECT COUNT(*) as total FROM asset_inventory")->fetch_assoc()['total'];

$servers = $mysqli->query("
SELECT COUNT(*) as total FROM asset_inventory
WHERE type LIKE '%server%'
")->fetch_assoc()['total'];

$storage = $mysqli->query("
SELECT COUNT(*) as total FROM asset_inventory
WHERE type LIKE '%storage%'
")->fetch_assoc()['total'];

$maintenance = $mysqli->query("
SELECT COUNT(*) as total FROM asset_inventory
WHERE remark LIKE '%maintenance%'
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
            <h5><i class="fa fa-chart-line text-warning"></i> Tender Tracker</h5>
            <p>Monitor tender activities and progress.</p>
            <a href="#" class="btn btn-warning">Open</a>
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
        <div class="stat-card">
            <h6>Total Devices</h6>
            <h2><?= $totalDevices ?></h2>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-12 mb-3">
        <div class="stat-card">
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

    <div class="col-lg-3 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Maintenance</h6>
            <h2 class="text-warning"><?= $maintenance ?></h2>
        </div>
    </div>

</div>

<div class="section-divider"></div>

<!-- TENDER (STATIC FOR NOW) -->
<h4>Tender Tracker Overview</h4>

<div class="row text-center mb-4">

    <div class="col-lg-4 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Active Tenders</h6>
            <h2>0</h2>
        </div>
    </div>

    <div class="col-lg-4 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Submitted</h6>
            <h2>0</h2>
        </div>
    </div>

    <div class="col-lg-4 col-md-6 col-12 mb-3">
        <div class="stat-card">
            <h6>Won</h6>
            <h2 class="text-success">0</h2>
        </div>
    </div>

</div>

</div>

<?php include "layout/footer.php"; ?>

<script>
function toggleSidebar(){
    const sidebar = document.getElementById("sidebar");
    const main = document.getElementById("main");
    sidebar.classList.toggle("collapsed");
    main.classList.toggle("expanded");
}
</script>

</body>
</html>