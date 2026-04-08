<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];
$username = $_SESSION['username'];

$search = "";
if(isset($_GET['search'])){
    $search = $mysqli->real_escape_string($_GET['search']);
}

$sql = "SELECT * FROM project_inventory
WHERE name LIKE '%$search%'
OR contract_name LIKE '%$search%'";

$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Contracts</title>

<link rel="icon" href="../image/logo.png">
<link rel="stylesheet" href="style.css">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-3">Contracts</h2>

<form method="GET" class="mb-3">
    <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= $search ?>">
</form>

<?php if(
    $role == "Administrator" ||
    $role == "User (Project Coordinator)" ||
    $role == "User (Project Manager)"
): ?>
<a href="contract_add.php" class="btn btn-warning mb-3">
    <i class="fa fa-plus"></i> Add Contract
</a>
<?php endif; ?>

<table id="contractsTable" class="table table-hover table-striped">

<thead>
<tr>
    <th>No</th>
    <th>Year</th>
    <th>Project Name</th>
    <th>Owner</th>
    <th>Status</th>
    <th>Start</th>
    <th>End</th>
    <th>Amount</th>
    <th>Actions</th>
</tr>
</thead>

<tbody>

<?php while($row = $result->fetch_assoc()): ?>
<tr
data-id="<?= $row['no']; ?>"
data-name="<?= htmlspecialchars($row['name']); ?>"
data-contract="<?= htmlspecialchars($row['contract_name']); ?>"
data-code="<?= $row['contract_code']; ?>"
data-location="<?= htmlspecialchars($row['location']); ?>"
data-pic="<?= htmlspecialchars($row['pic']); ?>"
data-support="<?= htmlspecialchars($row['support_coverage']); ?>"
data-preventive="<?= htmlspecialchars($row['preventive_management']); ?>"
data-partner="<?= htmlspecialchars($row['partner']); ?>"
data-partner_pic="<?= htmlspecialchars($row['partner_pic']); ?>"
data-remark="<?= htmlspecialchars($row['remark']); ?>"
data-owner="<?= $row['created_by']; ?>"
data-role="<?= $role; ?>"
data-username="<?= $username; ?>"
>

<td><?= $row['no']; ?></td>
<td><?= date('Y', strtotime($row['contract_start'])); ?></td>
<td><?= $row['contract_name']; ?></td>
<td><?= $row['created_by']; ?></td>
<td><?= $row['remark']; ?></td>
<td><?= $row['contract_start']; ?></td>
<td><?= $row['contract_end']; ?></td>
<td>RM <?= $row['amount']; ?></td>

<td>
<?php
$owner = $row['created_by'];

if($role == "Administrator" || $role == "User (Project Coordinator)"){
?>
<a href="contract_edit.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-primary">Edit</a>
<a href="../backend/contract_delete.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this contract?')">Delete</a>
<?php
}
elseif(($role == "User (Project Manager)" || $role == "User (Technical)") && $username == $owner){
?>
<a href="contract_edit.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-primary">Edit</a>
<a href="../backend/contract_delete.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this contract?')">Delete</a>
<?php } ?>
</td>

</tr>
<?php endwhile; ?>

</tbody>
</table>

</div>

<!-- MODAL -->
<div class="modal fade" id="contractModal">
<div class="modal-dialog modal-lg">
<div class="modal-content border-0 shadow-lg rounded-4">

<div class="modal-header bg-dark text-white rounded-top-4">
    <h5 class="modal-title">
        <i class="fa fa-file-contract"></i> Contract Details
    </h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-4">

<!-- TOP SUMMARY -->
<div class="mb-4">
    <h4 id="m_contract" class="fw-bold mb-1"></h4>
    <small class="text-muted">
        <i class="fa fa-building"></i> <span id="m_name"></span>
    </small>
</div>

<!-- GRID DETAILS -->
<div class="row g-3">

