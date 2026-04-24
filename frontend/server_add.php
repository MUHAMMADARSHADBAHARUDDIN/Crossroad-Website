<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];

if(!in_array($role, ["Administrator","System Admin","User (Technical)"])){
    header("Location: server_inventory.php");
    exit();
}

$error = "";

if(isset($_POST['add'])){

    $server   = trim($_POST['server_name']);
    $serial   = trim($_POST['serial_number']);
    $brand    = trim($_POST['brand']);
    $machine  = trim($_POST['machine_type']);
    $location = trim($_POST['location']);
    $status   = trim($_POST['status']);
    $remark   = trim($_POST['remark']);
    $date     = trim($_POST['date_testing']);
    $tester   = trim($_POST['tester']);

    if($server == "" || $serial == ""){
        $error = "Server Name and Serial Number are required!";
    } else {

        $check = $mysqli->query("SELECT * FROM server_inventory WHERE serial_number='$serial'");
        if($check->num_rows > 0){
            $error = "Serial Number already exists!";
        } else {

            $mysqli->query("
            INSERT INTO server_inventory
            (server_name,serial_number,brand,machine_type,location,status,remark,date_testing,tester,created_by)
            VALUES
            ('$server','$serial','$brand','$machine','$location','$status','$remark','$date','$tester','$username')
            ");

            $ip = $_SERVER['REMOTE_ADDR'];
            $time = date("Y-m-d H:i:s");

            $description = "User [$username] added new server.
            Server Name: $server
            Serial Number: $serial
            Brand: $brand
            Machine Type: $machine
            Location: $location
            Status: $status
            Tester: $tester
            Date Testing: $date
            Remark: $remark
            IP Address: $ip
            Time: $time";

            logActivity(
                $mysqli,
                $username,
                $role,
                "ADD SERVER",
                $description
            );

            header("Location: server_inventory.php");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Server</title>

<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">Add Server</h2>

<?php if($error != ""): ?>
<div class="alert alert-danger"><?= $error; ?></div>
<?php endif; ?>

<form method="POST">

<div class="row">

<div class="col-md-6 mb-3">
<label>Server Name *</label>
<input type="text" name="server_name" class="form-control" required>
</div>

<div class="col-md-6 mb-3">
<label>Serial Number *</label>
<input type="text" name="serial_number" class="form-control" required>
</div>

<div class="col-md-6 mb-3">
<label>Brand</label>
<input type="text" name="brand" class="form-control">
</div>

<div class="col-md-6 mb-3">
<label>Machine Type</label>
<input type="text" name="machine_type" class="form-control">
</div>

<div class="col-md-6 mb-3">
<label>Location</label>
<input type="text" name="location" class="form-control">
</div>

<div class="col-md-6 mb-3">
<label>Status</label>
<input type="text" name="status" class="form-control">
</div>

<div class="col-md-6 mb-3">
<label>Tester</label>
<input type="text" name="tester" class="form-control">
</div>

<div class="col-md-6 mb-3">
<label>Date Testing</label>
<input type="date" name="date_testing" class="form-control">
</div>

<div class="col-md-12 mb-3">
<label>Remark</label>
<textarea name="remark" class="form-control"></textarea>
</div>

</div>

<button class="btn btn-warning" name="add">Add Server</button>
<a href="server_inventory.php" class="btn btn-secondary">Cancel</a>

</form>

</div>

</body>
</html>