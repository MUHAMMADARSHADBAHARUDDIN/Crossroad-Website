<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/date_helpers.php";
require_once "../includes/contract_schema.php";
require_once "../includes/contract_notifications.php";

ensureContractProjectSchema($mysqli);

function contractEditEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function contractEditFetchDistinctValues($mysqli, $columnName){
    $allowedColumns = ["project_name", "project_owner"];

    if(!in_array($columnName, $allowedColumns, true)){
        return [];
    }

    $values = [];
    $result = $mysqli->query("
        SELECT DISTINCT `$columnName` AS value
        FROM project_inventory
        WHERE `$columnName` IS NOT NULL
          AND TRIM(`$columnName`) <> ''
        ORDER BY `$columnName` ASC
        LIMIT 500
    ");

    if($result){
        while($valueRow = $result->fetch_assoc()){
            $value = trim((string)($valueRow['value'] ?? ""));

            if($value !== ""){
                $values[] = $value;
            }
        }
    }

    return $values;
}

function contractEditDropdownValue($fieldName){
    $selected = trim((string)($_POST[$fieldName . '_select'] ?? ""));

    if($selected === "__other__"){
        return trim((string)($_POST[$fieldName . '_other'] ?? ""));
    }

    return $selected;
}

function contractEditTitleCase($value){
    $value = trim(preg_replace('/\s+/', ' ', (string)($value ?? "")));

    if($value === ""){
        return "";
    }

    return preg_replace_callback('/[A-Za-z][A-Za-z0-9]*/', function($matches){
        return ucfirst(strtolower($matches[0]));
    }, $value);
}

function contractEditNormalizeOptionKey($value){
    $value = strtoupper((string)($value ?? ""));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function contractEditFetchEndUserOptions($mysqli){
    $options = contractProjectCodeCanonicalEndUsers();
    $extraOptions = [];
    $seen = [];

    foreach($options as $prefix => $label){
        $seen["PREFIX:" . $prefix] = true;
    }

    $result = $mysqli->query("
        SELECT DISTINCT end_user AS value
        FROM project_inventory
        WHERE end_user IS NOT NULL
          AND TRIM(end_user) <> ''
        ORDER BY end_user ASC
        LIMIT 500
    ");

    if($result){
        while($valueRow = $result->fetch_assoc()){
            $value = trim((string)($valueRow['value'] ?? ""));

            if($value === ""){
                continue;
            }

            $prefix = contractProjectCodeFindKnownPrefix($value);

            if($prefix !== ""){
                if(!isset($seen["PREFIX:" . $prefix])){
                    $options[$prefix] = contractProjectCodeCanonicalEndUser($value);
                    $seen["PREFIX:" . $prefix] = true;
                }

                continue;
            }

            $key = contractEditNormalizeOptionKey($value);

            if($key === "" || isset($seen["RAW:" . $key])){
                continue;
            }

            $seen["RAW:" . $key] = true;
            $extraOptions[] = contractEditTitleCase($value);
        }
    }

    natcasesort($extraOptions);

    return array_merge(array_values($options), array_values($extraOptions));
}

function contractEditFindOptionValue($options, $value){
    $key = contractEditNormalizeOptionKey($value);

    foreach($options as $option){
        if(contractEditNormalizeOptionKey($option) === $key){
            return $option;
        }
    }

    return null;
}

if(!hasContractViewAccess($mysqli)){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if(!$id){
    die("Invalid request");
}

$stmt = $mysqli->prepare("
    SELECT *
    FROM project_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_assoc();

if(!$row){
    die("Contract not found");
}

$created_by = $row['created_by'] ?? "";
$canEdit = hasContractEditAccess($mysqli, $created_by);
$currentProjectCode = contractProjectCodeNormalize($row['project_code'] ?? "");
$currentProjectCodeMiddle = contractProjectCodeMiddleFromCode($row['project_code'] ?? "");
$currentProjectCodeDisplay = contractProjectCodeDisplay($row['project_code'] ?? "");
$projectNameOptions = contractEditFetchDistinctValues($mysqli, "project_name");
$projectOwnerOptions = contractEditFetchDistinctValues($mysqli, "project_owner");
$endUserOptions = contractEditFetchEndUserOptions($mysqli);
$currentProjectNameOption = contractEditFindOptionValue($projectNameOptions, $row['project_name'] ?? "");
$currentProjectOwnerOption = contractEditFindOptionValue($projectOwnerOptions, $row['project_owner'] ?? "");
$currentEndUser = contractProjectCodeCanonicalEndUser($row['end_user'] ?? "");
$currentEndUserOption = contractEditFindOptionValue($endUserOptions, $currentEndUser);

if(isset($_POST['submit'])){

    if(!$canEdit){
        die("Access denied");
    }

    $project_name = contractEditTitleCase(contractEditDropdownValue("project_name"));
    $project_owner = contractEditTitleCase(contractEditDropdownValue("project_owner"));
    $project_manager = contractEditTitleCase($_POST['project_manager'] ?? "");
    $account_manager = contractEditTitleCase($_POST['account_manager'] ?? "");
    $end_user = contractEditDropdownValue("end_user");
    $endUserIsOther = ($_POST['end_user_select'] ?? "") === "__other__";

    if($endUserIsOther){
        $end_user = contractEditTitleCase($end_user);
        $end_user = contractProjectCodeCanonicalEndUser($end_user);
    } else {
        $end_user = contractProjectCodeCanonicalEndUser($end_user);
    }

    $contract_no = trim($_POST['contract_no']);
    $service = contractEditTitleCase($_POST['service'] ?? "");
    $po_date = appNormalizeDateInput($_POST['po_date'] ?? "");
    $contract_start = appNormalizeDateInput($_POST['contract_start'] ?? "");
    $contract_end = appNormalizeDateInput($_POST['contract_end'] ?? "");
    $notification_email = trim((string)($_POST['notification_email'] ?? ""));
    $amount = floatval($_POST['amount']);
    $year_awarded = ($_POST['year_awarded'] ?? '') !== '' ? (int)$_POST['year_awarded'] : null;
    $payment_term = trim($_POST['payment_term'] ?? '');
    $no_of_pm = ($_POST['no_of_pm'] ?? '') !== '' ? (float)$_POST['no_of_pm'] : null;
    $pmFields = [];
    foreach(range(1, 4) as $pmYear){
        foreach(range(1, 4) as $pmQuarter){
            $pmKey = "pm_y{$pmYear}_q{$pmQuarter}";
            $pmFields[$pmKey] = trim($_POST[$pmKey] ?? '');
        }
    }

    if($endUserIsOther){
        $project_code_middle = $_POST['project_code_middle'] ?? "";
    } else {
        $project_code_middle = contractProjectCodePrefix("", $end_user, "", "");
    }

    $project_code_middle = contractProjectCodeMiddleNormalize($project_code_middle);

    if($end_user === ""){
        echo "<script>alert('Please select an end user or choose Others to enter a new one.'); window.history.back();</script>";
        exit();
    }

    if($project_name === ""){
        echo "<script>alert('Please select a project name or choose Others to enter a new one.'); window.history.back();</script>";
        exit();
    }

    if($project_code_middle === ""){
        $projectCodeMessage = $endUserIsOther
            ? "Please enter the Project Code Middle for the new end user."
            : "Unable to generate Project Code Middle from the selected end user.";

        echo "<script>alert('" . $projectCodeMessage . "'); window.history.back();</script>";
        exit();
    }
    elseif(
        $project_code_middle === $currentProjectCodeMiddle
        && $currentProjectCode !== ""
        && !contractProjectCodeIsPlaceholder($currentProjectCode)
    ){
        $project_code = $currentProjectCode;
    }
    else{
        $project_code = contractProjectCodeGenerateFromMiddle($mysqli, $project_code_middle, $id);
    }

    if($project_code !== null && contractProjectCodeExists($mysqli, $project_code, $id)){
        echo "<script>alert('Project Code already exists. Please use a unique project code.'); window.history.back();</script>";
        exit();
    }

    $today = date('Y-m-d');

    if($contract_end === null){
        $status = "Active";
    }
    elseif($contract_end < $today){
        $status = "Closed";
    }
    elseif(strtotime($contract_end) <= strtotime("+30 days")){
        $status = "Expiring";
    }
    else {
        $status = "Active";
    }
    $submittedStatus = trim($_POST['status'] ?? '');
    if(in_array($submittedStatus, ['In Progress', 'Awarded', 'Closed', 'Drop'], true)) $status = $submittedStatus;

    $updateStmt = $mysqli->prepare("
        UPDATE project_inventory SET
            project_code = ?,
            year_awarded = ?,
            project_name = ?,
            project_owner = ?,
            project_manager = ?,
            account_manager = ?,
            end_user = ?,
            contract_no = ?,
            service = ?,
            po_date = ?,
            contract_start = ?,
            contract_end = ?,
            status = ?,
            amount = ?,
            payment_term = ?,
            no_of_pm = ?,
            pm_y1_q1 = ?, pm_y1_q2 = ?, pm_y1_q3 = ?, pm_y1_q4 = ?,
            pm_y2_q1 = ?, pm_y2_q2 = ?, pm_y2_q3 = ?, pm_y2_q4 = ?,
            pm_y3_q1 = ?, pm_y3_q2 = ?, pm_y3_q3 = ?, pm_y3_q4 = ?,
            pm_y4_q1 = ?, pm_y4_q2 = ?, pm_y4_q3 = ?, pm_y4_q4 = ?,
            notification_email = ?
        WHERE no = ?
    ");

    if(!$updateStmt){
        die("SQL Error: " . $mysqli->error);
    }

    $updateStmt->bind_param(
        "sisssssssssssdsdsssssssssssssssssi",
        $project_code,
        $year_awarded,
        $project_name,
        $project_owner,
        $project_manager,
        $account_manager,
        $end_user,
        $contract_no,
        $service,
        $po_date,
        $contract_start,
        $contract_end,
        $status,
        $amount,
        $payment_term,
        $no_of_pm,
        $pmFields['pm_y1_q1'], $pmFields['pm_y1_q2'], $pmFields['pm_y1_q3'], $pmFields['pm_y1_q4'],
        $pmFields['pm_y2_q1'], $pmFields['pm_y2_q2'], $pmFields['pm_y2_q3'], $pmFields['pm_y2_q4'],
        $pmFields['pm_y3_q1'], $pmFields['pm_y3_q2'], $pmFields['pm_y3_q3'], $pmFields['pm_y3_q4'],
        $pmFields['pm_y4_q1'], $pmFields['pm_y4_q2'], $pmFields['pm_y4_q3'], $pmFields['pm_y4_q4'],
        $notification_email,
        $id
    );

    if(!$updateStmt->execute()){
        die("Update Error: " . $updateStmt->error);
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] updated contract.
Contract No: $id

OLD DATA:
- Project Code: " . contractProjectCodeDisplay($row['project_code'] ?? '') . "
- Project Code Middle: " . contractProjectCodeMiddleFromCode($row['project_code'] ?? '') . "
- Project Name: {$row['project_name']}
- Project Owner: {$row['project_owner']}
- Project Manager: " . ($row['project_manager'] ?? '') . "
- Account Manager: " . ($row['account_manager'] ?? '') . "
- End User: {$row['end_user']}
- Contract No: {$row['contract_no']}
- Service: {$row['service']}
- PO Date: {$row['po_date']}
- Start Date: {$row['contract_start']}
- End Date: {$row['contract_end']}
- Amount: RM {$row['amount']}
- Status: {$row['status']}

NEW DATA:
- Project Code: " . contractProjectCodeDisplay($project_code) . "
- Project Code Middle: $project_code_middle
- Project Name: $project_name
- Project Owner: $project_owner
- Project Manager: $project_manager
- Account Manager: $account_manager
- End User: $end_user
- Contract No: $contract_no
- Service: $service
- PO Date: $po_date
- Start Date: $contract_start
- End Date: $contract_end
- Amount: RM $amount
- Status: $status

IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "UPDATE CONTRACT",
        $description
    );

    $contractChanges = [];
    $changeCandidates = [
        "Project Code" => [$row['project_code'] ?? "", $project_code],
        "Year Awarded" => [$row['year_awarded'] ?? "", $year_awarded],
        "Project Name" => [$row['project_name'] ?? "", $project_name],
        "Project Owner" => [$row['project_owner'] ?? "", $project_owner],
        "Project Manager" => [$row['project_manager'] ?? "", $project_manager],
        "Account Manager" => [$row['account_manager'] ?? "", $account_manager],
        "End User" => [$row['end_user'] ?? "", $end_user],
        "Contract No." => [$row['contract_no'] ?? "", $contract_no],
        "Service" => [$row['service'] ?? "", $service],
        "PO Date" => [$row['po_date'] ?? "", $po_date],
        "Start Date" => [$row['contract_start'] ?? "", $contract_start],
        "End Date" => [$row['contract_end'] ?? "", $contract_end],
        "Amount" => [$row['amount'] ?? "", $amount],
        "Payment Term" => [$row['payment_term'] ?? "", $payment_term],
        "No. of PM" => [$row['no_of_pm'] ?? "", $no_of_pm],
        "Status" => [$row['status'] ?? "", $status]
    ];

    foreach($pmFields as $pmKey => $pmValue){
        $changeCandidates[strtoupper(str_replace("_", " ", $pmKey))] = [$row[$pmKey] ?? "", $pmValue];
    }

    foreach($changeCandidates as $changeLabel => $changeValues){
        if(in_array($changeLabel, ["Amount", "No. of PM"], true) && is_numeric($changeValues[0]) && is_numeric($changeValues[1]) && abs((float)$changeValues[0] - (float)$changeValues[1]) < 0.00001){
            continue;
        }

        if((string)$changeValues[0] !== (string)$changeValues[1]){
            $contractChanges[$changeLabel] = ["old" => $changeValues[0], "new" => $changeValues[1]];
        }
    }

    crossroadNotifyContractRecipients($mysqli, [$row['created_by'] ?? $username, $project_manager], "Contract updated", [
        "project_name" => $project_name,
        "project_code" => $project_code,
        "contract_no" => $contract_no,
        "created_by" => $row['created_by'] ?? $username,
        "project_manager" => $project_manager,
        "contract_start" => $contract_start,
        "contract_end" => $contract_end
    ], $contractChanges);

    header("Location: contracts.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>

<title><?= $canEdit ? 'Edit Contract' : 'View Contract' ?></title>

<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
.form-card{
    background:#fff;
    padding:25px;
    border-radius:12px;
    box-shadow:0 5px 20px rgba(0,0,0,0.05);
}

.project-name-box{
    min-height:105px !important;
    resize:vertical;
}

.form-section-title{
    font-size:14px;
    font-weight:700;
    color:#6c757d;
    text-transform:uppercase;
    letter-spacing:0.4px;
    margin-bottom:10px;
}

.auto-no-note{
    font-size:12px;
    color:#6c757d;
    margin-top:5px;
}

.contract-other-field{
    display:none;
}

.contract-other-field.is-visible{
    display:block;
}

@media(max-width:768px){
    .form-card{
        padding:18px;
    }
}
</style>

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<h2 class="mb-4"><?= $canEdit ? 'Edit Contract' : 'View Contract' ?></h2>

<form method="POST" class="form-card">

<!-- DATES -->
<div class="form-section-title">Contract Basic Information</div>

<div class="row g-3 mb-3">

<div class="col-md-3">
    <div class="form-floating">
        <input type="number" min="1900" max="2200" name="year_awarded" class="form-control" value="<?= htmlspecialchars($row['year_awarded'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
        <label>Year Awarded</label>
    </div>
</div>

<div class="col-md-3">
    <div class="form-floating">
        <select name="status" class="form-select" <?= $canEdit ? '' : 'disabled' ?>>
        <?php foreach(['In Progress', 'Awarded', 'Closed', 'Drop'] as $statusOption): ?>
            <option value="<?= $statusOption ?>" <?= strcasecmp((string)($row['status'] ?? ''), $statusOption) === 0 ? 'selected' : '' ?>><?= $statusOption ?></option>
        <?php endforeach; ?>
        </select><label>Status</label>
    </div>
</div>

<div class="col-md-3">
    <div class="form-floating">
        <input
            type="date"
            name="po_date"
            class="form-control"
            value="<?= htmlspecialchars(appDateInputValue($row['po_date'] ?? '')) ?>"
            <?= $canEdit ? '' : 'readonly' ?>
        >
        <label>PO Date</label>
    </div>
</div>

<div class="col-md-3">
    <div class="form-floating">
        <input
            type="date"
            name="contract_start"
            class="form-control"
            value="<?= htmlspecialchars(appDateInputValue($row['contract_start'] ?? '')) ?>"
            <?= $canEdit ? '' : 'readonly' ?>
        >
        <label>Start Date</label>
    </div>
</div>

<div class="col-md-3">
    <div class="form-floating">
        <input
            type="date"
            name="contract_end"
            class="form-control"
            value="<?= htmlspecialchars(appDateInputValue($row['contract_end'] ?? '')) ?>"
            <?= $canEdit ? '' : 'readonly' ?>
        >
        <label>End Date</label>
    </div>
</div>

</div>

<!-- PROJECT NAME BIGGER BOX -->
<div class="form-section-title">Project Details</div>

<div class="row g-3 mb-3">

<div class="col-md-12">
    <div class="form-floating">
        <select
            name="project_name_select"
            id="projectNameSelect"
            class="form-select contract-other-select"
            data-other-target="projectNameOtherWrap"
            data-other-required="1"
            <?= $canEdit ? '' : 'disabled' ?>
            required
        >
            <option value="">Select Project Name</option>
            <?php foreach($projectNameOptions as $projectNameOption): ?>
                <option value="<?= contractEditEscape($projectNameOption) ?>" <?= $currentProjectNameOption === $projectNameOption ? 'selected' : '' ?>><?= contractEditEscape($projectNameOption) ?></option>
            <?php endforeach; ?>
            <option value="__other__" <?= $currentProjectNameOption === null ? 'selected' : '' ?>>Others</option>
        </select>
        <label>Project Name</label>
    </div>
    <div id="projectNameOtherWrap" class="form-floating contract-other-field mt-2">
        <textarea
            name="project_name_other"
            id="projectNameOther"
            class="form-control project-name-box contract-title-case"
            placeholder="Project Name"
            <?= $canEdit ? '' : 'readonly' ?>
        ><?= $currentProjectNameOption === null ? contractEditEscape($row['project_name'] ?? '') : '' ?></textarea>
        <label>New Project Name</label>
    </div>
</div>

</div>

<div class="row g-3 mb-3">

<div class="col-md-6">
    <div class="form-floating">
        <select
            name="end_user_select"
            id="endUserSelect"
            class="form-select contract-other-select"
            data-other-target="endUserOtherWrap"
            data-other-required="1"
            <?= $canEdit ? '' : 'disabled' ?>
            required
        >
            <option value="">Select End User</option>
            <?php foreach($endUserOptions as $endUserOption): ?>
                <option value="<?= contractEditEscape($endUserOption) ?>" <?= $currentEndUserOption === $endUserOption ? 'selected' : '' ?>><?= contractEditEscape($endUserOption) ?></option>
            <?php endforeach; ?>
            <option value="__other__" <?= $currentEndUserOption === null ? 'selected' : '' ?>>Others</option>
        </select>
        <label>End User</label>
    </div>
    <div id="endUserOtherWrap" class="form-floating contract-other-field mt-2">
        <input
            type="text"
            name="end_user_other"
            id="endUserOther"
            class="form-control contract-title-case"
            value="<?= $currentEndUserOption === null ? contractEditEscape($currentEndUser) : '' ?>"
            placeholder="End User"
            <?= $canEdit ? '' : 'readonly' ?>
        >
        <label>New End User</label>
    </div>
</div>

<div class="col-md-6">
    <div class="form-floating">
        <input
            type="text"
            name="project_code_middle"
            id="projectCodeMiddlePreview"
            class="form-control"
            value="<?= contractEditEscape($currentProjectCodeMiddle) ?>"
            placeholder="Project Code Middle"
            readonly
        >
        <label>Project Code Middle</label>
    </div>
    <div class="auto-no-note">
        Current Project Code: <?= contractEditEscape($currentProjectCodeDisplay) ?>. Listed End Users generate this automatically; choose Others to type a custom middle.
    </div>
</div>

</div>

<!-- OTHER FIELDS -->
<div class="row g-3">

<div class="col-md-6">

<div class="form-floating">
<select
    name="project_owner_select"
    id="projectOwnerSelect"
    class="form-select contract-other-select"
    data-other-target="projectOwnerOtherWrap"
    data-other-required="1"
    <?= $canEdit ? '' : 'disabled' ?>
>
    <option value="">Select Project Owner</option>
    <?php foreach($projectOwnerOptions as $projectOwnerOption): ?>
        <option value="<?= contractEditEscape($projectOwnerOption) ?>" <?= $currentProjectOwnerOption === $projectOwnerOption ? 'selected' : '' ?>><?= contractEditEscape($projectOwnerOption) ?></option>
    <?php endforeach; ?>
    <option value="__other__" <?= $currentProjectOwnerOption === null && trim((string)($row['project_owner'] ?? '')) !== '' ? 'selected' : '' ?>>Others</option>
</select>
<label>Project Owner</label>
</div>
<div id="projectOwnerOtherWrap" class="form-floating contract-other-field mt-2">
<input type="text" name="project_owner_other" id="projectOwnerOther" class="form-control contract-title-case" value="<?= $currentProjectOwnerOption === null ? contractEditEscape($row['project_owner'] ?? '') : '' ?>" placeholder="Project Owner" <?= $canEdit ? '' : 'readonly' ?>>
<label>New Project Owner</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="project_manager" class="form-control contract-title-case" value="<?= htmlspecialchars($row['project_manager'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Project Manager</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="account_manager" class="form-control contract-title-case" value="<?= htmlspecialchars($row['account_manager'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Account Manager</label>
</div>

</div>

<div class="col-md-6">

<div class="form-floating">
<input type="text" name="contract_no" class="form-control" value="<?= htmlspecialchars($row['contract_no'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Contract No</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="service" class="form-control contract-title-case" value="<?= htmlspecialchars($row['service'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Service</label>
</div>

<div class="form-floating mt-3">
<input type="number" step="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($row['amount'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Amount (RM)</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="payment_term" class="form-control" value="<?= htmlspecialchars($row['payment_term'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Payment Term</label>
</div>

<div class="form-floating mt-3">
<input type="number" step="0.01" min="0" name="no_of_pm" class="form-control" value="<?= htmlspecialchars($row['no_of_pm'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>No. of PM</label>
</div>

</div>

</div>

<div class="form-section-title mt-4">Preventive Maintenance Schedule</div>
<div class="row g-3">
<?php for($pmYear = 1; $pmYear <= 4; $pmYear++): ?>
<div class="col-xl-3 col-md-6">
    <div class="border rounded p-3 h-100">
        <div class="fw-semibold mb-2">Year <?= $pmYear ?></div>
        <div class="row g-2">
        <?php for($pmQuarter = 1; $pmQuarter <= 4; $pmQuarter++): $pmKey = "pm_y{$pmYear}_q{$pmQuarter}"; ?>
            <div class="col-6">
                <label class="form-label small">Q<?= $pmQuarter ?></label>
                <input type="text" name="<?= $pmKey ?>" class="form-control" value="<?= htmlspecialchars($row[$pmKey] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
            </div>
        <?php endfor; ?>
        </div>
    </div>
</div>
<?php endfor; ?>
</div>

<div class="d-flex justify-content-end gap-2 mt-4">
<a href="contracts.php" class="btn btn-light px-4">Cancel</a>

<?php if($canEdit): ?>
<button type="submit" name="submit" class="btn btn-warning px-4">
<i class="fa fa-save"></i> Update
</button>
<?php else: ?>
<span class="badge bg-secondary align-self-center">View Only</span>
<?php endif; ?>
</div>

</form>

<?php if($canEdit): ?>
<div id="contractSavingModal" class="contract-saving-overlay" role="dialog" aria-modal="true" aria-labelledby="contractSavingTitle" aria-describedby="contractSavingText" hidden>
    <div class="contract-saving-box">
        <div class="spinner-border text-warning" role="status" aria-hidden="true"></div>
        <div>
            <strong id="contractSavingTitle">Saving contract changes</strong>
            <span id="contractSavingText">Please wait while email and Telegram notifications are being sent.</span>
        </div>
    </div>
</div>

<style>
.contract-saving-overlay{position:fixed;inset:0;z-index:20000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,18,22,.64);backdrop-filter:blur(2px)}
.contract-saving-overlay[hidden]{display:none}.contract-saving-box{display:flex;align-items:center;gap:17px;width:min(420px,100%);padding:24px;border:1px solid rgba(255,255,255,.2);border-radius:14px;background:#fff;color:#171b20;box-shadow:0 20px 55px rgba(0,0,0,.35)}
.contract-saving-box .spinner-border{width:2.6rem;height:2.6rem;flex:0 0 2.6rem}.contract-saving-box>div:last-child{display:flex;flex-direction:column;gap:4px}.contract-saving-box strong{font-size:1rem}.contract-saving-box span{color:#6c757d;font-size:.84rem;line-height:1.4}
</style>
<?php endif; ?>

</div>

<?php include "layout/footer.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const projectCodeKnownPatterns = <?= json_encode(contractProjectCodeKnownPatterns()) ?>;
const projectCodeCanonicalEndUsers = <?= json_encode(contractProjectCodeCanonicalEndUsers()) ?>;
const canEditContract = <?= $canEdit ? 'true' : 'false' ?>;
const projectCodeSkipTokens = [
    "AND", "THE", "FOR", "SDN", "BHD", "PT", "IT", "ICT", "ITD",
    "NAS", "SAN", "DR", "DC", "DATA", "CENTER", "CENTRE",
    "SUPPLY", "DELIVERY", "INSTALL", "TEST", "COMMISSION",
    "SERVICE", "SERVICES", "MAINTENANCE", "SUPPORT", "LICENSE",
    "RENEWAL", "PEMBAHARUAN", "PERKHIDMATAN", "PENYENGGARAAN",
    "SISTEM", "PROJEK", "PROJECT", "CONTRACT"
];

function normalizeProjectCodeText(value){
    return String(value || "")
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, " ")
        .replace(/\s+/g, " ")
        .trim();
}

function findKnownProjectCodePrefix(value){
    var normalized = normalizeProjectCodeText(value);

    if(normalized === ""){
        return "";
    }

    for(var canonicalPrefix in projectCodeCanonicalEndUsers){
        if(!Object.prototype.hasOwnProperty.call(projectCodeCanonicalEndUsers, canonicalPrefix)){
            continue;
        }

        var canonicalEndUser = normalizeProjectCodeText(projectCodeCanonicalEndUsers[canonicalPrefix]);

        if(canonicalEndUser !== "" && normalized === canonicalEndUser){
            return canonicalPrefix;
        }
    }

    for(var prefix in projectCodeKnownPatterns){
        if(!Object.prototype.hasOwnProperty.call(projectCodeKnownPatterns, prefix)){
            continue;
        }

        var aliases = projectCodeKnownPatterns[prefix] || [];

        for(var i = 0; i < aliases.length; i++){
            var alias = normalizeProjectCodeText(aliases[i]);

            if(alias !== "" && (" " + normalized + " ").indexOf(" " + alias + " ") !== -1){
                return prefix;
            }
        }
    }

    return "";
}

function buildProjectCodeMiddle(value){
    var knownPrefix = findKnownProjectCodePrefix(value);

    if(knownPrefix !== ""){
        return knownPrefix;
    }

    var normalized = normalizeProjectCodeText(value);

    if(normalized === ""){
        return "";
    }

    var tokens = normalized.split(" ");

    for(var i = 0; i < tokens.length; i++){
        var token = tokens[i];

        if(token.length >= 3 && token.length <= 12 && !projectCodeSkipTokens.includes(token) && !/^\d+$/.test(token)){
            return token.slice(0, 12);
        }
    }

    var initials = "";

    for(var j = 0; j < tokens.length; j++){
        var word = tokens[j];

        if(word === "" || projectCodeSkipTokens.includes(word) || /^\d+$/.test(word)){
            continue;
        }

        initials += word.charAt(0);

        if(initials.length >= 5){
            break;
        }
    }

    if(initials.length >= 2){
        return initials.slice(0, 12);
    }

    for(var k = 0; k < tokens.length; k++){
        var fallback = tokens[k];

        if(fallback !== "" && !projectCodeSkipTokens.includes(fallback) && !/^\d+$/.test(fallback)){
            return fallback.slice(0, 12);
        }
    }

    return "";
}

function normalizeProjectCodeMiddleValue(value){
    value = String(value || "")
        .toUpperCase()
        .replace(/\\/g, "/")
        .trim();

    var fullCodeMatch = value.match(/^PRO\/([^\/]*)\/\d+$/);

    if(fullCodeMatch){
        value = fullCodeMatch[1];
    }

    return value
        .replace(/\s+/g, "")
        .replace(/[^A-Z0-9_-]+/g, "-")
        .replace(/^[-_]+|[-_]+$/g, "")
        .slice(0, 36);
}

function canonicalEndUserValue(value){
    var prefix = findKnownProjectCodePrefix(value);

    if(prefix !== "" && projectCodeCanonicalEndUsers[prefix]){
        return projectCodeCanonicalEndUsers[prefix];
    }

    return value;
}

function selectedEndUserValue(){
    var select = document.getElementById("endUserSelect");
    var other = document.getElementById("endUserOther");

    if(!select){
        return "";
    }

    if(select.value === "__other__"){
        return other ? other.value : "";
    }

    return select.value;
}

function syncProjectCodeMiddlePreview(){
    var preview = document.getElementById("projectCodeMiddlePreview");
    var select = document.getElementById("endUserSelect");

    if(!preview){
        return;
    }

    if(!canEditContract){
        preview.readOnly = true;
        return;
    }

    var allowManual = canEditContract && select && select.value === "__other__";
    preview.readOnly = !allowManual;
    preview.required = !!allowManual;

    if(!allowManual){
        projectCodeMiddleEdited = false;
        preview.value = buildProjectCodeMiddle(selectedEndUserValue());
        return;
    }

    if(!projectCodeMiddleEdited){
        preview.value = buildProjectCodeMiddle(selectedEndUserValue());
    }
}

function titleCaseContractText(value){
    return String(value || "")
        .replace(/\s+/g, " ")
        .trim()
        .replace(/[A-Za-z][A-Za-z0-9]*/g, function(word){
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        });
}

function normalizeContractTitleCaseField(field){
    var normalized = titleCaseContractText(field.value);

    if(field.id === "endUserOther"){
        normalized = canonicalEndUserValue(normalized);
    }

    if(field.value !== normalized){
        field.value = normalized;
    }

    if(field.id === "endUserOther"){
        syncProjectCodeMiddlePreview();
    }
}

document.querySelectorAll(".contract-title-case").forEach(function(field){
    field.addEventListener("blur", function(){
        normalizeContractTitleCaseField(field);
    });
});

document.querySelectorAll(".contract-other-select").forEach(function(select){
    function syncOtherField(){
        var target = document.getElementById(select.dataset.otherTarget || "");

        if(!target){
            return;
        }

        var fields = target.querySelectorAll("input, textarea");
        var showOther = select.value === "__other__";
        var requireOther = select.dataset.otherRequired === "1";

        target.classList.toggle("is-visible", showOther);

        fields.forEach(function(field){
            field.required = showOther && requireOther && canEditContract;

            if(!showOther){
                field.value = "";
            }
        });
    }

    select.addEventListener("change", syncOtherField);
    syncOtherField();
});

var endUserSelect = document.getElementById("endUserSelect");
var endUserOther = document.getElementById("endUserOther");
var projectCodeMiddlePreview = document.getElementById("projectCodeMiddlePreview");
var projectCodeMiddleEdited = !!(
    endUserSelect
    && endUserSelect.value === "__other__"
    && projectCodeMiddlePreview
    && projectCodeMiddlePreview.value.trim() !== ""
);

if(endUserSelect){
    endUserSelect.addEventListener("change", function(){
        projectCodeMiddleEdited = false;
        syncProjectCodeMiddlePreview();
    });
}

if(endUserOther){
    endUserOther.addEventListener("input", syncProjectCodeMiddlePreview);
}

if(projectCodeMiddlePreview){
    projectCodeMiddlePreview.addEventListener("input", function(){
        if(!projectCodeMiddlePreview.readOnly){
            projectCodeMiddleEdited = true;
        }
    });

    projectCodeMiddlePreview.addEventListener("blur", function(){
        if(!projectCodeMiddlePreview.readOnly){
            projectCodeMiddlePreview.value = normalizeProjectCodeMiddleValue(projectCodeMiddlePreview.value);
        }
    });
}

var contractEditForm = document.querySelector("form.form-card");
var contractEditSending = false;
if(contractEditForm && canEditContract){
    contractEditForm.addEventListener("submit", function(event){
        if(contractEditSending){
            return;
        }

        event.preventDefault();
        document.querySelectorAll(".contract-title-case").forEach(function(field){
            normalizeContractTitleCaseField(field);
        });

        if(projectCodeMiddlePreview){
            if(projectCodeMiddlePreview.readOnly){
                syncProjectCodeMiddlePreview();
            } else {
                projectCodeMiddlePreview.value = normalizeProjectCodeMiddleValue(projectCodeMiddlePreview.value);
            }
        }

        document.getElementById("contractSavingModal").hidden = false;
        document.body.setAttribute("aria-busy", "true");
        window.setTimeout(function(){
            contractEditSending = true;
            contractEditForm.requestSubmit(contractEditForm.querySelector('button[name="submit"]'));
        }, 180);
    });
}

syncProjectCodeMiddlePreview();
</script>

</body>
</html>
