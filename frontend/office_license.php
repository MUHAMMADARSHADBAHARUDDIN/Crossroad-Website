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

$faviconVersion = file_exists("../image/logo.png") ? filemtime("../image/logo.png") : time();
$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$canAdd = hasPermission($mysqli, "office_inventory_add");
$canEdit = hasPermission($mysqli, "office_inventory_edit");
$canDelete = hasPermission($mysqli, "office_inventory_delete");
$search = trim($_GET['search'] ?? "");
$error = "";
$peopleOptions = officeInventoryFetchFamilyOptions($mysqli);

function officeLicenseEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function officeLicenseFormatDate($value){
    $value = trim((string)($value ?? ''));

    if($value === "" || $value === "0000-00-00"){
        return "";
    }

    $timestamp = strtotime($value);

    if($timestamp === false){
        return $value;
    }

    return date("d/m/y", $timestamp);
}

function officeLicenseArrayValue($value){
    if(is_array($value)){
        return $value;
    }

    if($value === null){
        return [];
    }

    return [$value];
}

function officeLicensePostedFamilyRows($post){
    $families = officeLicenseArrayValue($post['license_family'] ?? []);
    $officeLicenses = officeLicenseArrayValue($post['family_office365_license'] ?? []);
    $officeExpiredDates = officeLicenseArrayValue($post['family_office365_expired_date'] ?? []);
    $antivirusLicenses = officeLicenseArrayValue($post['family_antivirus_license'] ?? []);
    $antivirusExpiredDates = officeLicenseArrayValue($post['family_antivirus_expired_date'] ?? []);

    $count = max(
        count($families),
        count($officeLicenses),
        count($officeExpiredDates),
        count($antivirusLicenses),
        count($antivirusExpiredDates),
        1
    );

    $rows = [];

    for($index = 0; $index < $count; $index++){
        $row = [
            "family" => trim((string)($families[$index] ?? "")),
            "office365_license" => trim((string)($officeLicenses[$index] ?? "")),
            "office365_expired_date" => appNormalizeDateInput($officeExpiredDates[$index] ?? ""),
            "antivirus_license" => trim((string)($antivirusLicenses[$index] ?? "")),
            "antivirus_expired_date" => appNormalizeDateInput($antivirusExpiredDates[$index] ?? "")
        ];

        if(
            $row['family'] === "" &&
            $row['office365_license'] === "" &&
            $row['office365_expired_date'] === null &&
            $row['antivirus_license'] === "" &&
            $row['antivirus_expired_date'] === null
        ){
            continue;
        }

        $rows[] = $row;
    }

    return $rows;
}

function officeLicenseFamilyRowsError($owner, $rows){
    if($owner === ""){
        return "Owner is required.";
    }

    if(empty($rows)){
        return "Please add at least one family.";
    }

    foreach($rows as $row){
        $family = trim((string)($row['family'] ?? ""));
        $officeLicense = trim((string)($row['office365_license'] ?? ""));
        $officeExpired = $row['office365_expired_date'] ?? null;
        $antivirusLicense = trim((string)($row['antivirus_license'] ?? ""));
        $antivirusExpired = $row['antivirus_expired_date'] ?? null;

        if($family === ""){
            return "Family is required for every row.";
        }

        if($officeExpired !== null && $officeLicense === ""){
            return "Office license is required when office expired date is entered.";
        }

        if($antivirusExpired !== null && $antivirusLicense === ""){
            return "Antivirus license is required when antivirus expired date is entered.";
        }
    }

    return "";
}

