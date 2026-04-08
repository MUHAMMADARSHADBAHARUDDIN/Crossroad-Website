<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];

// Secure GET parameter
$part = $mysqli->real_escape_string($_GET['id']);

// Fetch the asset
$result = $mysqli->query("SELECT * FROM asset_inventory WHERE part_number='$part' LIMIT 1");
if(!$result) die("Query Error: " . $mysqli->error);

$row = $result->fetch_assoc();
if(!$row) die("No data found");

// ROLE CONTROL
$canEdit = in_array($role, ["Administrator", "System Admin"]) || ($role === "User (Technical)" && $row['created_by'] === $username);

// Handle update only if user can edit
if(isset($_POST['update']) && $canEdit){
    $new_part     = $mysqli->real_escape_string($_POST['part_number']);
    $new_serial   = $mysqli->real_escape_string($_POST['serial_number']);
    $brand        = $mysqli->real_escape_string($_POST['brand']);
    $description  = $mysqli->real_escape_string($_POST['description']);
    $type         = $mysqli->real_escape_string($_POST['type']);
    $location     = $mysqli->real_escape_string($_POST['location']);
    $remark       = $mysqli->real_escape_string($_POST['remark']);

    $update = $mysqli->query("
        UPDATE asset_inventory SET
            part_number='$new_part',
            serial_number='$new_serial',
            brand='$brand',
            description='$description',
            type='$type',
            location='$location',
            remark='$remark'
        WHERE part_number='$part'
          AND serial_number='".$row['serial_number']."'
    ");

    if(!$update) die("Update Error: " . $mysqli->error);
// INSERT INTO ACTIVITY LOG
$mysqli->query("
INSERT INTO activity_logs
(username, role, action_type, description)
VALUES
(
    '$username',
    '$role',
    'Edit Asset',
    'Edited asset: Old Part Number ".$row['part_number'].", Old Serial Number ".$row['serial_number'].". New Part Number $new_part, New Serial Number $new_serial, Brand $brand, Type $type, Location $location, Remark $remark'
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
                <label>Remark</label>
                <textarea name="remark" class="form-control" <?= $canEdit ? '' : 'readonly' ?>><?= $row['remark'] ?></textarea>
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