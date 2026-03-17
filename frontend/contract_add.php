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
    "User (Technical)",
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

    $created_by = $_SESSION['username'];

    $sql = "INSERT INTO project_inventory
    (name, contract_name, contract_code, contract_start, contract_end, location, pic, support_coverage, preventive_management, partner, partner_pic, remark, created_by)
    VALUES
    ('$org_name','$contract_name','$contract_code','$contract_start','$contract_end','$location','$pic','$support_coverage','$preventive_management','$partner','$partner_pic','$remark','$created_by')";

    $mysqli->query($sql);

    header("Location: contracts.php");
}
?>

<!DOCTYPE html>
<html>
<head>

    <title>Add Contract</title>

    <link rel="stylesheet" href="style.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<h2 class="mb-4">Add Contract</h2>

<div class="">
<div class="card-body p-10">

<form method="POST">

<div class="container-fluid">

<div class="row g-3">

<!-- Organization -->
<div class="col-md-6">
<label class="form-label">Organization Name</label>
<input type="text" name="org_name" class="form-control" required>
</div>

<!-- Contract Name -->
<div class="col-md-6">
<label class="form-label">Contract Name</label>
<input type="text" name="contract_name" class="form-control" required>
</div>

<!-- Contract Code -->
<div class="col-md-6">
<label class="form-label">Contract Code</label>
<input type="text" name="contract_code" class="form-control">
</div>

<!-- Location -->
<div class="col-md-6">
<label class="form-label">Location</label>
<input type="text" name="location" class="form-control">
</div>

<!-- Contract Start -->
<div class="col-md-6">
<label class="form-label">Contract Start</label>
<input type="date" name="contract_start" class="form-control">
</div>

<!-- Contract End -->
<div class="col-md-6">
<label class="form-label">Contract End</label>
<input type="date" name="contract_end" class="form-control">
</div>

<!-- PIC -->
<div class="col-md-6">
<label class="form-label">Person In Charge</label>
<input type="text" name="pic" class="form-control">
</div>

<!-- Support -->
<div class="col-md-6">
<label class="form-label">Support Coverage</label>
<input type="text" name="support_coverage" class="form-control">
</div>

<!-- Preventive -->
<div class="col-md-6">
<label class="form-label">Preventive Management</label>
<input type="text" name="preventive_management" class="form-control">
</div>

<!-- Partner -->
<div class="col-md-6">
<label class="form-label">Partner</label>
<input type="text" name="partner" class="form-control">
</div>

<!-- Partner PIC -->
<div class="col-md-6">
<label class="form-label">Partner Person In Charge</label>
<input type="text" name="partner_pic" class="form-control">
</div>

<!-- Remarks -->
<div class="col-12">
<label class="form-label">Remarks</label>
<textarea name="remark" class="form-control" rows="3"></textarea>
</div>

</div>

<hr class="my-4">

<div class="d-flex gap-2">

<button type="submit" name="submit" class="btn btn-warning">
<i class="fa fa-save"></i> Add Contract
</button>

<a href="contracts.php" class="btn btn-secondary">
<i class="fa fa-arrow-left"></i> Cancel
</a>

</div>

</div>

</form>

</div>
</div>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>