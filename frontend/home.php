<?php
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Crossroad Solutions Inventory</title>

    <link rel="icon" href="../image/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="style.css">



</head>

<body>

<!-- HEADER -->

<div class="topbar">

    <div class="header-left">

        <div class="menu-btn" id="menuBtn" onclick="toggleSidebar()">
            <span></span>
            <span></span>
            <span></span>
        </div>

        <img src="../image/logo.png" class="header-logo">

        <span class="company-title">Crossroad Solutions</span>

    </div>

    <div class="d-flex align-items-center gap-3">

<span class="badge bg-warning text-dark">
<?php echo $role; ?>
</span>

        <span>
<i class="fa fa-user"></i> <?php echo $username; ?>
</span>

        <a href="logout.php" class="btn btn-outline-light btn-sm">
            Logout
        </a>

    </div>

</div>

<!-- SIDEBAR -->

<div class="sidebar" id="sidebar">

    <a href="home.php">
        <i class="fa fa-home"></i>
        <span>Homepage</span>
    </a>

    <a href="contracts.php">
        <i class="fa fa-file-contract"></i>
        <span>Contracts</span>
    </a>

    <a href="asset_inventory.php">
        <i class="fa fa-box"></i>
        <span>Asset Inventory</span>
    </a>

    <a href="#">
        <i class="fa fa-chart-line"></i>
        <span>Tender Tracker</span>
    </a>

    <?php if($role === "Administrator"): ?>

        <a href="../frontend/manage_users.php" class="manage-users">
            <i class="fa fa-user-cog"></i>
            <span>Manage Users</span>
        </a>

    <?php endif; ?>
    <?php if($role === "Administrator"): ?>

    <a href="tracking.php">
    <i class="fa fa-history"></i>
    <span>Activity Tracking</span>
    </a>

    <?php endif; ?>
</div>

<!-- MAIN -->



<div class="main" id="main">

    <div class="banner">

        <h2><strong>Crossroad Solutions Inventory Management</strong></h2>

        <p>
            A centralized internal platform for Crossroad Solutions employees to manage project contracts,
            track company assets, and monitor tender activities.
        </p>

    </div>


    <!-- QUICK ACCESS -->

    <div class="row mb-4">

        <div class="col-md-4">

            <div class="card dashboard-card p-4 shadow-sm">

                <h5>
                    <i class="fa fa-file-contract text-warning"></i>
                    Contracts
                </h5>

                <p>Browse, manage, and monitor company project contracts.</p>

                <a href="contracts.php" class="btn btn-warning">Open</a>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card dashboard-card p-4 shadow-sm">

                <h5>
                    <i class="fa fa-box text-warning"></i>
                    Asset Inventory
                </h5>

                <p>Track servers, hardware, and equipment across locations.</p>

                <a href="asset_inventory.php" class="btn btn-warning">Open</a>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card dashboard-card p-4 shadow-sm">

                <h5>
                    <i class="fa fa-chart-line text-warning"></i>
                    Tender Tracker
                </h5>

                <p>Monitor tender opportunities and submission status.</p>

                <a href="#" class="btn btn-warning">Open</a>

            </div>

        </div>

    </div>

    <!-- CONTRACTS -->

    <h4>Contracts Overview</h4>

    <div class="row text-center mb-4">

        <div class="col-md-3">
            <div class="stat-card">
                <h6>Total Contracts</h6>
                <h2>0</h2>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <h6>Active</h6>
                <h2 class="text-success">0</h2>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <h6>Upcoming Expiry</h6>
                <h2 class="text-info">0</h2>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <h6>Expired</h6>
                <h2 class="text-danger">0</h2>
            </div>
        </div>

    </div>

    <div class="section-divider"></div>

    <!-- ASSETS -->

    <h4>Asset Inventory Overview</h4>

    <div class="row text-center mb-4">

        <div class="col-md-3">
            <div class="stat-card">
                <h6>Total Devices</h6>
                <h2>0</h2>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <h6>Servers</h6>
                <h2>0</h2>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <h6>Storage Devices</h6>
                <h2>0</h2>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <h6>Devices in Maintenance</h6>
                <h2 class="text-warning">0</h2>
            </div>
        </div>

    </div>

    <div class="section-divider"></div>

    <!-- TENDER -->

    <h4>Tender Tracker Overview</h4>

    <div class="row text-center mb-4">

        <div class="col-md-4">
            <div class="stat-card">
                <h6>Active Tenders</h6>
                <h2>0</h2>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <h6>Submitted Tenders</h6>
                <h2>0</h2>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <h6>Won Tenders</h6>
                <h2 class="text-success">0</h2>
            </div>
        </div>

    </div>

</div>

<?php include "layout/footer.php"; ?>

<script>

    function toggleSidebar(){

        const sidebar = document.getElementById("sidebar")
        const main = document.getElementById("main")
        const btn = document.getElementById("menuBtn")

        sidebar.classList.toggle("collapsed")
        main.classList.toggle("expanded")

        btn.classList.toggle("active")

    }

</script>

</body>
</html>