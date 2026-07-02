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

if(!hasPermission($mysqli, "office_inventory_view")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$canEdit = hasPermission($mysqli, "office_inventory_edit");
$canDelete = hasPermission($mysqli, "office_inventory_delete");
$error = "";
$ownerOptions = officeInventoryFetchFamilyOptions($mysqli);

function officeEditEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function officeEditSelected($actualValue, $expectedValue){
    return (string)$actualValue === (string)$expectedValue ? 'selected' : '';
}

function officeEditLicenseTypeText($office365License, $antivirusLicense){
    $types = [];

    if($office365License !== ""){
        $types[] = $office365License;
    }

    if($antivirusLicense !== ""){
        $types[] = $antivirusLicense;
    }

    return implode(", ", $types);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id <= 0){
    die("Invalid request");
}

$stmt = $mysqli->prepare("
    SELECT *
    FROM laptop_inventory
    WHERE id = ?
    LIMIT 1
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if(!$row){
    die("Office inventory record not found");
}

if(isset($_POST['delete']) && $canDelete){
    $deleteStmt = $mysqli->prepare("DELETE FROM laptop_inventory WHERE id = ? LIMIT 1");

    if(!$deleteStmt){
        die("SQL Error: " . $mysqli->error);
    }

    $deleteStmt->bind_param("i", $id);

    if($deleteStmt->execute()){
        $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
        $time = date("Y-m-d H:i:s");

        $description = "User [$username] deleted office inventory.
Owner: {$row['owner']}
Serial Number: {$row['serial_number']}
Brand: {$row['brand']}
Model: {$row['model']}
IP Address: $ip
Time: $time";

        logActivity($mysqli, $username, $role, "DELETE OFFICE INVENTORY", $description);

        header("Location: office_inventory.php");
        exit();
    }

    $error = "Delete failed: " . $deleteStmt->error;
}

if(isset($_POST['update']) && $canEdit){
    $deliveryDate = appNormalizeDateInput($_POST['delivery_date'] ?? "");
    $owner = trim($_POST['owner'] ?? "");
    $serialNumber = trim($_POST['serial_number'] ?? "");
    $brand = trim($_POST['brand'] ?? "");
    $model = trim($_POST['model'] ?? "");
    $office365License = trim($_POST['office365_license'] ?? "");
    $antivirusLicense = trim($_POST['antivirus_license'] ?? "");
    $licenseType = officeEditLicenseTypeText($office365License, $antivirusLicense);
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
              AND id <> ?
            LIMIT 1
        ");

        if(!$checkStmt){
            die("SQL Error: " . $mysqli->error);
        }

        $checkStmt->bind_param("si", $serialNumber, $id);
        $checkStmt->execute();

        if($checkStmt->get_result()->num_rows > 0){
            $error = "Serial Number already exists.";
        }
        else{
            $updateStmt = $mysqli->prepare("
                UPDATE laptop_inventory SET
                    delivery_date = ?,
                    owner = ?,
                    serial_number = ?,
                    brand = ?,
                    model = ?,
                    office365_license = ?,
                    antivirus_license = ?,
                    license_type = ?,
                    license_ownership = ?,
                    license_family = ?,
                    license_family_details = ?,
                    license_expired_date = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            if(!$updateStmt){
                die("SQL Error: " . $mysqli->error);
            }

            $updateStmt->bind_param(
                "ssssssssssssi",
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
                $id
            );

            if($updateStmt->execute()){
                $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
                $time = date("Y-m-d H:i:s");

                $description = "User [$username] updated office inventory.
Office Inventory ID: $id

OLD DATA:
- Owner: {$row['owner']}
- Serial Number: {$row['serial_number']}
- Brand: {$row['brand']}
- Model: {$row['model']}
- Office License For Owner: {$row['office365_license']}
- Antivirus For Owner: {$row['antivirus_license']}
- Delivery Date: {$row['delivery_date']}

NEW DATA:
- Owner: $owner
- Serial Number: $serialNumber
- Brand: $brand
- Model: $model
- Office License For Owner: $office365License
- Antivirus For Owner: $antivirusLicense
- Delivery Date: $deliveryDate

IP Address: $ip
Time: $time";

                logActivity($mysqli, $username, $role, "UPDATE OFFICE INVENTORY", $description);

                header("Location: office_inventory.php");
                exit();
            }

            $error = "Update failed: " . $updateStmt->error;
        }
    }
}

$formValues = array_merge($row, $_POST);

$currentOwner = trim((string)($formValues['owner'] ?? ""));

if($currentOwner !== "" && !in_array($currentOwner, $ownerOptions, true)){
    $ownerOptions[] = $currentOwner;
}
?>

<!DOCTYPE html>
<html>
<head>
<title><?= $canEdit ? 'Edit Office Inventory' : 'View Office Inventory' ?></title>

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

<h2 class="mb-4"><?= $canEdit ? 'Edit Office Inventory' : 'View Office Inventory' ?></h2>

<?php if($error !== ""): ?>
<div class="alert alert-danger"><?= officeEditEscape($error) ?></div>
<?php endif; ?>

<form method="POST">
<div class="row">

<div class="col-md-6 mb-3">
    <label>Delivery Date</label>
    <input type="date" name="delivery_date" class="form-control" value="<?= officeEditEscape(appDateInputValue($formValues['delivery_date'] ?? '')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
    <label>Owner *</label>
    <select name="owner" class="form-select" <?= $canEdit ? '' : 'disabled' ?> required>
        <option value="">Select Owner</option>
        <?php foreach($ownerOptions as $option): ?>
            <option value="<?= officeEditEscape($option) ?>" <?= officeEditSelected($formValues['owner'] ?? "", $option) ?>>
                <?= officeEditEscape($option) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="col-md-6 mb-3">
    <label>Serial Number *</label>
    <input type="text" name="serial_number" class="form-control" value="<?= officeEditEscape($formValues['serial_number'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?> required>
</div>

<div class="col-md-6 mb-3">
    <label>Brand</label>
    <input type="text" name="brand" class="form-control" value="<?= officeEditEscape($formValues['brand'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
    <label>Model</label>
    <input type="text" name="model" class="form-control" value="<?= officeEditEscape($formValues['model'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
    <label>Office License For Owner</label>
    <select name="office365_license" class="form-select" <?= $canEdit ? '' : 'disabled' ?>>
        <option value="">None</option>
        <?php foreach(officeInventoryOfficeLicenseOptions() as $optionValue => $optionLabel): ?>
            <option value="<?= officeEditEscape($optionValue) ?>" <?= officeEditSelected($formValues['office365_license'] ?? "", $optionValue) ?>>
                <?= officeEditEscape($optionLabel) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="col-md-6 mb-3">
    <label>Antivirus For Owner</label>
    <select name="antivirus_license" class="form-select" <?= $canEdit ? '' : 'disabled' ?>>
        <option value="">None</option>
        <?php foreach(officeInventoryAntivirusLicenseOptions() as $optionValue => $optionLabel): ?>
            <option value="<?= officeEditEscape($optionValue) ?>" <?= officeEditSelected($formValues['antivirus_license'] ?? "", $optionValue) ?>>
                <?= officeEditEscape($optionLabel) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

</div>

<?php if($canEdit): ?>
<button class="btn btn-warning" name="update">Update</button>
<?php endif; ?>

<?php if($canDelete): ?>
<button class="btn btn-danger" name="delete" onclick="return confirm('Delete this office inventory record?')">Delete</button>
<?php endif; ?>

<a href="office_inventory.php" class="btn btn-secondary">Cancel</a>
</form>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>
