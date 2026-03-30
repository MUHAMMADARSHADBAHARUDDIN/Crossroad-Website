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

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2>Contracts</h2>

<form method="GET" class="mb-3">
    <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= $search ?>">
</form>

<div class="card">
<div class="card-body">

<table id="contractsTable" class="table table-hover">

<thead>
<tr>
    <th>Organization</th>
    <th>Contract</th>
    <th>Code</th>
    <th>Start</th>
    <th>End</th>
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
data-owner="<?= $row['created_by']; ?>"
data-role="<?= $role; ?>"
data-username="<?= $username; ?>">

<td><?= $row['name']; ?></td>
<td><?= $row['contract_name']; ?></td>
<td><?= $row['contract_code']; ?></td>
<td><?= $row['contract_start']; ?></td>
<td><?= $row['contract_end']; ?></td>

<td>
<?php
$owner = $row['created_by'];

if($role == "Administrator" || $role == "User (Project Coordinator)"){
?>
<a href="contract_edit.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-primary">Edit</a>
<a href="../backend/contract_delete.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-danger">Delete</a>
<?php
}
elseif(($role == "User (Project Manager)" || $role == "User (Technical)") && $username == $owner){
?>
<a href="contract_edit.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-primary">Edit</a>
<a href="../backend/contract_delete.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-danger">Delete</a>
<?php } ?>
</td>

</tr>

<?php endwhile; ?>

</tbody>
</table>

</div>
</div>

</div>

<!-- MODAL -->
<div class="modal fade" id="contractModal">
<div class="modal-dialog">
<div class="modal-content">

<div class="modal-header">
<h5>Contract Details</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<p><b>Organization:</b> <span id="m_name"></span></p>
<p><b>Contract:</b> <span id="m_contract"></span></p>
<p><b>Code:</b> <span id="m_code"></span></p>

<hr>

<h6>Attachments</h6>
<div id="filesContainer">No files</div>

<hr>

<div id="uploadSection">
<form action="../backend/upload_contract.php" method="POST" enctype="multipart/form-data">
<input type="hidden" name="contract_id" id="m_id">
<input type="file" name="file" class="form-control mb-2">
<button class="btn btn-warning">Upload</button>
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

<script>
$(document).ready(function(){

$('#contractsTable').DataTable();

$('#contractsTable tbody').on('click','tr',function(e){

if($(e.target).closest('a,button').length){
return;
}

let row = $(this);

let id = row.data('id');
let name = row.data('name');
let contract = row.data('contract');
let code = row.data('code');
let owner = row.data('owner');
let role = row.data('role');
let username = row.data('username');

$('#m_id').val(id);
$('#m_name').text(name);
$('#m_contract').text(contract);
$('#m_code').text(code);

// ROLE RULE
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