<div class="col-md-6">
    <div class="card p-3 h-100 shadow-sm border-0">
        <small class="text-muted">Contract Code</small>
        <div id="m_code"></div>
    </div>
</div>

<div class="col-md-6">
    <div class="card p-3 h-100 shadow-sm border-0">
        <small class="text-muted">Location</small>
        <div id="m_location"></div>
    </div>
</div>

<div class="col-md-6">
    <div class="card p-3 h-100 shadow-sm border-0">
        <small class="text-muted">Person In Charge</small>
        <div id="m_pic"></div>
    </div>
</div>

<div class="col-md-6">
    <div class="card p-3 h-100 shadow-sm border-0">
        <small class="text-muted">Partner</small>
        <div id="m_partner"></div>
    </div>
</div>

<div class="col-md-6">
    <div class="card p-3 h-100 shadow-sm border-0">
        <small class="text-muted">Support Coverage</small>
        <div id="m_support"></div>
    </div>
</div>

<div class="col-md-6">
    <div class="card p-3 h-100 shadow-sm border-0">
        <small class="text-muted">Preventive Management</small>
        <div id="m_preventive"></div>
    </div>
</div>

<div class="col-md-6">
    <div class="card p-3 h-100 shadow-sm border-0">
        <small class="text-muted">Partner PIC</small>
        <div id="m_partner_pic"></div>
    </div>
</div>

<div class="col-md-6">
    <div class="card p-3 h-100 shadow-sm border-0">
        <small class="text-muted">Status</small>
        <span id="m_remark" class="badge bg-info text-dark px-3 py-2"></span>
    </div>
</div>

</div>

<!-- ATTACHMENTS -->
<hr class="my-4">

<h6 class="fw-bold mb-2">
    <i class="fa fa-paperclip"></i> Attachments
</h6>

<div id="filesContainer" class="mb-3 text-muted">
    Loading...
</div>

<!-- UPLOAD -->
<div id="uploadSection" class="mt-3">

<form action="../backend/upload_contract.php" method="POST" enctype="multipart/form-data">

    <input type="hidden" name="contract_id" id="m_id">

    <div class="input-group">
        <input type="file" name="file" class="form-control">
        <button class="btn btn-warning">
            <i class="fa fa-upload"></i> Upload
        </button>
    </div>

</form>

</div>

</div>

</div>
</div>
</div>

<?php include "layout/footer.php"; ?>

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function(){

$('#contractsTable').DataTable({
    pageLength: 10
});

$('#contractsTable tbody').on('click','tr',function(e){

if($(e.target).closest('a,button').length) return;

let row = $(this);
let id = row.data('id');

$('#m_id').val(id);

// BASIC
$('#m_name').text(row.data('name'));
$('#m_contract').text(row.data('contract'));
$('#m_code').text(row.data('code'));

// FULL
$('#m_location').text(row.data('location'));
$('#m_pic').text(row.data('pic'));
$('#m_support').text(row.data('support'));
$('#m_preventive').text(row.data('preventive'));
$('#m_partner').text(row.data('partner'));
$('#m_partner_pic').text(row.data('partner_pic'));
$('#m_remark').text(row.data('remark'));

// ROLE CONTROL
let owner = row.data('owner');
let role = row.data('role');
let username = row.data('username');

let canUpload = false;

if(role === "Administrator" || role === "User (Project Coordinator)"){
    canUpload = true;
}
else if(role === "User (Project Manager)" && username === owner){
    canUpload = true;
}

if(canUpload){
    $('#uploadSection').show();
}else{
    $('#uploadSection').hide();
}

// LOAD FILES
$('#filesContainer').html("Loading...");
$.post("../backend/get_contract_files.php",{id:id},function(data){
    $('#filesContainer').html(data);
});

let modal = new bootstrap.Modal(document.getElementById('contractModal'));
modal.show();

});

});
</script>

</body>
</html>