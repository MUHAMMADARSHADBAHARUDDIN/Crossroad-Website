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

if(isset($_POST['submit'])){

    if(!$canEdit){
        die("Access denied");
    }

    $year_awarded = intval($_POST['year_awarded']);
    $project_name = trim($_POST['project_name']);
    $project_owner = trim($_POST['project_owner']);
    $end_user = trim($_POST['end_user']);
    $contract_no = trim($_POST['contract_no']);
    $service = trim($_POST['service']);
    $po_date = trim($_POST['po_date']);
    $contract_start = trim($_POST['contract_start']);
    $contract_end = trim($_POST['contract_end']);
    $amount = floatval($_POST['amount']);

    $today = date('Y-m-d');

    if(empty($contract_end)){
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

    $updateStmt = $mysqli->prepare("
        UPDATE project_inventory SET
            year_awarded = ?,
            project_name = ?,
            project_owner = ?,
            end_user = ?,
            contract_no = ?,
            service = ?,
            po_date = ?,
            contract_start = ?,
            contract_end = ?,
            status = ?,
            amount = ?
        WHERE no = ?
    ");

    if(!$updateStmt){
        die("SQL Error: " . $mysqli->error);
    }

    $updateStmt->bind_param(
        "isssssssssdi",
        $year_awarded,
        $project_name,
        $project_owner,
        $end_user,
        $contract_no,
        $service,
        $po_date,
        $contract_start,
        $contract_end,
        $status,
        $amount,
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
- Year Awarded: {$row['year_awarded']}
- Project Name: {$row['project_name']}
- Project Owner: {$row['project_owner']}
- End User: {$row['end_user']}
- Contract No: {$row['contract_no']}
- Service: {$row['service']}
- PO Date: {$row['po_date']}
- Start Date: {$row['contract_start']}
- End Date: {$row['contract_end']}
- Amount: RM {$row['amount']}
- Status: {$row['status']}

NEW DATA:
- Year Awarded: $year_awarded
- Project Name: $project_name
- Project Owner: $project_owner
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

    header("Location: contracts.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>

<title><?= $canEdit ? 'Edit Contract' : 'View Contract' ?></title>

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
</style>

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<h2 class="mb-4"><?= $canEdit ? 'Edit Contract' : 'View Contract' ?></h2>

<form method="POST" class="form-card">

<div class="row g-3">

<div class="col-md-6">

<div class="form-floating">
<input type="number" name="no" class="form-control" value="<?= htmlspecialchars($row['no']) ?>" readonly>
<label>No</label>
</div>

<div class="form-floating mt-3">
<input type="number" name="year_awarded" class="form-control" value="<?= htmlspecialchars($row['year_awarded'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?> required>
<label>Year Awarded</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="project_name" class="form-control" value="<?= htmlspecialchars($row['project_name'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?> required>
<label>Project Name</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="project_owner" class="form-control" value="<?= htmlspecialchars($row['project_owner'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Project Owner</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="end_user" class="form-control" value="<?= htmlspecialchars($row['end_user'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>End User</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="contract_no" class="form-control" value="<?= htmlspecialchars($row['contract_no'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Contract No</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="service" class="form-control" value="<?= htmlspecialchars($row['service'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Service</label>
</div>

</div>

<div class="col-md-6">

<div class="form-floating">
<input type="date" name="po_date" class="form-control" value="<?= htmlspecialchars($row['po_date'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>PO Date</label>
</div>

<div class="form-floating mt-3">
<input type="date" name="contract_start" class="form-control" value="<?= htmlspecialchars($row['contract_start'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Start Date</label>
</div>

<div class="form-floating mt-3">
<input type="date" name="contract_end" class="form-control" value="<?= htmlspecialchars($row['contract_end'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>End Date</label>
</div>

<div class="form-floating mt-3">
<input type="number" step="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($row['amount'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
<label>Amount (RM)</label>
</div>

</div>

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

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>