function officeLicenseRecordExists($mysqli, $owner, $family, $licenseName, $expiredDate){
    $stmt = $mysqli->prepare("
        SELECT id
        FROM office_licenses
        WHERE owner = ?
          AND family = ?
          AND license_name = ?
          AND expired_date <=> ?
        LIMIT 1
    ");

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("ssss", $owner, $family, $licenseName, $expiredDate);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function officeLicenseDeleteFamilyPlaceholder($mysqli, $owner, $family){
    $stmt = $mysqli->prepare("
        DELETE FROM office_licenses
        WHERE owner = ?
          AND family = ?
          AND TRIM(COALESCE(license_name, '')) = ''
    ");

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("ss", $owner, $family);
    $stmt->execute();
}

function officeLicenseInsertIfMissing($mysqli, $owner, $family, $licenseName, $expiredDate, $createdBy, $allowEmptyLicense = false){
    if($owner === "" || $family === ""){
        return;
    }

    if($licenseName === "" && !$allowEmptyLicense){
        return;
    }

    if($licenseName !== ""){
        officeLicenseDeleteFamilyPlaceholder($mysqli, $owner, $family);
    }

    if(officeLicenseRecordExists($mysqli, $owner, $family, $licenseName, $expiredDate)){
        return;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO office_licenses
            (owner, family, license_name, expired_date, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("sssss", $owner, $family, $licenseName, $expiredDate, $createdBy);
    $stmt->execute();
}

function officeLicenseSaveOwnerFamilyRows($mysqli, $owner, $rows, $createdBy){
    foreach($rows as $row){
        $family = trim((string)($row['family'] ?? ""));
        $officeLicense = trim((string)($row['office365_license'] ?? ""));
        $officeExpired = $row['office365_expired_date'] ?? null;
        $antivirusLicense = trim((string)($row['antivirus_license'] ?? ""));
        $antivirusExpired = $row['antivirus_expired_date'] ?? null;

        if($family === ""){
            continue;
        }

        if($officeLicense === "" && $antivirusLicense === ""){
            officeLicenseInsertIfMissing($mysqli, $owner, $family, "", null, $createdBy, true);
            continue;
        }

        officeLicenseInsertIfMissing($mysqli, $owner, $family, $officeLicense, $officeExpired, $createdBy);
        officeLicenseInsertIfMissing($mysqli, $owner, $family, $antivirusLicense, $antivirusExpired, $createdBy);
    }
}

function officeLicenseDeleteOwnerRows($mysqli, $owner){
    $stmt = $mysqli->prepare("DELETE FROM office_licenses WHERE owner = ?");

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("s", $owner);
    $stmt->execute();
}

function officeLicenseMigrateLegacyRows($mysqli, $fallbackUser){
    $result = $mysqli->query("
        SELECT *
        FROM laptop_inventory
        WHERE TRIM(COALESCE(license_family, '')) <> ''
           OR TRIM(COALESCE(license_family_details, '')) <> ''
        ORDER BY id ASC
    ");

    if(!$result){
        return;
    }

    while($row = $result->fetch_assoc()){
        $owner = trim((string)($row['owner'] ?? ""));
        $createdBy = trim((string)($row['created_by'] ?? ""));

        if($createdBy === ""){
            $createdBy = $fallbackUser;
        }

        foreach(officeInventoryFamilyDetailRowsForRecord($row) as $familyRow){
            $family = trim((string)($familyRow['family'] ?? ""));
            $officeLicense = trim((string)($familyRow['office365_license'] ?? ""));
            $officeExpired = appNormalizeDateInput($familyRow['office365_expired_date'] ?? "");
            $antivirusLicense = trim((string)($familyRow['antivirus_license'] ?? ""));
            $antivirusExpired = appNormalizeDateInput($familyRow['antivirus_expired_date'] ?? "");

            officeLicenseInsertIfMissing($mysqli, $owner, $family, $officeLicense, $officeExpired, $createdBy);
            officeLicenseInsertIfMissing($mysqli, $owner, $family, $antivirusLicense, $antivirusExpired, $createdBy);
        }
    }
}

function officeLicenseNumberedHtml($values){
    if(empty($values)){
        return "<span class=\"text-muted\">-</span>";
    }

    $html = "";

    foreach($values as $index => $value){
        $displayValue = trim((string)$value);

        if($displayValue === ""){
            $displayValue = "-";
        }

        $html .= "<div class=\"office-license-numbered-line\"><span>" . ($index + 1) . ")</span><strong>" . officeLicenseEscape($displayValue) . "</strong></div>";
    }

    return $html;
}

function officeLicenseBuildOwnerGroups($mysqli){
    $result = $mysqli->query("
        SELECT *
        FROM office_licenses
        ORDER BY id ASC
    ");

    if(!$result){
        die("SQL Error: " . $mysqli->error);
    }

    $officeOptions = officeInventoryOfficeLicenseOptions();
    $antivirusOptions = officeInventoryAntivirusLicenseOptions();
    $groups = [];

    while($row = $result->fetch_assoc()){
        $owner = trim((string)($row['owner'] ?? ""));

        if($owner === ""){
            $owner = "Unassigned";
        }

        if(!isset($groups[$owner])){
            $groups[$owner] = [
                "owner" => $owner,
                "latest_id" => 0,
                "families" => []
            ];
        }

        $id = (int)($row['id'] ?? 0);
        $family = trim((string)($row['family'] ?? ""));

        if($family === ""){
            $family = "Unassigned";
        }

        $familyKey = strtolower($family);

        if(!isset($groups[$owner]['families'][$familyKey])){
            $groups[$owner]['families'][$familyKey] = [
                "family" => $family,
                "min_id" => $id,
                "office365_license" => "",
                "office365_expired_date" => null,
                "antivirus_license" => "",
                "antivirus_expired_date" => null,
                "extra_licenses" => []
            ];
        }

        $groups[$owner]['latest_id'] = max($groups[$owner]['latest_id'], $id);
        $groups[$owner]['families'][$familyKey]['min_id'] = min($groups[$owner]['families'][$familyKey]['min_id'], $id);

        $licenseName = trim((string)($row['license_name'] ?? ""));
        $expiredDate = appNormalizeDateInput($row['expired_date'] ?? "");

        if($licenseName === ""){
            continue;
        }

        if(isset($officeOptions[$licenseName])){
            if($groups[$owner]['families'][$familyKey]['office365_license'] === ""){
                $groups[$owner]['families'][$familyKey]['office365_license'] = $licenseName;
                $groups[$owner]['families'][$familyKey]['office365_expired_date'] = $expiredDate;
            }

            continue;
        }

        if(isset($antivirusOptions[$licenseName])){
            if($groups[$owner]['families'][$familyKey]['antivirus_license'] === ""){
                $groups[$owner]['families'][$familyKey]['antivirus_license'] = $licenseName;
                $groups[$owner]['families'][$familyKey]['antivirus_expired_date'] = $expiredDate;
            }

            continue;
        }

        $groups[$owner]['families'][$familyKey]['extra_licenses'][] = [
            "license_name" => $licenseName,
            "expired_date" => $expiredDate
        ];
    }

    foreach($groups as $owner => $group){
        uasort($group['families'], function($left, $right){
            return ($left['min_id'] ?? 0) <=> ($right['min_id'] ?? 0);
        });

        $familyRows = [];
        $familyValues = [];
        $licenseValues = [];
        $expiredValues = [];
        $searchValues = [$owner];

        foreach($group['families'] as $familyRow){
            $licenseParts = [];
            $expiredParts = [];

            if($familyRow['office365_license'] !== ""){
                $licenseParts[] = $familyRow['office365_license'];
                $expiredParts[] = officeLicenseFormatDate($familyRow['office365_expired_date']) ?: "-";
            }

            if($familyRow['antivirus_license'] !== ""){
                $licenseParts[] = $familyRow['antivirus_license'];
                $expiredParts[] = officeLicenseFormatDate($familyRow['antivirus_expired_date']) ?: "-";
            }

            foreach($familyRow['extra_licenses'] as $extraLicense){
                $licenseParts[] = $extraLicense['license_name'];
                $expiredParts[] = officeLicenseFormatDate($extraLicense['expired_date']) ?: "-";
            }

            $familyValues[] = $familyRow['family'];
            $licenseValues[] = !empty($licenseParts) ? implode(", ", $licenseParts) : "-";
            $expiredValues[] = !empty($expiredParts) ? implode(", ", $expiredParts) : "-";
            $familyRows[] = [
                "family" => $familyRow['family'],
                "office365_license" => $familyRow['office365_license'],
                "office365_expired_date" => appDateInputValue($familyRow['office365_expired_date']),
                "antivirus_license" => $familyRow['antivirus_license'],
                "antivirus_expired_date" => appDateInputValue($familyRow['antivirus_expired_date'])
            ];

            $searchValues[] = $familyRow['family'];
            $searchValues[] = implode(" ", $licenseParts);
            $searchValues[] = implode(" ", $expiredParts);
        }

        $groups[$owner]['families'] = $familyRows;
        $groups[$owner]['family_values'] = $familyValues;
        $groups[$owner]['license_values'] = $licenseValues;
        $groups[$owner]['expired_values'] = $expiredValues;
        $groups[$owner]['search_text'] = strtolower(implode(" ", $searchValues));
    }

    uasort($groups, function($left, $right){
        return ($right['latest_id'] ?? 0) <=> ($left['latest_id'] ?? 0);
    });

    return array_values($groups);
}

officeLicenseMigrateLegacyRows($mysqli, $username);

$licenseAction = $_POST['license_action'] ?? "";

if($licenseAction === "save"){
    if(!$canAdd){
        die("Access denied");
    }

    $owner = trim($_POST['owner'] ?? "");
    $familyRows = officeLicensePostedFamilyRows($_POST);
    $error = officeLicenseFamilyRowsError($owner, $familyRows);

    if($error === ""){
        officeLicenseSaveOwnerFamilyRows($mysqli, $owner, $familyRows, $username);

        $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
        $time = date("Y-m-d H:i:s");
        $familySummary = implode(", ", array_map(function($row){
            return trim((string)($row['family'] ?? ""));
        }, $familyRows));

        $description = "User [$username] added office license family rows.
Owner: $owner
Families: $familySummary
IP Address: $ip
Time: $time";

        logActivity($mysqli, $username, $role, "ADD OFFICE LICENSE", $description);

        header("Location: office_license.php");
        exit();
    }
}
elseif($licenseAction === "update"){
    if(!$canEdit){
        die("Access denied");
    }

    $originalOwner = trim($_POST['original_owner'] ?? "");
    $owner = trim($_POST['owner'] ?? "");
    $familyRows = officeLicensePostedFamilyRows($_POST);
    $error = officeLicenseFamilyRowsError($owner, $familyRows);

    if($originalOwner === ""){
        $error = "Original owner is missing.";
    }

    if($error === ""){
        $mysqli->begin_transaction();

        try{
            officeLicenseDeleteOwnerRows($mysqli, $originalOwner);
            officeLicenseSaveOwnerFamilyRows($mysqli, $owner, $familyRows, $username);
            $mysqli->commit();
        }
        catch(Throwable $exception){
            $mysqli->rollback();
            throw $exception;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
        $time = date("Y-m-d H:i:s");
        $familySummary = implode(", ", array_map(function($row){
            return trim((string)($row['family'] ?? ""));
        }, $familyRows));

        $description = "User [$username] updated office license family rows.
Old Owner: $originalOwner
New Owner: $owner
Families: $familySummary
IP Address: $ip
Time: $time";

        logActivity($mysqli, $username, $role, "UPDATE OFFICE LICENSE", $description);

        header("Location: office_license.php");
        exit();
    }
}
elseif($licenseAction === "delete_group"){
    if(!$canDelete){
        die("Access denied");
    }

    $owner = trim($_POST['owner_key'] ?? "");

    if($owner !== ""){
        officeLicenseDeleteOwnerRows($mysqli, $owner);

        $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
        $time = date("Y-m-d H:i:s");

        $description = "User [$username] deleted office license group.
Owner: $owner
IP Address: $ip
Time: $time";

        logActivity($mysqli, $username, $role, "DELETE OFFICE LICENSE", $description);

        header("Location: office_license.php");
        exit();
    }
}

$ownerGroups = officeLicenseBuildOwnerGroups($mysqli);
?>

<!DOCTYPE html>
<html>
<head>
<title>Office License</title>

<link rel="icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
<link rel="shortcut icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
<link rel="apple-touch-icon" href="../image/logo.png?v=<?= $faviconVersion ?>">
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>

<style>
html, body{
    overflow-x:hidden !important;
}

.main{
    overflow-x:hidden !important;
    max-width:100%;
}

.table-responsive{
    overflow-x:auto !important;
    overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior-x:contain;
    width:100%;
}

#officeLicenseTable{
    width:100% !important;
    min-width:520px;
    table-layout:auto;
}

#officeLicenseTable th,
#officeLicenseTable td{
    white-space:normal !important;
    word-break:break-word;
    overflow-wrap:anywhere;
    vertical-align:top;
}

#officeLicenseTable tbody tr:hover{
    background:#fff3cd !important;
}

.office-license-owner-row{
    cursor:pointer;
}

#officeLicenseTable_wrapper{
    width:100%;
    overflow-x:hidden;
}

#officeLicenseTable_wrapper .dataTables_length select{
    width:auto;
    min-width:72px;
    display:inline-block;
}

#officeLicenseTable_wrapper .pagination{
    flex-wrap:wrap;
    gap:4px;
}

#officeLicenseTable_wrapper .page-link{
    color:#856404;
}

#officeLicenseTable_wrapper .page-item.active .page-link{
    background-color:#ffc107;
    border-color:#ffc107;
    color:#000;
}

