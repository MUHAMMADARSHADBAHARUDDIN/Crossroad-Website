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

$id = $_GET['id'];

/* GET CONTRACT DATA */

$result = $mysqli->query("SELECT * FROM project_inventory WHERE no=$id");
$row = $result->fetch_assoc();

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

    <div class="card p-4 shadow-sm">

        <form method="POST">

            <div class="mb-3">
                <label>Organization Name</label>
                <input type="text" name="name" class="form-control"
                       value="<?php echo $row['name']; ?>" required>
            </div>

            <div class="mb-3">
                <label>Contract Name</label>
                <input type="text" name="contract_name" class="form-control"
                       value="<?php echo $row['contract_name']; ?>" required>
            </div>

            <div class="mb-3">
                <label>Contract Code</label>
                <input type="text" name="contract_code" class="form-control"
                       value="<?php echo $row['contract_code']; ?>">
            </div>

            <div class="mb-3">
                <label>Contract Start</label>
                <input type="date" name="start" class="form-control"
                       value="<?php echo $row['contract_start']; ?>">
            </div>

            <div class="mb-3">
                <label>Contract End</label>
                <input type="date" name="end" class="form-control"
                       value="<?php echo $row['contract_end']; ?>">
            </div>

            <div class="mb-3">
                <label>Location</label>
                <input type="text" name="location" class="form-control"
                       value="<?php echo $row['location']; ?>">
            </div>

            <div class="mb-3">
                <label>Person In Charge</label>
                <input type="text" name="pic" class="form-control"
                       value="<?php echo $row['pic']; ?>">
            </div>

            <div class="mb-3">
                <label>Support Coverage</label>
                <input type="text" name="support" class="form-control"
                       value="<?php echo $row['support_coverage']; ?>">
            </div>

            <div class="mb-3">
                <label>Preventive Management</label>
                <input type="text" name="preventive" class="form-control"
                       value="<?php echo $row['preventive_management']; ?>">
            </div>

            <div class="mb-3">
                <label>Partner</label>
                <input type="text" name="partner" class="form-control"
                       value="<?php echo $row['partner']; ?>">
            </div>

            <div class="mb-3">
                <label>Partner PIC</label>
                <input type="text" name="partner_pic" class="form-control"
                       value="<?php echo $row['partner_pic']; ?>">
            </div>

            <div class="mb-3">
                <label>Remarks</label>
                <textarea name="remark" class="form-control"><?php echo $row['remark']; ?></textarea>
            </div>

            <button type="submit" name="update" class="btn btn-warning">
                Update Contract
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