<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];

// ✅ DEFINE THIS (YOU MISSED THIS)
$canEdit = in_array($role, ["Administrator", "System Admin", "User (Technical)"]);

// ✅ GET ID SAFELY
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ✅ FETCH DATA
$result = $mysqli->query("
    SELECT * FROM asset_inventory WHERE no='$id' LIMIT 1
");

if(!$result) die("Query Error: " . $mysqli->error);

$row = $result->fetch_assoc();
if(!$row) die("No data found");

// ✅ HANDLE UPDATE
if(isset($_POST['update']) && $canEdit){

    $new_part     = $mysqli->real_escape_string($_POST['part_number']);
    $new_serial   = $mysqli->real_escape_string($_POST['serial_number']);
    $brand        = $mysqli->real_escape_string($_POST['brand']);
    $description  = $mysqli->real_escape_string($_POST['description']);
    $type         = $mysqli->real_escape_string($_POST['type']);
    $location     = $mysqli->real_escape_string($_POST['location']);
    $date         = $mysqli->real_escape_string($_POST['date_received']);

    // ✅ UPDATE USING UNIQUE ID
    $update = $mysqli->query("
        UPDATE asset_inventory SET
            part_number='$new_part',
            serial_number='$new_serial',
            brand='$brand',
            description='$description',
            type='$type',
            location='$location',
            date_received='$date'
        WHERE no='$id'
    ");

    if(!$update) die("Update Error: " . $mysqli->error);

    // ✅ ACTIVITY LOG (INSIDE UPDATE BLOCK)
    $mysqli->query("
        INSERT INTO activity_logs
        (username, role, action_type, description)
        VALUES
        (
            '$username',
            '$role',
            'Edit Asset',
            'Edited asset: Old Part ".$row['part_number'].", Old Serial ".$row['serial_number'].
            ". New Part $new_part, New Serial $new_serial, Brand $brand, Type $type, Location $location, Date $date'
        )
    ");

    header("Location: ../frontend/asset_inventory.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Asset</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">
    <h2 class="mb-4">Edit Asset</h2>

    <form method="POST">

        <div class="row">

            <div class="col-md-6 mb-3">
                <label>Part Number *</label>
                <input type="text" name="part_number" class="form-control"
                       value="<?= $row['part_number'] ?>" <?= $canEdit ? '' : 'readonly' ?> required>
            </div>

            <div class="col-md-6 mb-3">
                <label>Serial Number *</label>
                <input type="text" name="serial_number" class="form-control"
                       value="<?= $row['serial_number'] ?>" <?= $canEdit ? '' : 'readonly' ?> required>
            </div>

            <div class="col-md-6 mb-3">
                <label>Brand</label>
                <input type="text" name="brand" class="form-control"
                       value="<?= $row['brand'] ?>" <?= $canEdit ? '' : 'readonly' ?>>
            </div>

            <div class="col-md-6 mb-3">
                <label>Type</label>
                <input type="text" name="type" class="form-control"
                       value="<?= $row['type'] ?>" <?= $canEdit ? '' : 'readonly' ?>>
            </div>

            <div class="col-md-6 mb-3">
                <label>Location</label>
                <input type="text" name="location" class="form-control"
                       value="<?= $row['location'] ?>" <?= $canEdit ? '' : 'readonly' ?>>
            </div>

            <div class="col-12 mb-3">
                <label>Description</label>
                <input type="text" name="description" class="form-control"
                       value="<?= $row['description'] ?>" <?= $canEdit ? '' : 'readonly' ?>>
            </div>

            <div class="col-12 mb-3">
                <label>Date Received</label>
                <input type="date" name="date_received" class="form-control"
                       value="<?= $row['date_received'] ?>">
            </div>

        </div>

        <?php if($canEdit): ?>
            <button class="btn btn-warning" name="update">Update Asset</button>
        <?php else: ?>
            <span class="badge bg-secondary">View Only</span>
        <?php endif; ?>

        <a href="../frontend/asset_inventory.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<?php include "layout/footer.php"; ?>
</body>
</html>