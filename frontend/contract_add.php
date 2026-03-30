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
   // "User (Technical)",
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
require_once "../includes/activity_log.php";

logActivity(
    $mysqli,
    $_SESSION['username'],
    $_SESSION['role'],
    "ADD CONTRACT",
    "Added contract: $contract_name"
);
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

<form method="POST" class="modern-form">

<div class="row">

    <!-- LEFT COLUMN -->
    <div class="col-md-6">

        <div class="form-floating mb-3">
            <input type="text" name="org_name" class="form-control" placeholder="Organization" required>
            <label>Organization Name</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="contract_name" class="form-control" placeholder="Contract Name" required>
            <label>Contract Name</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="contract_code" class="form-control" placeholder="Code">
            <label>Contract Code</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="location" class="form-control" placeholder="Location">
            <label>Location</label>
        </div>

    </div>

    <!-- RIGHT COLUMN -->
    <div class="col-md-6">

        <div class="form-floating mb-3">
            <input type="date" name="contract_start" class="form-control">
            <label>Contract Start</label>
        </div>

        <div class="form-floating mb-3">
            <input type="date" name="contract_end" class="form-control">
            <label>Contract End</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="pic" class="form-control" placeholder="PIC">
            <label>Person In Charge</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="partner" class="form-control" placeholder="Partner">
            <label>Partner</label>
        </div>

    </div>

</div>

<!-- FULL WIDTH -->
<div class="form-floating mb-3">
    <input type="text" name="support_coverage" class="form-control" placeholder="Support">
    <label>Support Coverage</label>
</div>

<div class="form-floating mb-3">
    <input type="text" name="preventive_management" class="form-control" placeholder="Preventive">
    <label>Preventive Management</label>
</div>

<div class="form-floating mb-3">
    <input type="text" name="partner_pic" class="form-control" placeholder="Partner PIC">
    <label>Partner Person In Charge</label>
</div>

<div class="form-floating mb-3">
    <textarea name="remark" class="form-control" style="height:100px"></textarea>
    <label>Remarks</label>
</div>

<!-- BUTTONS -->
<div class="d-flex justify-content-end gap-2 mt-3">

    <a href="contracts.php" class="btn btn-light px-4">
        Cancel
    </a>

    <button type="submit" name="submit" class="btn btn-warning px-4">
        <i class="fa fa-save"></i> Save
    </button>

</div>

</form>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>