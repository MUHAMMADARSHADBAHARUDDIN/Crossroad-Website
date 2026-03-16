<?php
if(session_status() === PHP_SESSION_NONE){
    session_start();
}

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

    <link rel="stylesheet" href="../frontend/style.css">

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

        <span class="company-title">Crossroad Solutions Sdn Bhd</span>

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
