<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

$isView = isset($_GET['view']);
$canEdit = !$isView && hasPermission($mysqli, "inventory_edit");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if(!$id){
    die("Invalid request");
}

$stmt = $mysqli->prepare("
    SELECT *
    FROM asset_inventory
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

if(isset($_POST['update']) && $canEdit){

    $new_part     = trim($_POST['part_number']);
    $new_serial   = trim($_POST['serial_number']);
    $brand        = trim($_POST['brand']);
    $descriptionInput  = trim($_POST['description']);
    $location     = trim($_POST['location']);
    $date         = trim($_POST['date_received']);

    $updateStmt = $mysqli->prepare("
        UPDATE asset_inventory SET
            part_number = ?,
            serial_number = ?,
            brand = ?,
            description = ?,
            location = ?,
            date_received = ?
        WHERE no = ?
    ");

    if(!$updateStmt){
        die("Update Prepare Error: " . $mysqli->error);
    }

    $updateStmt->bind_param(
        "ssssssi",
        $new_part,
        $new_serial,
        $brand,
        $descriptionInput,
        $location,
        $date,
        $id
    );

    if(!$updateStmt->execute()){
        die("Update Error: " . $mysqli->error);
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $logDescription = "User [$username] edited asset.

OLD DATA:
- Part Number: {$row['part_number']}
- Serial Number: {$row['serial_number']}
- Brand: {$row['brand']}
- Description: {$row['description']}
- Location: {$row['location']}
- Date Received: {$row['date_received']}

NEW DATA:
- Part Number: $new_part
- Serial Number: $new_serial
- Brand: $brand
- Description: $descriptionInput
- Location: $location
- Date Received: $date

IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "EDIT ASSET",
        $logDescription
    );

    header("Location: ../frontend/asset_inventory.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?= $canEdit ? 'Edit Asset' : 'View Asset' ?></title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">
    <h2 class="mb-4"><?= $canEdit ? 'Edit Asset' : 'View Asset' ?></h2>

    <form method="POST">

        <div class="row">

            <div class="col-md-6 mb-3">
                <label>Part Number *</label>
                <input type="text" name="part_number" class="form-control"
                       value="<?= htmlspecialchars($row['part_number'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?> required>
            </div>

            <div class="col-md-6 mb-3">
                <label>Serial Number *</label>
                <input type="text" name="serial_number" class="form-control"
                       value="<?= htmlspecialchars($row['serial_number'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?> required>
            </div>

            <div class="col-md-6 mb-3">
                <label>Brand</label>
                <input type="text" name="brand" class="form-control"
                       value="<?= htmlspecialchars($row['brand'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
            </div>

            <div class="col-md-6 mb-3">
                <label>Location</label>
                <input type="text" name="location" class="form-control"
                       value="<?= htmlspecialchars($row['location'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
            </div>

            <div class="col-12 mb-3">
                <label>Description</label>
                <input type="text" name="description" class="form-control"
                       value="<?= htmlspecialchars($row['description'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
            </div>

            <div class="col-12 mb-3">
                <label>Date Received</label>
                <input type="date" name="date_received" class="form-control"
                       value="<?= htmlspecialchars($row['date_received'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
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