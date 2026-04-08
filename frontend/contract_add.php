<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

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

    $org_name = $_POST['org_name'];
    $contract_name = $_POST['contract_name'];
    $contract_code = $_POST['contract_code'];
    $contract_start = $_POST['contract_start'];
    $contract_end = $_POST['contract_end'];
    $location = $_POST['location'];
    $pic = $_POST['pic'];
    $support_coverage = $_POST['support_coverage'];
    $preventive_management = $_POST['preventive_management'];
    $partner = $_POST['partner'];
    $partner_pic = $_POST['partner_pic'];
    $remark = $_POST['remark'];

    // NEW FIELDS
    $status = $_POST['status'];
    $amount = $_POST['amount'];

    $created_by = $_SESSION['username'];

    // ✅ PREPARED STATEMENT (SAFE)
    $stmt = $mysqli->prepare("
        INSERT INTO project_inventory
        (name, contract_name, contract_code, contract_start, contract_end,
         location, pic, support_coverage, preventive_management,
         partner, partner_pic, remark, status, amount, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "sssssssssssssss",
        $org_name,
        $contract_name,
        $contract_code,
        $contract_start,
        $contract_end,
        $location,
        $pic,
        $support_coverage,
        $preventive_management,
        $partner,
        $partner_pic,
        $remark,
        $status,
        $amount,
        $created_by
    );

    $stmt->execute();

    require_once "../includes/activity_log.php";

    logActivity(
        $mysqli,
        $_SESSION['username'],
        $_SESSION['role'],
        "ADD CONTRACT",
        "Added contract: $contract_name"
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

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<h2 class="mb-4">Add Contract</h2>

<form method="POST" class="modern-form">

<div class="row">

<!-- LEFT -->
<div class="col-md-6">

<div class="form-floating mb-3">
<input type="text" name="org_name" class="form-control" placeholder="Organization" required>
<label>Organization Name</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="contract_name" class="form-control" placeholder="Project Name" required>
<label>Project Name</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="contract_code" class="form-control" placeholder="Code">
<label>Contract Code</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="location" class="form-control">
<label>Location</label>
</div>

</div>

<!-- RIGHT -->
<div class="col-md-6">

<div class="form-floating mb-3">
<input type="date" name="contract_start" class="form-control">
<label>Start Date</label>
</div>

<div class="form-floating mb-3">
<input type="date" name="contract_end" class="form-control">
<label>End Date</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="pic" class="form-control">
<label>Person In Charge</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="partner" class="form-control">
<label>Partner</label>
</div>

</div>

</div>

<!-- NEW FIELDS -->
<div class="row">

<div class="col-md-6">
<div class="form-floating mb-3">
<select name="status" class="form-control" required>
<option value="Active">Active</option>
<option value="Completed">Completed</option>
<option value="Pending">Pending</option>
<option value="Cancelled">Cancelled</option>
</select>
<label>Status</label>
</div>
</div>

<div class="col-md-6">
<div class="form-floating mb-3">
<input type="number" name="amount" class="form-control" placeholder="Amount">
<label>Amount (RM)</label>
</div>
</div>

</div>

<!-- FULL WIDTH -->
<div class="form-floating mb-3">
<input type="text" name="support_coverage" class="form-control">
<label>Support Coverage</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="preventive_management" class="form-control">
<label>Preventive Management</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="partner_pic" class="form-control">
<label>Partner PIC</label>
</div>

<div class="form-floating mb-3">
<textarea name="remark" class="form-control" style="height:100px"></textarea>
<label>Remarks</label>
</div>

<!-- BUTTON -->
<div class="d-flex justify-content-end gap-2 mt-3">
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