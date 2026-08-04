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

if(!hasPermission($mysqli, "office_inventory_view")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$canEdit = hasPermission($mysqli, "office_inventory_edit");
$error = "";
$ownerOptions = officeInventoryOwnerOptions($mysqli);

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

if(isset($_POST['update']) && $canEdit){
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
    $licenseType = officeEditLicenseTypeText($office365License, $antivirusLicense);
    $licenseOwnership = "";
    $licenseFamily = "";
    $licenseFamilyDetails = null;
    $licenseExpiredDate = null;
    $documentFileName = $row['document_file_name'] ?? null;
    $documentOriginalName = $row['document_original_name'] ?? null;
    $documentUploadedBy = $row['document_uploaded_by'] ?? null;
    $documentUploadedAt = $row['document_uploaded_at'] ?? null;
    $oldDocumentFileName = trim((string)$documentFileName);
    $uploadedPath = "";
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
                    remark = ?,
                    office365_license = ?,
                    antivirus_license = ?,
                    license_type = ?,
                    license_ownership = ?,
                    license_family = ?,
                    license_family_details = ?,
                    license_expired_date = ?,
                    document_file_name = ?,
                    document_original_name = ?,
                    document_uploaded_by = ?,
                    document_uploaded_at = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            if(!$updateStmt){
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
                $updateStmt->bind_param(
                    "sssssssssssssssssi",
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
                    $id
                );

                if($updateStmt->execute()){
                    if($hasDocumentUpload && $oldDocumentFileName !== ""){
                        $oldDocumentPath = officeInventoryDocumentDiskPath($oldDocumentFileName);

                        if(is_file($oldDocumentPath)){
                            unlink($oldDocumentPath);
                        }
                    }

                    $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
                    $time = date("Y-m-d H:i:s");

                    $description = "User [$username] updated office inventory.
Office Inventory ID: $id

OLD DATA:
- Owner: {$row['owner']}
- Serial Number: {$row['serial_number']}
- Brand: {$row['brand']}
- Model: {$row['model']}
- Remark: {$row['remark']}
- Office License For Owner: {$row['office365_license']}
- Antivirus For Owner: {$row['antivirus_license']}
- Delivery Date: {$row['delivery_date']}

NEW DATA:
- Owner: $owner
- Serial Number: $serialNumber
- Brand: $brand
- Model: $model
- Remark: $remark
- Office License For Owner: $office365License
- Antivirus For Owner: $antivirusLicense
- Delivery Date: $deliveryDate
- Document: " . (trim((string)$documentOriginalName) !== "" ? $documentOriginalName : "-") . "

IP Address: $ip
Time: $time";

                    logActivity($mysqli, $username, $role, "UPDATE OFFICE INVENTORY", $description);

                    header("Location: office_inventory.php");
                    exit();
                }

                $error = "Update failed: " . $updateStmt->error;
            }

            if($uploadedPath !== "" && is_file($uploadedPath)){
                unlink($uploadedPath);
            }
        }
    }
}

$formValues = array_merge($row, $_POST);

$currentOwner = trim((string)($row['owner'] ?? ""));
if(strcasecmp($currentOwner, "In Storage") === 0){
    $currentOwner = "Available";
}
$formValues['owner'] = $currentOwner;
if(isset($_POST['owner_choice'])){
    $formValues['owner_choice'] = $_POST['owner_choice'];
}
elseif($currentOwner !== "" && !in_array($currentOwner, $ownerOptions, true)){
    $formValues['owner_choice'] = "__other__";
    $formValues['custom_owner'] = $currentOwner;
}
else{
    $formValues['owner_choice'] = $currentOwner;
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

<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="<?= officeInventoryDocumentMaxUploadBytes() ?>">
<div class="row">

<div class="col-md-6 mb-3">
    <label>Delivery Date</label>
    <input type="date" name="delivery_date" class="form-control" value="<?= officeEditEscape(appDateInputValue($formValues['delivery_date'] ?? '')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
    <label>Owner *</label>
    <select name="owner_choice" id="officeOwnerChoice" class="form-select" <?= $canEdit ? '' : 'disabled' ?> required>
        <option value="">Select Owner</option>
        <?php foreach($ownerOptions as $option): ?>
            <option value="<?= officeEditEscape($option) ?>" <?= officeEditSelected($formValues['owner_choice'] ?? "", $option) ?>>
                <?= officeEditEscape($option) ?>
            </option>
        <?php endforeach; ?>
        <option value="__other__" <?= officeEditSelected($formValues['owner_choice'] ?? "", '__other__') ?>>Other</option>
    </select>
</div>

<div class="col-md-6 mb-3 <?= (($formValues['owner_choice'] ?? '') === '__other__') ? '' : 'd-none' ?>" id="officeCustomOwnerWrap">
    <label>Other Owner *</label>
    <input type="text" name="custom_owner" id="officeCustomOwner" maxlength="150" class="form-control" value="<?= officeEditEscape($formValues['custom_owner'] ?? '') ?>" placeholder="Enter owner name" <?= $canEdit ? '' : 'readonly' ?>>
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

<div class="col-12 mb-3">
    <label>Remark</label>
    <textarea name="remark" class="form-control" rows="3" placeholder="Add notes about this device" <?= $canEdit ? '' : 'readonly' ?>><?= officeEditEscape($formValues['remark'] ?? '') ?></textarea>
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

<div class="col-12 mb-3">
    <label>Document</label>
    <?php if(trim((string)($row['document_file_name'] ?? '')) !== ''): ?>
        <div class="form-text mb-2">
            Current: <?= officeEditEscape(officeInventoryDocumentDisplayName($row)) ?>
        </div>
    <?php else: ?>
        <div class="form-text mb-2">No document uploaded.</div>
    <?php endif; ?>

    <?php if($canEdit): ?>
        <input
            type="file"
            name="office_document"
            class="form-control"
            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png,.zip"
        >
        <div class="form-text">Maximum <?= officeEditEscape(officeInventoryDocumentMaxUploadLabel()) ?>. Choosing a new file will replace the current document.</div>
    <?php endif; ?>
</div>

</div>

<?php if($canEdit): ?>
<button class="btn btn-warning" name="update">Update</button>
<?php endif; ?>

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
        if(input && !input.readOnly){ input.required = !!isOther; }
    }
    select?.addEventListener("change", syncOwnerInput);
    syncOwnerInput();
})();
</script>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>
