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

ensureContractProjectSchema($mysqli);

if(!hasContractAddAccess($mysqli)){
    header("Location: contracts.php");
    exit();
}

$created_by = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";

function getNextContractNo($mysqli){
    $result = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM project_inventory
    ");

    $total = 0;

    if($result){
        $row = $result->fetch_assoc();
        $total = (int)($row['total'] ?? 0);
    }

    $nextNo = $total + 1;

    /*
        Safety check:
        If total + 1 already exists because an old middle record was deleted,
        keep increasing until available.
    */
    while(true){
        $checkStmt = $mysqli->prepare("
            SELECT no
            FROM project_inventory
            WHERE no = ?
            LIMIT 1
        ");

        if(!$checkStmt){
            die("SQL Error: " . $mysqli->error);
        }

        $checkStmt->bind_param("i", $nextNo);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if($checkResult->num_rows <= 0){
            break;
        }

        $nextNo++;
    }

    return $nextNo;
}

function contractAddEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function contractAddFetchDistinctValues($mysqli, $columnName){
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
        while($row = $result->fetch_assoc()){
            $value = trim((string)($row['value'] ?? ""));

            if($value !== ""){
                $values[] = $value;
            }
        }
    }

    return $values;
}

function contractAddFetchProjectCodeMiddles($mysqli){
    $values = [];
    $result = $mysqli->query("
        SELECT DISTINCT project_code
        FROM project_inventory
        WHERE project_code IS NOT NULL
          AND TRIM(project_code) <> ''
        ORDER BY project_code ASC
        LIMIT 500
    ");

    if($result){
        while($row = $result->fetch_assoc()){
            $middle = contractProjectCodeMiddleFromCode($row['project_code'] ?? "");

            if($middle !== "" && !in_array($middle, $values, true)){
                $values[] = $middle;
            }
        }
    }

    sort($values, SORT_NATURAL | SORT_FLAG_CASE);

    return $values;
}

function contractAddDropdownValue($fieldName){
    $selected = trim((string)($_POST[$fieldName . '_select'] ?? ""));

    if($selected === "__other__"){
        return trim((string)($_POST[$fieldName . '_other'] ?? ""));
    }

    return trim((string)$selected);
}

$nextContractNo = getNextContractNo($mysqli);
$projectCodeMiddleOptions = contractAddFetchProjectCodeMiddles($mysqli);
$projectNameOptions = contractAddFetchDistinctValues($mysqli, "project_name");
$projectOwnerOptions = contractAddFetchDistinctValues($mysqli, "project_owner");

if(isset($_POST['submit'])){

    /*
        Auto detect again during submit.
        This prevents duplicate number if another user adds a contract
        while this page is open.
    */
    $no = getNextContractNo($mysqli);

    $year_awarded = intval($_POST['year_awarded']);
    $project_name = contractAddDropdownValue("project_name");
    $project_owner = contractAddDropdownValue("project_owner");
    $project_manager = trim($_POST['project_manager']);
    $account_manager = trim($_POST['account_manager']);
    $end_user = trim($_POST['end_user']);
    $contract_no = trim($_POST['contract_no']);
    $project_code_middle = contractProjectCodeMiddleNormalize(contractAddDropdownValue("project_code_middle"));
    $service = trim($_POST['service']);
    $po_date = appNormalizeDateInput($_POST['po_date'] ?? "");
    $contract_start = appNormalizeDateInput($_POST['contract_start'] ?? "");
    $contract_end = appNormalizeDateInput($_POST['contract_end'] ?? "");
    $amount = floatval($_POST['amount']);

    if($project_code_middle === ""){
        echo "<script>alert('Please enter the project code middle part, for example IWK or PERKESO.'); window.history.back();</script>";
        exit();
    }

    if($project_name === ""){
        echo "<script>alert('Please select a project name or choose Others to enter a new one.'); window.history.back();</script>";
        exit();
    }

    $project_code = contractProjectCodeGenerateFromMiddle($mysqli, $project_code_middle);

    if(contractProjectCodeExists($mysqli, $project_code)){
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

    $checkStmt = $mysqli->prepare("
        SELECT no
        FROM project_inventory
        WHERE no = ?
        LIMIT 1
    ");

    if(!$checkStmt){
        die("SQL Error: " . $mysqli->error);
    }

    $checkStmt->bind_param("i", $no);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if($checkResult->num_rows > 0){
        echo "<script>alert('Contract No already exists. Please refresh and try again.'); window.history.back();</script>";
        exit();
    }

    $stmt = $mysqli->prepare("
        INSERT INTO project_inventory
        (no, project_code, year_awarded, project_name, project_owner, project_manager, account_manager, end_user,
        contract_no, service, po_date, contract_start, contract_end, status, amount, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param(
        "isisssssssssssds",
        $no,
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
        $created_by
    );

    if(!$stmt->execute()){
        die("Insert Error: " . $stmt->error);
    }

    $adminUser = $_SESSION['username'];
    $adminRole = $_SESSION['role'] ?? "UNKNOWN";

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$adminUser] created new contract.
Contract No: $no
Project Code Middle: $project_code_middle
Generated Project Code: $project_code
Project Name: $project_name
Year Awarded: $year_awarded
Project Owner: $project_owner
Project Manager: $project_manager
Account Manager: $account_manager
End User: $end_user
Service: $service
PO Date: $po_date
Start Date: $contract_start
End Date: $contract_end
Amount: RM $amount
Status: $status
IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $adminUser,
        $adminRole,
        "ADD CONTRACT",
        $description
    );

    header("Location: contracts.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>

<title>Add Contract</title>

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

<h2 class="mb-4">Add Contract</h2>

<form method="POST" class="form-card">

<!-- FIRST ROW: PROJECT CODE + YEAR AWARDED -->
<div class="form-section-title">Contract Basic Information</div>

<div class="row g-3 mb-3">

<div class="col-md-6">
    <div class="form-floating">
        <select
            name="project_code_middle_select"
            id="projectCodeMiddleSelect"
            class="form-select contract-other-select"
            data-other-target="projectCodeMiddleOtherWrap"
            data-other-required="1"
            required
        >
            <option value="">Select Project Code Middle</option>
            <?php foreach($projectCodeMiddleOptions as $middle): ?>
                <option value="<?= contractAddEscape($middle) ?>"><?= contractAddEscape($middle) ?></option>
            <?php endforeach; ?>
            <option value="__other__">Others</option>
        </select>
        <label>Project Code Middle</label>
    </div>
    <div id="projectCodeMiddleOtherWrap" class="form-floating contract-other-field mt-2">
        <input
            type="text"
            name="project_code_middle_other"
            id="projectCodeMiddleOther"
            class="form-control"
            placeholder="IWK"
        >
        <label>New Project Code Middle</label>
    </div>
    <div class="auto-no-note">
        Choose an existing middle part or select Others to add a new one. The system will save it as PRO/IWK/001, PRO/IWK/002, and so on.
    </div>
</div>

<div class="col-md-6">
    <div class="form-floating">
        <select name="year_awarded" class="form-control" required>
            <option value="">Select Year Awarded</option>

            <?php
            $currentYear = (int)date("Y");
            $startYear = $currentYear + 5;
            $endYear = 1990;

            for($year = $startYear; $year >= $endYear; $year--):
            ?>
                <option value="<?= $year ?>" <?= $year === $currentYear ? 'selected' : '' ?>>
                    <?= $year ?>
                </option>
            <?php endfor; ?>
        </select>
        <label>Year Awarded</label>
    </div>
</div>

</div>

<!-- SECOND ROW: PO DATE + START DATE + END DATE -->
<div class="form-section-title">Important Dates</div>

<div class="row g-3 mb-3">

<div class="col-md-4">
    <div class="form-floating">
        <input type="date" name="po_date" class="form-control">
        <label>PO Date</label>
    </div>
</div>

<div class="col-md-4">
    <div class="form-floating">
        <input type="date" name="contract_start" class="form-control">
        <label>Start Date</label>
    </div>
</div>

<div class="col-md-4">
    <div class="form-floating">
        <input type="date" name="contract_end" class="form-control">
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
            required
        >
            <option value="">Select Project Name</option>
            <?php foreach($projectNameOptions as $projectNameOption): ?>
                <option value="<?= contractAddEscape($projectNameOption) ?>"><?= contractAddEscape($projectNameOption) ?></option>
            <?php endforeach; ?>
            <option value="__other__">Others</option>
        </select>
        <label>Project Name</label>
    </div>
    <div id="projectNameOtherWrap" class="form-floating contract-other-field mt-2">
        <textarea
            name="project_name_other"
            id="projectNameOther"
            class="form-control project-name-box"
            placeholder="Project Name"
        ></textarea>
        <label>New Project Name</label>
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
>
    <option value="">Select Project Owner</option>
    <?php foreach($projectOwnerOptions as $projectOwnerOption): ?>
        <option value="<?= contractAddEscape($projectOwnerOption) ?>"><?= contractAddEscape($projectOwnerOption) ?></option>
    <?php endforeach; ?>
    <option value="__other__">Others</option>
</select>
<label>Project Owner</label>
</div>
<div id="projectOwnerOtherWrap" class="form-floating contract-other-field mt-2">
<input type="text" name="project_owner_other" id="projectOwnerOther" class="form-control" placeholder="Project Owner">
<label>New Project Owner</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="project_manager" class="form-control">
<label>Project Manager</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="account_manager" class="form-control">
<label>Account Manager</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="end_user" class="form-control">
<label>End User</label>
</div>

</div>

<div class="col-md-6">

<div class="form-floating">
<input type="text" name="contract_no" class="form-control">
<label>Contract No</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="service" class="form-control">
<label>Service</label>
</div>

<div class="form-floating mt-3">
<input type="number" step="0.01" name="amount" class="form-control">
<label>Amount (RM)</label>
</div>

</div>

</div>

<div class="d-flex justify-content-end gap-2 mt-4">
<a href="contracts.php" class="btn btn-light px-4">Cancel</a>
<button type="submit" name="submit" class="btn btn-warning px-4">
<i class="fa fa-save"></i> Save
</button>
</div>

</form>

</div>

<?php include "layout/footer.php"; ?>

<script>
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
            field.required = showOther && requireOther;

            if(!showOther){
                field.value = "";
            }
        });
    }

    select.addEventListener("change", syncOtherField);
    syncOtherField();
});
</script>

</body>
</html>
