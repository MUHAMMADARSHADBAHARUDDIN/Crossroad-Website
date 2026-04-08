<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];
$username = $_SESSION['username'];

/* ALLOWED ROLES */
$allowed = [
    "Administrator",
    "User (Project Coordinator)",
    "User (Project Manager)"
];

if(!in_array($role, $allowed)){
    header("Location: contracts.php");
    exit();
}

/* GET ID */
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

/* GET CONTRACT DATA */
$stmt = $mysqli->prepare("SELECT * FROM project_inventory WHERE no = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if(!$row){
    die("Contract not found.");
}

/* CHECK OWNER (FOR PROJECT MANAGER) */
if($role == "User (Project Manager)" && $row['created_by'] != $username){
    die("Access denied. You can only edit your own contract.");
}

/* UPDATE CONTRACT */
if(isset($_POST['update'])){

    $org_name = $_POST['org_name'];
    $contract_name = $_POST['contract_name'];
    $contract_code = $_POST['contract_code'];
    $contract_start = $_POST['contract_start'];
    $contract_end = $_POST['contract_end'];
    $location = $_POST['location'];
    $pic = $_POST['pic'];
    $support = $_POST['support_coverage'];
    $preventive = $_POST['preventive_management'];
    $partner = $_POST['partner'];
    $partner_pic = $_POST['partner_pic'];
    $remark = $_POST['remark'];
    $amount = $_POST['amount'];

    $stmt = $mysqli->prepare("UPDATE project_inventory SET
        name=?,
        contract_name=?,
        contract_code=?,
        contract_start=?,
        contract_end=?,
        location=?,
        pic=?,
        support_coverage=?,
        preventive_management=?,
        partner=?,
        partner_pic=?,
        remark=?,
        amount=?
        WHERE no=?");

    $stmt->bind_param(
        "ssssssssssssdi",
        $org_name,
        $contract_name,
        $contract_code,
        $contract_start,
        $contract_end,
        $location,
        $pic,
        $support,
        $preventive,
        $partner,
        $partner_pic,
        $remark,
        $amount,
        $id
    );

    $stmt->execute();

    /* ACTIVITY LOG */
    require_once "../includes/activity_log.php";

    logActivity(
        $mysqli,
        $_SESSION['username'],
        $_SESSION['role'],
        "UPDATE CONTRACT",
        "Updated contract: $contract_name"
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

<div class="main">

<h2 class="mb-4">Edit Contract</h2>

<div class="">

<form method="POST">

<div class="row">

<!-- LEFT -->
<div class="col-md-6">

<div class="form-floating mb-3">
<input type="text" name="org_name" class="form-control"
value="<?= $row['name']; ?>" required>
<label>Organization Name</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="contract_name" class="form-control"
value="<?= $row['contract_name']; ?>" required>
<label>Project Name</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="contract_code" class="form-control"
value="<?= $row['contract_code']; ?>">
<label>Contract Code</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="location" class="form-control"
value="<?= $row['location']; ?>">
<label>Location</label>
</div>

<div class="form-floating mb-3">
<input type="number" step="0.01" name="amount" class="form-control"
value="<?= $row['amount']; ?>">
<label>Amount (RM)</label>
</div>

</div>

<!-- RIGHT -->
<div class="col-md-6">

<div class="form-floating mb-3">
<input type="date" name="contract_start" class="form-control"
value="<?= $row['contract_start']; ?>">
<label>Start Date</label>
</div>

<div class="form-floating mb-3">
<input type="date" name="contract_end" class="form-control"
value="<?= $row['contract_end']; ?>">
<label>End Date</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="pic" class="form-control"
value="<?= $row['pic']; ?>">
<label>Person In Charge</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="partner" class="form-control"
value="<?= $row['partner']; ?>">
<label>Partner</label>
</div>

</div>

</div>

<!-- FULL WIDTH -->
<div class="form-floating mb-3">
<input type="text" name="support_coverage" class="form-control"
value="<?= $row['support_coverage']; ?>">
<label>Support Coverage</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="preventive_management" class="form-control"
value="<?= $row['preventive_management']; ?>">
<label>Preventive Management</label>
</div>

<div class="form-floating mb-3">
<input type="text" name="partner_pic" class="form-control"
value="<?= $row['partner_pic']; ?>">
<label>Partner PIC</label>
</div>

<div class="form-floating mb-3">
<textarea name="remark" class="form-control" style="height:100px"><?= $row['remark']; ?></textarea>
<label>Status / Remark</label>
</div>

<!-- BUTTON -->
<div class="d-flex justify-content-end gap-2">

<a href="contracts.php" class="btn btn-secondary">Cancel</a>

<button type="submit" name="update" class="btn btn-warning">
Update Contract
</button>

</div>

</form>

</div>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>