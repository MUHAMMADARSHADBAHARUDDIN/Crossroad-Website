<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";
require_once "../includes/office_family_helper.php";
require_once "../includes/office_inventory_documents.php";
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
$ownerOptions = officeInventoryOwnerOptions($mysqli);

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
    $ownerChoice = trim($_POST['owner_choice'] ?? "");
    $customOwner = trim($_POST['custom_owner'] ?? "");
    $owner = $ownerChoice === "__other__" ? $customOwner : $ownerChoice;
    $serialNumber = trim($_POST['serial_number'] ?? "");
    $brand = trim($_POST['brand'] ?? "");
    $model = trim($_POST['model'] ?? "");
    $remark = trim($_POST['remark'] ?? "");
    $office365License = trim($_POST['office365_license'] ?? "");
    $antivirusLicense = trim($_POST['antivirus_license'] ?? "");
    $licenseType = officeLicenseTypeText($office365License, $antivirusLicense);
    $licenseOwnership = "";
    $licenseFamily = "";
    $licenseFamilyDetails = null;
    $licenseExpiredDate = null;
    $documentFileName = null;
    $documentOriginalName = null;
    $documentUploadedBy = null;
    $documentUploadedAt = null;
    $hasDocumentUpload = isset($_FILES['office_document']) && (int)($_FILES['office_document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if($hasDocumentUpload){
        $uploadError = officeInventoryDocumentValidateUpload($_FILES['office_document']);

        if($uploadError !== ""){
            $error = $uploadError;
        }
    }

    if($error === "" && ($owner === "" || $serialNumber === "")){
        $error = "Owner and Serial Number are required.";
    }
    elseif($error === "" && strlen($owner) > 150){
        $error = "Owner must not exceed 150 characters.";
    }
    elseif($error === ""){
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
            $uploadedPath = "";

            $stmt = $mysqli->prepare("
                INSERT INTO laptop_inventory
                (delivery_date, owner, serial_number, brand, model, remark, office365_license, antivirus_license, license_type, license_ownership, license_family, license_family_details, license_expired_date, document_file_name, document_original_name, document_uploaded_by, document_uploaded_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if(!$stmt){
                die("SQL Error: " . $mysqli->error);
            }

            if($hasDocumentUpload){
                $documentOriginalName = basename((string)($_FILES['office_document']['name'] ?? ""));
                $documentFileName = officeInventoryDocumentStoredFileName($documentOriginalName);
                $documentUploadedBy = $username;
                $documentUploadedAt = date("Y-m-d H:i:s");
                $uploadDir = officeInventoryDocumentEnsureUploadDir();
                $uploadedPath = $uploadDir . "/" . $documentFileName;

                if(!move_uploaded_file($_FILES['office_document']['tmp_name'], $uploadedPath)){
                    $error = "Failed to move uploaded document.";
                }
            }

            if($error === ""){
                $stmt->bind_param(
                    "ssssssssssssssssss",
                    $deliveryDate,
                    $owner,
                    $serialNumber,
                    $brand,
                    $model,
                    $remark,
                    $office365License,
                    $antivirusLicense,
                    $licenseType,
                    $licenseOwnership,
                    $licenseFamily,
                    $licenseFamilyDetails,
                    $licenseExpiredDate,
                    $documentFileName,
                    $documentOriginalName,
                    $documentUploadedBy,
                    $documentUploadedAt,
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
Remark: $remark
Office License For Owner: $office365License
Antivirus For Owner: $antivirusLicense
Document: " . ($documentOriginalName ?? "-") . "
Delivery Date: $deliveryDate
IP Address: $ip
Time: $time";

                    logActivity($mysqli, $username, $role, "ADD OFFICE INVENTORY", $description);

                    header("Location: office_inventory.php");
                    exit();
                }

                $error = "Insert failed: " . $stmt->error;
            }

            if($uploadedPath !== "" && is_file($uploadedPath)){
                unlink($uploadedPath);
            }
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

<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="<?= officeInventoryDocumentMaxUploadBytes() ?>">
<div class="row">

<div class="col-md-6 mb-3">
    <label>Delivery Date</label>
    <input type="date" name="delivery_date" class="form-control" value="<?= officeAddOld('delivery_date') ?>">
</div>

<div class="col-md-6 mb-3">
    <label>Owner *</label>
    <select name="owner_choice" id="officeOwnerChoice" class="form-select" required>
        <option value="">Select Owner</option>
        <?php foreach($ownerOptions as $option): ?>
            <option value="<?= officeAddEscape($option) ?>" <?= officeAddSelected('owner_choice', $option) ?>>
                <?= officeAddEscape($option) ?>
            </option>
        <?php endforeach; ?>
        <option value="__other__" <?= officeAddSelected('owner_choice', '__other__') ?>>Other</option>
    </select>
</div>

<div class="col-md-6 mb-3 <?= (($_POST['owner_choice'] ?? '') === '__other__') ? '' : 'd-none' ?>" id="officeCustomOwnerWrap">
    <label>Other Owner *</label>
    <input type="text" name="custom_owner" id="officeCustomOwner" maxlength="150" class="form-control" value="<?= officeAddOld('custom_owner') ?>" placeholder="Enter owner name">
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

<div class="col-12 mb-3">
    <label>Remark</label>
    <textarea name="remark" class="form-control" rows="3" placeholder="Add notes about this device"><?= officeAddOld('remark') ?></textarea>
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

<div class="col-12 mb-3">
    <label>Document</label>
    <input
        type="file"
        name="office_document"
        class="form-control"
        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png,.zip"
    >
    <div class="form-text">Maximum <?= officeAddEscape(officeInventoryDocumentMaxUploadLabel()) ?>. ZIP files are allowed.</div>
</div>

</div>

<button class="btn btn-warning" name="add">Add</button>
<a href="office_inventory.php" class="btn btn-secondary">Cancel</a>
</form>

<script>
(function(){
    const select = document.getElementById("officeOwnerChoice");
    const wrap = document.getElementById("officeCustomOwnerWrap");
    const input = document.getElementById("officeCustomOwner");
    function syncOwnerInput(){
        const isOther = select && select.value === "__other__";
        wrap?.classList.toggle("d-none", !isOther);
        if(input){ input.required = !!isOther; }
    }
    select?.addEventListener("change", syncOwnerInput);
    syncOwnerInput();
})();
</script>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>
