<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";
require_once "../includes/office_family_helper.php";
require_once "../includes/date_helpers.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

if(!hasPermission($mysqli, "office_inventory_add")){
    header("Location: office_inventory.php");
    exit();
}

ensureInventoryReportSchema($mysqli);

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$error = "";
$ownerOptions = officeInventoryFetchFamilyOptions($mysqli);

if(isset($_POST['owner']) && trim((string)$_POST['owner']) !== "" && !in_array(trim((string)$_POST['owner']), $ownerOptions, true)){
    $ownerOptions[] = trim((string)$_POST['owner']);
}

function officeAddEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function officeAddOld($fieldName){
    return officeAddEscape($_POST[$fieldName] ?? '');
}

function officeAddSelected($fieldName, $value){
    return (($_POST[$fieldName] ?? '') === $value) ? 'selected' : '';
}

function officeLicenseTypeText($office365License, $antivirusLicense){
    $types = [];

    if($office365License !== ""){
        $types[] = $office365License;
    }

    if($antivirusLicense !== ""){
        $types[] = $antivirusLicense;
    }

    return implode(", ", $types);
}

if(isset($_POST['add'])){
    $deliveryDate = appNormalizeDateInput($_POST['delivery_date'] ?? "");
    $owner = trim($_POST['owner'] ?? "");
    $serialNumber = trim($_POST['serial_number'] ?? "");
    $brand = trim($_POST['brand'] ?? "");
    $model = trim($_POST['model'] ?? "");
    $office365License = trim($_POST['office365_license'] ?? "");
    $antivirusLicense = trim($_POST['antivirus_license'] ?? "");
    $licenseType = officeLicenseTypeText($office365License, $antivirusLicense);
    $licenseOwnership = "";
    $licenseFamily = "";
    $licenseFamilyDetails = null;
    $licenseExpiredDate = null;

    if($owner === "" || $serialNumber === ""){
        $error = "Owner and Serial Number are required.";
    }
    else{
        $checkStmt = $mysqli->prepare("
            SELECT id
            FROM laptop_inventory
            WHERE serial_number = ?
            LIMIT 1
        ");

        if(!$checkStmt){
            die("SQL Error: " . $mysqli->error);
        }

        $checkStmt->bind_param("s", $serialNumber);
        $checkStmt->execute();

        if($checkStmt->get_result()->num_rows > 0){
            $error = "Serial Number already exists.";
        }
        else{
            $stmt = $mysqli->prepare("
                INSERT INTO laptop_inventory
                (delivery_date, owner, serial_number, brand, model, office365_license, antivirus_license, license_type, license_ownership, license_family, license_family_details, license_expired_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if(!$stmt){
                die("SQL Error: " . $mysqli->error);
            }

            $stmt->bind_param(
                "sssssssssssss",
                $deliveryDate,
                $owner,
                $serialNumber,
                $brand,
                $model,
                $office365License,
                $antivirusLicense,
                $licenseType,
                $licenseOwnership,
                $licenseFamily,
                $licenseFamilyDetails,
                $licenseExpiredDate,
                $username
            );

            if($stmt->execute()){
                $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
                $time = date("Y-m-d H:i:s");

                $description = "User [$username] added office inventory.
Owner: $owner
Serial Number: $serialNumber
Brand: $brand
Model: $model
Office License For Owner: $office365License
Antivirus For Owner: $antivirusLicense
Delivery Date: $deliveryDate
IP Address: $ip
Time: $time";

                logActivity($mysqli, $username, $role, "ADD OFFICE INVENTORY", $description);

                header("Location: office_inventory.php");
                exit();
            }

            $error = "Insert failed: " . $stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Office Inventory</title>

<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">Add Office Inventory</h2>

<?php if($error !== ""): ?>
<div class="alert alert-danger"><?= officeAddEscape($error) ?></div>
<?php endif; ?>

<form method="POST">
<div class="row">

<div class="col-md-6 mb-3">
    <label>Delivery Date</label>
    <input type="date" name="delivery_date" class="form-control" value="<?= officeAddOld('delivery_date') ?>">
</div>

<div class="col-md-6 mb-3">
    <label>Owner *</label>
    <select name="owner" class="form-select" required>
        <option value="">Select Owner</option>
        <?php foreach($ownerOptions as $option): ?>
            <option value="<?= officeAddEscape($option) ?>" <?= officeAddSelected('owner', $option) ?>>
                <?= officeAddEscape($option) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="col-md-6 mb-3">
    <label>Serial Number *</label>
    <input type="text" name="serial_number" class="form-control" value="<?= officeAddOld('serial_number') ?>" required>
</div>

<div class="col-md-6 mb-3">
    <label>Brand</label>
    <input type="text" name="brand" class="form-control" value="<?= officeAddOld('brand') ?>">
</div>

<div class="col-md-6 mb-3">
    <label>Model</label>
    <input type="text" name="model" class="form-control" value="<?= officeAddOld('model') ?>">
</div>

<div class="col-md-6 mb-3">
    <label>Office License For Owner</label>
    <select name="office365_license" class="form-select">
        <option value="">None</option>
        <?php foreach(officeInventoryOfficeLicenseOptions() as $optionValue => $optionLabel): ?>
            <option value="<?= officeAddEscape($optionValue) ?>" <?= officeAddSelected('office365_license', $optionValue) ?>>
                <?= officeAddEscape($optionLabel) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="col-md-6 mb-3">
    <label>Antivirus For Owner</label>
    <select name="antivirus_license" class="form-select">
        <option value="">None</option>
        <?php foreach(officeInventoryAntivirusLicenseOptions() as $optionValue => $optionLabel): ?>
            <option value="<?= officeAddEscape($optionValue) ?>" <?= officeAddSelected('antivirus_license', $optionValue) ?>>
                <?= officeAddEscape($optionLabel) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

</div>

<button class="btn btn-warning" name="add">Add</button>
<a href="office_inventory.php" class="btn btn-secondary">Cancel</a>
</form>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>