.office-license-actions{
    display:flex;
    flex-wrap:wrap;
    gap:4px;
}

.office-license-actions .btn{
    width:34px;
    height:32px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.office-license-numbered-line{
    display:grid;
    grid-template-columns:28px minmax(0,1fr);
    gap:4px;
    line-height:1.35;
    margin-bottom:4px;
}

.office-license-numbered-line span{
    color:#6c757d;
    font-weight:700;
}

.office-license-numbered-line strong{
    color:#212529;
    font-weight:600;
    word-break:break-word;
}

.office-license-modal .modal-content{
    border:0;
    border-radius:10px;
    box-shadow:0 12px 32px rgba(0,0,0,0.16);
}

.office-license-family-row{
    border:1px solid #dee2e6;
    border-radius:8px;
    background:#fff;
    padding:12px;
}

.office-license-family-row-grid{
    display:grid;
    grid-template-columns:minmax(160px,1.1fr) minmax(150px,1fr) minmax(132px,.85fr) minmax(150px,1fr) minmax(132px,.85fr) 40px;
    gap:10px;
    align-items:end;
}

.office-license-family-remove{
    width:38px;
    height:38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

@media(max-width:992px){
    #officeLicenseTable{
        min-width:520px;
    }

    .office-license-family-row-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .office-license-family-remove{
        width:100%;
    }
}

@media(max-width:768px){
    #officeLicenseTable th,
    #officeLicenseTable td{
        font-size:13px;
        padding:8px;
    }

    .office-license-actions{
        flex-wrap:nowrap;
    }

    .office-license-family-row-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">License</h2>

<?php if($error !== ""): ?>
<div class="alert alert-danger"><?= officeLicenseEscape($error) ?></div>
<?php endif; ?>

<form method="GET" class="mb-2" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveOfficeLicenseSearch"
            name="search"
            class="form-control"
            placeholder="Search... Example: owner, family, license"
            value="<?= officeLicenseEscape($search) ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i>
        </button>
    </div>
</form>

<?php if($canAdd): ?>
<button type="button" class="btn btn-warning mb-3" onclick="openOfficeLicenseModal()">
    <i class="fa fa-plus"></i> Add License
</button>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-striped table-hover align-middle" id="officeLicenseTable">
<thead>
<tr>
    <th>Owner</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach($ownerGroups as $group): ?>
    <?php
    $payload = [
        "owner" => $group['owner'],
        "rows" => $group['families'],
        "family_values" => $group['family_values'] ?? [],
        "license_values" => $group['license_values'] ?? [],
        "expired_values" => $group['expired_values'] ?? []
    ];
    $payloadJson = json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    ?>
    <tr
        class="office-license-owner-row"
        data-search="<?= officeLicenseEscape($group['search_text'] ?? '') ?>"
        onclick='openOfficeLicenseDetail(<?= officeLicenseEscape($payloadJson) ?>)'
    >
        <td class="fw-semibold"><?= officeLicenseEscape($group['owner'] ?? '-') ?></td>
        <td>
            <div class="office-license-actions">
                <?php if($canEdit): ?>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        onclick='event.stopPropagation(); openOfficeLicenseModal(<?= officeLicenseEscape($payloadJson) ?>)'
                        title="Edit"
                    >
                        <i class="fa fa-pen"></i>
                    </button>
                <?php endif; ?>

                <?php if($canDelete): ?>
                    <form method="POST" class="d-inline" onclick="event.stopPropagation();" onsubmit="event.stopPropagation(); return confirm('Delete this owner license group?');">
                        <input type="hidden" name="license_action" value="delete_group">
                        <input type="hidden" name="owner_key" value="<?= officeLicenseEscape($group['owner'] ?? '') ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <i class="fa fa-trash"></i>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if(!$canEdit && !$canDelete): ?>
                    <span class="badge bg-secondary">View Only</span>
                <?php endif; ?>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div>

<div class="modal fade office-license-modal" id="officeLicenseDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="officeLicenseDetailTitle">
          <i class="fa fa-circle-info text-warning"></i>
          License Details
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="officeLicenseDetailContent"></div>
    </div>
  </div>
</div>

<?php if($canAdd || $canEdit): ?>
<div class="modal fade office-license-modal" id="officeLicenseFormModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <form method="POST" class="modal-content">
      <input type="hidden" name="license_action" id="officeLicenseAction" value="save">
      <input type="hidden" name="original_owner" id="officeLicenseOriginalOwner" value="">

      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="officeLicenseFormTitle">
          <i class="fa fa-key text-warning"></i>
          Add License
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Owner *</label>
                <select name="owner" id="officeLicenseOwner" class="form-select" required>
                    <option value="">Select Owner</option>
                    <?php foreach($peopleOptions as $option): ?>
                        <option value="<?= officeLicenseEscape($option) ?>"><?= officeLicenseEscape($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="d-flex flex-column gap-2" id="officeLicenseFamilyRows"></div>

        <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="addOfficeLicenseFamilyBtn">
            <i class="fa fa-plus"></i> Add Family
        </button>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning" id="officeLicenseSubmitBtn">Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let officeLicenseTable;
const officeLicensePeopleOptions = <?= json_encode($peopleOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const officeLicenseOfficeOptions = <?= json_encode(officeInventoryOfficeLicenseOptions(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const officeLicenseAntivirusOptions = <?= json_encode(officeInventoryAntivirusLicenseOptions(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function escapeHtml(value){
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function ensureSelectOption(select, value){
    value = String(value ?? "");

    if(value === ""){
        return;
    }

    const exists = Array.from(select.options).some(function(option){
        return option.value === value;
    });

    if(!exists){
        select.add(new Option(value, value));
    }
}

function setSelectValue(selectId, value){
    const select = document.getElementById(selectId);

    if(!select){
        return;
    }

    ensureSelectOption(select, value);
    select.value = value || "";
}

function peopleOptionsHtml(selectedValue){
    selectedValue = String(selectedValue || "");
    const options = officeLicensePeopleOptions.slice();

    if(selectedValue !== "" && !options.includes(selectedValue)){
        options.push(selectedValue);
    }

    let html = '<option value="">Select Family</option>';

    options.forEach(function(option){
        const selected = option === selectedValue ? " selected" : "";
        html += '<option value="' + escapeHtml(option) + '"' + selected + '>' + escapeHtml(option) + '</option>';
    });

    return html;
}

function optionMapHtml(options, selectedValue, emptyLabel){
    selectedValue = String(selectedValue || "");
    let html = '<option value="">' + escapeHtml(emptyLabel) + '</option>';
    const values = Object.keys(options);

    if(selectedValue !== "" && !values.includes(selectedValue)){
        values.push(selectedValue);
        options = Object.assign({}, options, {[selectedValue]: selectedValue});
    }

    values.forEach(function(value){
        const selected = value === selectedValue ? " selected" : "";
        html += '<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(options[value]) + '</option>';
    });

    return html;
}

function createOfficeLicenseFamilyRow(row){
    const data = row || {};

    return '<div class="office-license-family-row">' +
        '<div class="office-license-family-row-grid">' +
            '<div>' +
                '<label class="form-label">Family</label>' +
                '<select name="license_family[]" class="form-select" required>' + peopleOptionsHtml(data.family || "") + '</select>' +
            '</div>' +
            '<div>' +
                '<label class="form-label">Office License</label>' +
                '<select name="family_office365_license[]" class="form-select">' + optionMapHtml(officeLicenseOfficeOptions, data.office365_license || "", "None") + '</select>' +
            '</div>' +
            '<div>' +
                '<label class="form-label">Office Expired</label>' +
                '<input type="date" name="family_office365_expired_date[]" class="form-control" value="' + escapeHtml(data.office365_expired_date || "") + '">' +
            '</div>' +
            '<div>' +
                '<label class="form-label">Antivirus</label>' +
                '<select name="family_antivirus_license[]" class="form-select">' + optionMapHtml(officeLicenseAntivirusOptions, data.antivirus_license || "", "None") + '</select>' +
            '</div>' +
            '<div>' +
                '<label class="form-label">AV Expired</label>' +
                '<input type="date" name="family_antivirus_expired_date[]" class="form-control" value="' + escapeHtml(data.antivirus_expired_date || "") + '">' +
            '</div>' +
            '<div>' +
                '<button type="button" class="btn btn-outline-danger office-license-family-remove" title="Remove">' +
                    '<i class="fa fa-trash"></i>' +
                '</button>' +
            '</div>' +
        '</div>' +
    '</div>';
}

function renderOfficeLicenseFamilyRows(rows){
    const container = document.getElementById("officeLicenseFamilyRows");

    if(!container){
        return;
    }

    const list = Array.isArray(rows) && rows.length > 0 ? rows : [{}];
    container.innerHTML = list.map(createOfficeLicenseFamilyRow).join("");
}

function openOfficeLicenseDetail(group){
    const record = group || {};
    const families = Array.isArray(record.family_values) ? record.family_values : [];
    const licenses = Array.isArray(record.license_values) ? record.license_values : [];
    const expired = Array.isArray(record.expired_values) ? record.expired_values : [];
    const count = Math.max(families.length, licenses.length, expired.length);

    document.getElementById("officeLicenseDetailTitle").innerHTML =
        '<i class="fa fa-circle-info text-warning"></i> ' + escapeHtml(record.owner || "License Details");

    if(count === 0){
        document.getElementById("officeLicenseDetailContent").innerHTML =
            "<div class='alert alert-secondary mb-0'>No license details found.</div>";
    }
    else{
        let rows = "";

        for(let index = 0; index < count; index++){
            rows += "<tr>" +
                "<td>" + escapeHtml(families[index] || "-") + "</td>" +
                "<td>" + escapeHtml(licenses[index] || "-") + "</td>" +
                "<td>" + escapeHtml(expired[index] || "-") + "</td>" +
            "</tr>";
        }

        document.getElementById("officeLicenseDetailContent").innerHTML =
            "<div class='table-responsive'>" +
                "<table class='table table-sm table-bordered align-middle mb-0'>" +
                    "<thead class='table-light'>" +
                        "<tr><th>Family</th><th>License</th><th>Expired</th></tr>" +
                    "</thead>" +
                    "<tbody>" + rows + "</tbody>" +
                "</table>" +
            "</div>";
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById("officeLicenseDetailModal")).show();
}

function openOfficeLicenseModal(group){
    const record = group || {};
    const isEdit = !!record.owner;
    const modal = document.getElementById("officeLicenseFormModal");

    if(!modal){
        return;
    }

    document.getElementById("officeLicenseFormTitle").innerHTML =
        '<i class="fa fa-key text-warning"></i> ' + (isEdit ? "Edit License" : "Add License");
    document.getElementById("officeLicenseAction").value = isEdit ? "update" : "save";
    document.getElementById("officeLicenseOriginalOwner").value = isEdit ? record.owner : "";
    document.getElementById("officeLicenseSubmitBtn").textContent = isEdit ? "Update" : "Save";

    setSelectValue("officeLicenseOwner", isEdit ? record.owner : "");
    renderOfficeLicenseFamilyRows(isEdit ? record.rows : [{}]);

    bootstrap.Modal.getOrCreateInstance(modal).show();
}

document.addEventListener("click", function(event){
    if(event.target.closest("#addOfficeLicenseFamilyBtn")){
        document.getElementById("officeLicenseFamilyRows").insertAdjacentHTML("beforeend", createOfficeLicenseFamilyRow({}));
        return;
    }

    const removeButton = event.target.closest(".office-license-family-remove");

    if(!removeButton){
        return;
    }

    const rows = document.querySelectorAll(".office-license-family-row");
    const row = removeButton.closest(".office-license-family-row");

    if(rows.length <= 1){
        row.querySelectorAll("select, input").forEach(function(field){
            field.value = "";
        });
        return;
    }

    row.remove();
});

$.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if(settings.nTable.id !== "officeLicenseTable"){
        return true;
    }

    const input = document.getElementById("liveOfficeLicenseSearch");
    const keyword = input ? input.value.toLowerCase().trim() : "";

    if(keyword === ""){
        return true;
    }

    const terms = keyword.split(",").map(term => term.trim()).filter(term => term !== "");
    const rowNode = settings.aoData[dataIndex].nTr;
    const searchText = rowNode ? (rowNode.getAttribute("data-search") || "") : "";

    return terms.every(term => searchText.includes(term));
});

$(document).ready(function(){
    officeLicenseTable = $("#officeLicenseTable").DataTable({
        pageLength:10,
        lengthMenu:[10,25,50,100],
        ordering:true,
        searching:true,
        autoWidth:false,
        scrollX:false,
        order:[],
        dom:"<'row mb-2 align-items-center'<'col-md-6'l>>rt<'row mt-3 align-items-center office-license-bottom-row'<'col-md-6'i><'col-md-6 d-flex justify-content-end'p>>",
        language:{
            zeroRecords:"No records found",
            lengthMenu:"Show _MENU_ entries",
            info:"Showing _START_ to _END_ of _TOTAL_ license records"
        },
        columnDefs:[
            {width:"78%", targets:0},
            {width:"22%", targets:1, orderable:false, searchable:false}
        ]
    });

    $("#liveOfficeLicenseSearch").on("input", function(){
        officeLicenseTable.draw();

        let keyword = this.value.trim();
        let newUrl = "office_license.php" + (keyword !== "" ? "?search=" + encodeURIComponent(keyword) : "");

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    officeLicenseTable.draw();
});
</script>

<?php include "layout/footer.php"; ?>

</body>
</html>
