<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];

$id = $_GET['id'];

/* GET CONTRACT DATA */

$result = $mysqli->query("SELECT * FROM project_inventory WHERE no=$id");
$row = $result->fetch_assoc();

$owner = $row['created_by'];
$username = $_SESSION['username'];

if(
    $role != "Administrator" &&
    $role != "User (Project Coordinator)" &&
    !(
        ($role == "User (Project Manager)")
        && $username == $owner
    )
){
    header("Location: contracts.php");
    exit();
}
/* UPDATE CONTRACT */

if(isset($_POST['update'])){

    $name = $_POST['name'];
    $contract_name = $_POST['contract_name'];
    $contract_code = $_POST['contract_code'];
    $start = $_POST['start'];
    $end = $_POST['end'];
    $location = $_POST['location'];
    $pic = $_POST['pic'];
    $support = $_POST['support'];
    $preventive = $_POST['preventive'];
    $partner = $_POST['partner'];
    $partner_pic = $_POST['partner_pic'];
    $remark = $_POST['remark'];

    $sql = "UPDATE project_inventory SET
    name='$name',
    contract_name='$contract_name',
    contract_code='$contract_code',
    contract_start='$start',
    contract_end='$end',
    location='$location',
    pic='$pic',
    support_coverage='$support',
    preventive_management='$preventive',
    partner='$partner',
    partner_pic='$partner_pic',
    remark='$remark'
    WHERE no=$id";

    $mysqli->query($sql);

require_once "../includes/activity_log.php";

logActivity(
    $mysqli,
    $_SESSION['username'],
    $_SESSION['role'],
    "UPDATE CONTRACT",
    "Updated contract: $contract_name (ID: $id)"
);
    header("Location: contracts.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>

    <title>Edit Contract</title>

    <link rel="stylesheet" href="style.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

    <h2 class="mb-4">Edit Contract</h2>

    <div class="">

<form method="POST" class="modern-form">

<div class="row">

    <!-- LEFT COLUMN -->
    <div class="col-md-6">

        <div class="form-floating mb-3">
            <input type="text" name="name" class="form-control" placeholder="Organization" value="<?= $row['name'] ?? '' ?>" required>
            <label>Organization Name</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="contract_name" class="form-control" placeholder="Contract Name" value="<?= $row['contract_name'] ?? '' ?>" required>
            <label>Contract Name</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="contract_code" class="form-control" placeholder="Code" value="<?= $row['contract_code'] ?? '' ?>">
            <label>Contract Code</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="location" class="form-control" placeholder="Location" value="<?= $row['location'] ?? '' ?>">
            <label>Location</label>
        </div>

    </div>

    <!-- RIGHT COLUMN -->
    <div class="col-md-6">

        <div class="form-floating mb-3">
            <input type="date" name="start" class="form-control" value="<?= $row['contract_start'] ?? '' ?>">
            <label>Start Date</label>
        </div>

        <div class="form-floating mb-3">
            <input type="date" name="end" class="form-control" value="<?= $row['contract_end'] ?? '' ?>">
            <label>End Date</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="pic" class="form-control" placeholder="PIC" value="<?= $row['pic'] ?? '' ?>">
            <label>Person In Charge</label>
        </div>

        <div class="form-floating mb-3">
            <input type="text" name="partner" class="form-control" placeholder="Partner" value="<?= $row['partner'] ?? '' ?>">
            <label>Partner</label>
        </div>

    </div>

</div>

<!-- FULL WIDTH -->
<div class="form-floating mb-3">
    <input type="text" name="support" class="form-control" placeholder="Support" value="<?= $row['support_coverage'] ?? '' ?>">
    <label>Support Coverage</label>
</div>

<div class="form-floating mb-3">
    <input type="text" name="preventive" class="form-control" placeholder="Preventive" value="<?= $row['preventive_management'] ?? '' ?>">
    <label>Preventive Management</label>
</div>

<div class="form-floating mb-3">
    <input type="text" name="partner_pic" class="form-control" placeholder="Partner PIC" value="<?= $row['partner_pic'] ?? '' ?>">
    <label>Partner PIC</label>
</div>

<div class="form-floating mb-3">
    <textarea name="remark" class="form-control" style="height:100px"><?= $row['remark'] ?? '' ?></textarea>
    <label>Remarks</label>
</div>

<!-- BUTTONS -->
<div class="d-flex justify-content-end gap-2 mt-3">

    <a href="contracts.php" class="btn btn-light px-4">
        Cancel
    </a>

    <button type="submit" name="update" class="btn btn-warning px-4">
        Save
    </button>

</div>

</form>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>