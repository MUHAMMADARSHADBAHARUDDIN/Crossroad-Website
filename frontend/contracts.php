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

/* ✅ CENTRAL PERMISSION FUNCTION */
function canManageContract($role, $username, $owner){
    return (
        $role === "Administrator" ||
        $role === "User (Project Coordinator)" ||
        ($role === "User (Project Manager)" && $username === $owner)
    );
}

$sql = "
SELECT *
FROM project_inventory
WHERE
    project_name LIKE '%$search%' OR
    contract_no LIKE '%$search%' OR
    year_awarded LIKE '%$search%' OR
    project_owner LIKE '%$search%' OR
    end_user LIKE '%$search%'
ORDER BY no DESC
";

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

<h2 class="mb-3">Contracts</h2>

<form method="GET" class="mb-3">
    <div class="input-group">
        <input type="text" name="search" class="form-control"
               placeholder="Search contracts..." value="<?= $search ?>">

        <button class="btn btn-warning">
            <i class="fa fa-search"></i> Search
        </button>

        <?php if($search): ?>
        <a href="contracts.php" class="btn btn-secondary">
            Reset
        </a>
        <?php endif; ?>
    </div>
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

<?php while($row = $result->fetch_assoc()):

$today = new DateTime();
$endDate = !empty($row['contract_end']) ? new DateTime($row['contract_end']) : null;

$auto_status = "Active";

if($endDate){
    $diff = $today->diff($endDate)->days;
    $isPast = $endDate < $today;

    if($isPast){
        $auto_status = "Closed";
    } elseif($diff <= 30){
        $auto_status = "Expiring Soon";
    }
}

/* SEARCH FILTER (STATUS INCLUDED) */
if($search != ""){
    $match =
        stripos($row['project_name'], $search) !== false ||
        stripos($row['contract_no'], $search) !== false ||
        stripos($row['year_awarded'], $search) !== false ||
        stripos($row['project_owner'], $search) !== false ||
        stripos($row['end_user'], $search) !== false ||
        stripos($auto_status, $search) !== false;

    if(!$match){
        continue;
    }
}

$owner = $row['project_owner'];

?>

<tr
data-id="<?= $row['no']; ?>"
data-year="<?= $row['year_awarded']; ?>"
data-project="<?= htmlspecialchars($row['project_name']); ?>"
data-owner="<?= htmlspecialchars($row['project_owner']); ?>"
data-enduser="<?= htmlspecialchars($row['end_user']); ?>"
data-contractno="<?= htmlspecialchars($row['contract_no']); ?>"
data-service="<?= htmlspecialchars($row['service']); ?>"
data-podate="<?= $row['po_date']; ?>"
data-start="<?= $row['contract_start']; ?>"
data-end="<?= $row['contract_end']; ?>"
data-status="<?= $auto_status; ?>"
data-amount="<?= $row['amount']; ?>"
data-role="<?= $role; ?>"
data-username="<?= $username; ?>"
>

<td><?= $row['no']; ?></td>
<td><?= $row['year_awarded']; ?></td>
<td><?= $row['project_name']; ?></td>
<td><?= $row['project_owner']; ?></td>

<td>
<?php if($auto_status == "Closed"): ?>
    <span class="badge bg-danger">Closed</span>
<?php elseif($auto_status == "Expiring Soon"): ?>
    <span class="badge bg-warning text-dark">Expiring Soon</span>
<?php else: ?>
    <span class="badge bg-success">Active</span>
<?php endif; ?>
</td>

<td><?= $row['contract_start']; ?></td>
<td><?= $row['contract_end']; ?></td>
<td>RM <?= number_format($row['amount'],2); ?></td>

<td>

<?php if(canManageContract($role, $username, $owner)): ?>

    <a href="contract_edit.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-primary">
        Edit
    </a>

    <a href="../backend/contract_delete.php?id=<?= $row['no']; ?>"
       class="btn btn-sm btn-danger"
       onclick="return confirm('Delete this contract?')">
       Delete
    </a>

<?php else: ?>

    <span class="badge bg-secondary">View Only</span>

<?php endif; ?>

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

<div class="modal-header bg-primary text-white">
    <h5 class="modal-title">
        <i class="fa fa-file-contract"></i> Contract Details
    </h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-4">

<h4 id="m_project"></h4>
<small id="m_owner"></small>

<hr>

<div class="row g-3">

<div class="col-md-6"><b>Year</b><div id="m_year"></div></div>
<div class="col-md-6"><b>End User</b><div id="m_enduser"></div></div>
<div class="col-md-6"><b>Contract No</b><div id="m_contractno"></div></div>
<div class="col-md-6"><b>Service</b><div id="m_service"></div></div>
<div class="col-md-6"><b>PO Date</b><div id="m_podate"></div></div>
<div class="col-md-6"><b>Start</b><div id="m_start"></div></div>
<div class="col-md-6"><b>End</b><div id="m_end"></div></div>
<div class="col-md-6"><b>Status</b><div id="m_status"></div></div>
<div class="col-md-6"><b>Amount</b><div id="m_amount"></div></div>

</div>

<hr>

<h6><i class="fa fa-paperclip"></i> Attachments</h6>
<div id="filesContainer">Loading...</div>

<div id="uploadSection" class="mt-3">
<form action="../backend/upload_contract.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="contract_id" id="m_id">
    <div class="input-group">
        <input type="file" name="file" class="form-control">
        <button class="btn btn-warning">Upload</button>
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
    pageLength: 10,
    searching: false
});

$('#contractsTable tbody').on('click','tr',function(e){

    if($(e.target).closest('a,button').length) return;

    let row = $(this);
    let id = row.data('id');

    $('#m_id').val(id);

    $('#m_project').text(row.data('project'));
    $('#m_owner').text(row.data('owner'));
    $('#m_year').text(row.data('year'));
    $('#m_enduser').text(row.data('enduser'));
    $('#m_contractno').text(row.data('contractno'));
    $('#m_service').text(row.data('service'));
    $('#m_podate').text(row.data('podate'));
    $('#m_start').text(row.data('start'));
    $('#m_end').text(row.data('end'));

    let status = row.data('status');

    if(status === "Closed"){
        $('#m_status').html('<span class="badge bg-danger">Closed</span>');
    }
    else if(status === "Expiring Soon"){
        $('#m_status').html('<span class="badge bg-warning text-dark">Expiring Soon</span>');
    }
    else{
        $('#m_status').html('<span class="badge bg-success">Active</span>');
    }

    let amount = parseFloat(row.data('amount'));

    if(!isNaN(amount)){
        $('#m_amount').text(
            "RM " + amount.toLocaleString('en-MY', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            })
        );
    } else {
        $('#m_amount').text("RM 0.00");
    }

    // ROLE CONTROL (UPLOAD / VIEW)
    let role = row.data('role');
    let username = row.data('username');
    let owner = row.data('owner');

    let canUpload = false;

    if(role === "Administrator" || role === "User (Project Coordinator)"){
        canUpload = true;
    }
    else if(role === "User (Project Manager)" && username === owner){
        canUpload = true;
    }

    $('#uploadSection').toggle(canUpload);

    $('#filesContainer').html("Loading...");
    $.post("../backend/get_contract_files.php",{id:id},function(data){
        $('#filesContainer').html(data);
    });

    new bootstrap.Modal(document.getElementById('contractModal')).show();

});

});
</script>

</body>
</html>