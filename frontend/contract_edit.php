<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

$role = $_SESSION['role'];
$username = $_SESSION['username'];

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

/* FETCH DATA */
$stmt = $mysqli->prepare("SELECT * FROM project_inventory WHERE no = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if(!$row){
    die("Contract not found.");
}

/* OWNER CHECK */
if($role == "User (Project Manager)" && $row['created_by'] !== $username){
    header("Location: contracts.php");
    exit();
}

/* UPDATE */
if(isset($_POST['update'])){

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

    $stmt = $mysqli->prepare("
        UPDATE project_inventory SET
        year_awarded=?,
        project_name=?,
        project_owner=?,
        end_user=?,
        contract_no=?,
        service=?,
        po_date=?,
        contract_start=?,
        contract_end=?,
        amount=?
        WHERE no=?
    ");

    $stmt->bind_param(
        "issssssssdi",
        $year_awarded,
        $project_name,
        $project_owner,
        $end_user,
        $contract_no,
        $service,
        $po_date,
        $contract_start,
        $contract_end,
        $amount,
        $id
    );

$stmt->execute();

/* 🔥 PUT YOUR LOG HERE (RIGHT AFTER SUCCESSFUL UPDATE) */

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

IP Address: $ip
Time: $time";

logActivity(
    $mysqli,
    $username,
    $role,
    "UPDATE CONTRACT",
    $description
);

/* THEN REDIRECT */
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

<div class="main">

<h2 class="mb-4">Edit Contract</h2>

<form method="POST" class="form-card">

<div class="row g-3">

<!-- LEFT -->
<div class="col-md-6">

<div class="form-floating">
<input type="number" name="year_awarded" class="form-control"
value="<?= $row['year_awarded']; ?>" required>
<label>Year Awarded</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="project_name" class="form-control"
value="<?= $row['project_name']; ?>" required>
<label>Project Name</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="project_owner" class="form-control"
value="<?= $row['project_owner']; ?>">
<label>Project Owner</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="end_user" class="form-control"
value="<?= $row['end_user']; ?>">
<label>End User</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="contract_no" class="form-control"
value="<?= $row['contract_no']; ?>">
<label>Contract No</label>
</div>

<div class="form-floating mt-3">
<input type="text" name="service" class="form-control"
value="<?= $row['service']; ?>">
<label>Service</label>
</div>

</div>

<!-- RIGHT -->
<div class="col-md-6">

<div class="form-floating">
<input type="date" name="po_date" class="form-control"
value="<?= $row['po_date']; ?>">
<label>PO Date</label>
</div>

<div class="form-floating mt-3">
<input type="date" name="contract_start" class="form-control"
value="<?= $row['contract_start']; ?>">
<label>Start Date</label>
</div>

<div class="form-floating mt-3">
<input type="date" name="contract_end" class="form-control"
value="<?= $row['contract_end']; ?>">
<label>End Date</label>
</div>

<div class="form-floating mt-3">
<input type="number" step="0.01" name="amount" class="form-control"
value="<?= $row['amount']; ?>">
<label>Amount (RM)</label>
</div>

</div>

</div>

<!-- BUTTON -->
<div class="d-flex justify-content-end gap-2 mt-4">

<a href="contracts.php" class="btn btn-light px-4">Cancel</a>

<button type="submit" name="update" class="btn btn-warning px-4">
<i class="fa fa-save"></i> Update
</button>

</div>

</form>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>