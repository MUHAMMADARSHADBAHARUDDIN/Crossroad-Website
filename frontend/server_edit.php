<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

if(!isset($_GET['id'])){
    die("Invalid request");
}

$id = intval($_GET['id']);

$stmt = $mysqli->prepare("
    SELECT *
    FROM server_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$stmt){
    die("Prepare failed: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_assoc();

if(!$row){
    die("No data found");
}

$canEdit = hasPermission($mysqli, "inventory_edit");

if(isset($_POST['update']) && $canEdit){

    $server   = trim($_POST['server_name']);
    $serial   = trim($_POST['serial_number']);
    $brand    = trim($_POST['brand']);
    $machine  = trim($_POST['machine_type']);
    $location = trim($_POST['location']);
    $status   = trim($_POST['status']);
    $remark   = trim($_POST['remark']);
    $date     = trim($_POST['date_testing']);
    $tester   = trim($_POST['tester']);

    $updateStmt = $mysqli->prepare("
        UPDATE server_inventory SET
            server_name = ?,
            serial_number = ?,
            brand = ?,
            machine_type = ?,
            location = ?,
            status = ?,
            remark = ?,
            date_testing = ?,
            tester = ?
        WHERE no = ?
    ");

    if(!$updateStmt){
        die("Prepare failed: " . $mysqli->error);
    }

    $updateStmt->bind_param(
        "sssssssssi",
        $server,
        $serial,
        $brand,
        $machine,
        $location,
        $status,
        $remark,
        $date,
        $tester,
        $id
    );

    if(!$updateStmt->execute()){
        die("Update failed: " . $mysqli->error);
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] updated server.
Server ID: $id

OLD DATA:
- Server Name: {$row['server_name']}
- Serial Number: {$row['serial_number']}
- Brand: {$row['brand']}
- Machine Type: {$row['machine_type']}
- Location: {$row['location']}
- Status: {$row['status']}
- Tester: {$row['tester']}
- Date Testing: {$row['date_testing']}
- Remark: {$row['remark']}

NEW DATA:
- Server Name: $server
- Serial Number: $serial
- Brand: $brand
- Machine Type: $machine
- Location: $location
- Status: $status
- Tester: $tester
- Date Testing: $date
- Remark: $remark

IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "UPDATE SERVER",
        $description
    );

    header("Location: server_inventory.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<title><?= $canEdit ? 'Edit Server' : 'View Server' ?></title>

<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4"><?= $canEdit ? 'Edit Server' : 'View Server' ?></h2>

<form method="POST">

<div class="row">

<div class="col-md-6 mb-3">
<label>Server Name</label>
<input type="text" name="server_name" class="form-control" value="<?= htmlspecialchars($row['server_name'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Serial Number</label>
<input type="text" name="serial_number" class="form-control" value="<?= htmlspecialchars($row['serial_number'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Brand</label>
<input type="text" name="brand" class="form-control" value="<?= htmlspecialchars($row['brand'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Machine Type</label>
<input type="text" name="machine_type" class="form-control" value="<?= htmlspecialchars($row['machine_type'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Location</label>
<input type="text" name="location" class="form-control" value="<?= htmlspecialchars($row['location'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Status</label>
<input type="text" name="status" class="form-control" value="<?= htmlspecialchars($row['status'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Tester</label>
<input type="text" name="tester" class="form-control" value="<?= htmlspecialchars($row['tester'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Date Testing</label>
<input type="date" name="date_testing" class="form-control" value="<?= htmlspecialchars($row['date_testing'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-12 mb-3">
<label>Remark</label>
<textarea name="remark" class="form-control" <?= $canEdit ? '' : 'readonly' ?>><?= htmlspecialchars($row['remark'] ?? '') ?></textarea>
</div>

</div>

<?php if($canEdit): ?>
<button class="btn btn-warning" name="update">Update Server</button>
<?php else: ?>
<span class="badge bg-secondary">View Only</span>
<?php endif; ?>

<a href="server_inventory.php" class="btn btn-secondary">Cancel</a>

</form>

</div>

</body>
</html>