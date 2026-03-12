<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];

if($role != "Administrator" && $role != "System Admin"){
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

    $sql = "INSERT INTO project_inventory
(Name, contract_name, contract_code, contract_start, contract_end, Location, pic, Support_coverage, preventive_management, partner, partner_pic, remark)
VALUES
('$org_name','$contract_name','$contract_code','$contract_start','$contract_end','$location','$pic','$support_coverage','$preventive_management','$partner','$partner_pic','$remark')";

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

    <h2>Add Contract</h2>

    <div class="card p-4">

        <form method="POST">

            <div class="mb-3">
                <label>Organization Name</label>
                <input type="text" name="org_name" class="form-control">
            </div>

            <div class="mb-3">
                <label>Contract Name</label>
                <input type="text" name="contract_name" class="form-control">
            </div>

            <div class="mb-3">
                <label>Contract Code</label>
                <input type="text" name="contract_code" class="form-control">
            </div>

            <div class="mb-3">
                <label>Contract Start</label>
                <input type="date" name="contract_start" class="form-control">
            </div>

            <div class="mb-3">
                <label>Contract End</label>
                <input type="date" name="contract_end" class="form-control">
            </div>

            <div class="mb-3">
                <label>Location</label>
                <input type="text" name="location" class="form-control">
            </div>

            <div class="mb-3">
                <label>Person In Charge</label>
                <input type="text" name="pic" class="form-control">
            </div>

            <div class="mb-3">
                <label>Support Coverage</label>
                <input type="text" name="support_coverage" class="form-control">
            </div>

            <div class="mb-3">
                <label>Preventive Management</label>
                <input type="text" name="preventive_management" class="form-control">
            </div>

            <div class="mb-3">
                <label>Partner</label>
                <input type="text" name="partner" class="form-control">
            </div>

            <div class="mb-3">
                <label>Partner Person In Charge</label>
                <input type="text" name="partner_pic" class="form-control">
            </div>

            <div class="mb-3">
                <label>Remarks</label>
                <textarea name="remark" class="form-control"></textarea>
            </div>

            <button type="submit" name="submit" class="btn btn-warning">
                Add Contract
            </button>

            <a href="contracts.php" class="btn btn-secondary">
                Cancel
            </a>

        </form>

    </div>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>