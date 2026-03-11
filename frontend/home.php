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

    <style>

        body{
            background:#f4f6f9;
            font-family:'Inter',sans-serif;
            overflow-x:hidden;
        }

        /* SIDEBAR */

        .sidebar{
            width:230px;
            height:100vh;
            background:#1a1a1a;
            position:fixed;
            top:0;
            left:0;
            color:white;
            padding-top:20px;
            transition:width 0.3s ease;
            overflow:hidden;
        }

        .sidebar.collapsed{
            width:70px;
        }

        .sidebar-title{
            color:white;
            font-weight:600;
            padding-left:20px;
            margin-bottom:20px;
            transition:opacity 0.3s;
        }

        .sidebar.collapsed .sidebar-title{
            opacity:0;
        }

        .sidebar a{
            display:flex;
            align-items:center;
            gap:12px;
            color:white;
            text-decoration:none;
            padding:12px 20px;
            font-size:15px;
            transition:background 0.2s;
        }

        .sidebar a:hover{
            background:#ffc107;
            color:black;
        }

        .sidebar i{
            width:20px;
            text-align:center;
        }

        .sidebar.collapsed a span{
            display:none;
        }

        /* ADMIN BUTTON */

        .manage-users{
            background:#1a1a1a;
            color:white;
            font-weight:600;
            margin-top:0px;
            border-radius:20px;
        }

        .manage-users:hover{
            background:#ffb300;
        }

        /* HEADER */

        .topbar{
            position:fixed;
            top:0;
            left:230px;
            right:0;
            height:70px;
            background:#1a1a1a;
            color:white;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 20px;
            border-bottom:3px solid #ffc107;
            transition:left 0.3s ease;
            z-index:99;
        }

        .topbar.expanded{
            left:70px;
        }

        .header-left{
            display:flex;
            align-items:center;
            gap:15px;
        }

        .header-logo{
            height:35px;
        }

        /* MAIN */

        .main{
            margin-left:230px;
            padding:100px 30px 30px 30px;
            transition:margin-left 0.3s ease;
        }

        .main.expanded{
            margin-left:70px;
        }

        /* BANNER */

        .banner{
            background:linear-gradient(135deg,#1a1a1a,#333);
            color:white;
            padding:40px;
            border-radius:15px;
            margin-bottom:25px;
        }

        .banner h2{
            font-weight:700;
        }

        /* CARDS */

        .card{
            border-radius:12px;
            box-shadow:0 4px 10px rgba(0,0,0,0.1);
            transition:all 0.25s ease;
            cursor:pointer;
            height:180px;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
        }

        .card:hover{
            transform:translateY(-6px) scale(1.02);
            box-shadow:0 12px 25px rgba(0,0,0,0.15);
        }

        /* STAT CARDS */

        .stat-card{
            border-radius:12px;
            box-shadow:0 4px 10px rgba(0,0,0,0.1);
            padding:20px;
            background:white;
            text-align:center;
            transition:all 0.25s ease;
        }

        .stat-card:hover{
            transform:translateY(-6px);
            box-shadow:0 12px 25px rgba(0,0,0,0.15);
        }

        /* DIVIDER */

        .section-divider{
            border-top:2px solid #ddd;
            margin:40px 0;
        }

    </style>

</head>

<body>

<!-- SIDEBAR -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-title">Dashboard</div>

    <a href="#">
        <i class="fa fa-home"></i>
        <span>Homepage</span>
    </a>

    <a href="#">
        <i class="fa fa-file-contract"></i>
        <span>Contracts</span>
    </a>

    <a href="#">
        <i class="fa fa-box"></i>
        <span>Asset Inventory</span>
    </a>

    <a href="#">
        <i class="fa fa-chart-line"></i>
        <span>Tender Tracker</span>
    </a>

    <?php if($role == "Administrator"): ?>

       <a href="manage_users.php" class="manage-users">
            <i class="fa fa-user-cog"></i>
            <span>Manage Users</span>
        </a>

    <?php endif; ?>

</div>

<!-- HEADER -->

<div class="topbar" id="topbar">

    <div class="header-left">

        <button class="btn btn-warning" onclick="toggleSidebar()">
            <i class="fa fa-bars"></i>
        </button>

        <img src="../image/logo.png" class="header-logo">

        <span>Crossroad Solutions Inventory Management</span>

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

<!-- MAIN -->

<div class="main" id="main">

    <!-- BANNER -->

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

            <div class="card p-4 shadow-sm">

                <h5>
                    <i class="fa fa-file-contract text-warning"></i>
                    Contracts
                </h5>

                <p>Browse, manage, and monitor company project contracts.</p>

                <button class="btn btn-warning">Open</button>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card p-4 shadow-sm">

                <h5>
                    <i class="fa fa-box text-warning"></i>
                    Asset Inventory
                </h5>

                <p>Track servers, hardware, and equipment across locations.</p>

                <button class="btn btn-warning">Open</button>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card p-4 shadow-sm">

                <h5>
                    <i class="fa fa-chart-line text-warning"></i>
                    Tender Tracker
                </h5>

                <p>Monitor tender opportunities and submission status.</p>

                <button class="btn btn-warning">Open</button>

            </div>

        </div>

    </div>

    <!-- CONTRACTS OVERVIEW -->

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

    <div class="card shadow-sm">

        <div class="card-header bg-dark text-white">
            Recent Contracts
        </div>

        <div class="card-body">

            <table class="table">

                <thead>

                <tr>
                    <th>Project</th>
                    <th>Status</th>
                    <th>Service</th>
                    <th>Year</th>
                    <th>Start</th>
                    <th>End</th>
                </tr>

                </thead>

                <tbody>

                <tr>
                    <td colspan="6" class="text-center">No contracts yet</td>
                </tr>

                </tbody>

            </table>

        </div>

    </div>

    <div class="section-divider"></div>

    <!-- ASSET INVENTORY -->

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

<script>

    function toggleSidebar(){

        const sidebar = document.getElementById("sidebar")
        const main = document.getElementById("main")
        const topbar = document.getElementById("topbar")

        sidebar.classList.toggle("collapsed")
        main.classList.toggle("expanded")
        topbar.classList.toggle("expanded")

    }

</script>

</body>
</html>