<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
$created_by = $_SESSION['username'];
$role = $_SESSION['role'];

$allowed = [
    "Administrator",
    "User (Project Coordinator)",
    "User (Project Manager)"
];

if(!in_array($role,$allowed)){
    header("Location: contracts.php");
    exit();
}

if(isset($_POST['submit'])){

    $no = $_POST['no']; // 👈 NEW MANUAL ID

    $year_awarded = $_POST['year_awarded'];
    $project_name = $_POST['project_name'];
    $project_owner = $_POST['project_owner'];
    $end_user = $_POST['end_user'];
    $contract_no = $_POST['contract_no'];
    $service = $_POST['service'];
    $po_date = $_POST['po_date'];
    $contract_start = $_POST['contract_start'];
    $contract_end = $_POST['contract_end'];
    $amount = $_POST['amount'];

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

    $stmt = $mysqli->prepare("
        INSERT INTO project_inventory
        (no, year_awarded, project_name, project_owner, end_user,
        contract_no, service, po_date, contract_start, contract_end, status, amount, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param(
        "iisssssssssds",
        $no,
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
        $created_by
    );

    $stmt->execute();

    require_once "../includes/activity_log.php";

    $adminUser = $_SESSION['username'];
    $adminRole = $_SESSION['role'];

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$adminUser] created new contract.
    Contract No: $no
    Project Name: $project_name
    Year Awarded: $year_awarded
    Project Owner: $project_owner
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

<h2 class="mb-4">Add Contract</h2>

<form method="POST" class="form-card">

<div class="row g-3">

<!-- LEFT COLUMN -->
<div class="col-md-6">

<div class="form-floating">
<input type="number" name="no" class="form-control" required>
<label>No</label>
</div>

<div class="form-floating mt-3">
<input type="number" name="year_awarded" class="form-control" required>
<label>Year Awarded</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="project_name" class="form-control" required>
<label>Project Name</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="project_owner" class="form-control">
<label>Project Owner</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="end_user" class="form-control">
<label>End User</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="contract_no" class="form-control">
<label>Contract No</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="service" class="form-control">
<label>Service</label>
</div>

</div>

<!-- RIGHT COLUMN -->
<div class="col-md-6">

<div class="form-floating">
<input type="date" name="po_date" class="form-control">
<label>PO Date</label>
</div>

<div class="form-floating mt-3">
<input type="date" name="contract_start" class="form-control">
<label>Start Date</label>
</div>

<div class="form-floating mt-3">
<input type="date" name="contract_end" class="form-control">
<label>End Date</label>
</div>

<div class="form-floating mt-3">
<input type="number" step="0.01" name="amount" class="form-control">
<label>Amount (RM)</label>
</div>

</div>

</div>

<!-- BUTTON -->
<div class="d-flex justify-content-end gap-2 mt-4">
<a href="contracts.php" class="btn btn-light px-4">Cancel</a>
<button type="submit" name="submit" class="btn btn-warning px-4">
<i class="fa fa-save"></i> Save
</button>
</div>

</form>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>