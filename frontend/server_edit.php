<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];

if(!isset($_GET['id'])){
    die("Invalid request");
}

$id = intval($_GET['id']);

$result = $mysqli->query("SELECT * FROM server_inventory WHERE no='$id'");
$row = $result->fetch_assoc();

if(!$row){
    die("No data found");
}

$canEdit = in_array($role, ["Administrator","System Admin","User (Technical)"]);

if(isset($_POST['update']) && $canEdit){

    $server   = $_POST['server_name'];
    $serial   = $_POST['serial_number'];
    $brand    = $_POST['brand'];
    $machine  = $_POST['machine_type'];
    $location = $_POST['location'];
    $status   = $_POST['status'];
    $remark   = $_POST['remark'];
    $date     = $_POST['date_testing'];
    $tester   = $_POST['tester'];

    $mysqli->query("
    UPDATE server_inventory SET
    server_name='$server',
    serial_number='$serial',
    brand='$brand',
    machine_type='$machine',
    location='$location',
    status='$status',
    remark='$remark',
    date_testing='$date',
    tester='$tester'
    WHERE no='$id'
    ");

    header("Location: server_inventory.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Server</title>

<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">Edit Server</h2>

<form method="POST">

<div class="row">

<div class="col-md-6 mb-3">
<label>Server Name</label>
<input type="text" name="server_name" class="form-control" value="<?= $row['server_name'] ?>">
</div>

<div class="col-md-6 mb-3">
<label>Serial Number</label>
<input type="text" name="serial_number" class="form-control" value="<?= $row['serial_number'] ?>">
</div>

<div class="col-md-6 mb-3">
<label>Brand</label>
<input type="text" name="brand" class="form-control" value="<?= $row['brand'] ?>">
</div>

<div class="col-md-6 mb-3">
<label>Machine Type</label>
<input type="text" name="machine_type" class="form-control" value="<?= $row['machine_type'] ?>">
</div>

<div class="col-md-6 mb-3">
<label>Location</label>
<input type="text" name="location" class="form-control" value="<?= $row['location'] ?>">
</div>

<div class="col-md-6 mb-3">
<label>Status</label>
<input type="text" name="status" class="form-control" value="<?= $row['status'] ?>">
</div>

<div class="col-md-6 mb-3">
<label>Tester</label>
<input type="text" name="tester" class="form-control" value="<?= $row['tester'] ?>">
</div>

<div class="col-md-6 mb-3">
<label>Date Testing</label>
<input type="date" name="date_testing" class="form-control" value="<?= $row['date_testing'] ?>">
</div>

<div class="col-md-12 mb-3">
<label>Remark</label>
<textarea name="remark" class="form-control"><?= $row['remark'] ?></textarea>
</div>

</div>

<button class="btn btn-warning" name="update">Update Server</button>
<a href="server_inventory.php" class="btn btn-secondary">Cancel</a>

</form>

</div>

</body>
</html>