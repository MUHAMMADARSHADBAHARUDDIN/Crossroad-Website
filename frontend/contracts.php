<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!hasContractViewAccess($mysqli)){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

$canAddContract = hasContractAddAccess($mysqli);

$search = "";
if(isset($_GET['search'])){
    $search = trim($_GET['search']);
}

$searchLike = "%" . $search . "%";

$stmt = $mysqli->prepare("
SELECT *
FROM project_inventory
WHERE
    project_name LIKE ? OR
    contract_no LIKE ? OR
    year_awarded LIKE ? OR
    project_owner LIKE ? OR
    end_user LIKE ?
ORDER BY no DESC
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("sssss", $searchLike, $searchLike, $searchLike, $searchLike, $searchLike);
$stmt->execute();
$result = $stmt->get_result();
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
               placeholder="Search contracts..." value="<?= htmlspecialchars($search) ?>">

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

<?php if($canAddContract): ?>
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
$endDate = !empty($row['contract_end']) && $row['contract_end'] !== "0000-00-00" ? new DateTime($row['contract_end']) : null;

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

if($search != ""){
    $match =
        stripos($row['project_name'] ?? '', $search) !== false ||
        stripos($row['contract_no'] ?? '', $search) !== false ||
        stripos((string)$row['year_awarded'], $search) !== false ||
        stripos($row['project_owner'] ?? '', $search) !== false ||
        stripos($row['end_user'] ?? '', $search) !== false ||
        stripos($auto_status, $search) !== false;

    if(!$match){
        continue;
    }
}

$created_by = $row['created_by'] ?? "";

$canEditThisContract = hasContractEditAccess($mysqli, $created_by);
$canDeleteThisContract = hasContractDeleteAccess($mysqli, $created_by);
$canUploadThisContract = hasContractUploadAccess($mysqli, $created_by);

?>

<tr
data-id="<?= htmlspecialchars($row['no']); ?>"
data-year="<?= htmlspecialchars($row['year_awarded']); ?>"
data-project="<?= htmlspecialchars($row['project_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
data-owner="<?= htmlspecialchars($row['project_owner'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
data-createdby="<?= htmlspecialchars($created_by, ENT_QUOTES, 'UTF-8'); ?>"
data-canupload="<?= $canUploadThisContract ? '1' : '0'; ?>"
data-enduser="<?= htmlspecialchars($row['end_user'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
data-contractno="<?= htmlspecialchars($row['contract_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
data-service="<?= htmlspecialchars($row['service'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
data-podate="<?= htmlspecialchars($row['po_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
data-start="<?= htmlspecialchars($row['contract_start'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
data-end="<?= htmlspecialchars($row['contract_end'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
data-status="<?= htmlspecialchars($auto_status, ENT_QUOTES, 'UTF-8'); ?>"
data-amount="<?= htmlspecialchars($row['amount'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>"
>

<td><?= htmlspecialchars($row['no']); ?></td>
<td><?= htmlspecialchars($row['year_awarded']); ?></td>
<td><?= htmlspecialchars($row['project_name'] ?? ''); ?></td>
<td><?= htmlspecialchars($row['project_owner'] ?? ''); ?></td>

<td>
<?php if($auto_status == "Closed"): ?>
    <span class="badge bg-danger">Closed</span>
<?php elseif($auto_status == "Expiring Soon"): ?>
    <span class="badge bg-warning text-dark">Expiring Soon</span>
<?php else: ?>
    <span class="badge bg-success">Active</span>
<?php endif; ?>
</td>

<td><?= htmlspecialchars($row['contract_start'] ?? ''); ?></td>
<td><?= htmlspecialchars($row['contract_end'] ?? ''); ?></td>
<td>RM <?= number_format((float)$row['amount'],2); ?></td>

<td>

<?php if($canEditThisContract): ?>
    <a href="contract_edit.php?id=<?= $row['no']; ?>" class="btn btn-sm btn-primary">
        Edit
    </a>
<?php endif; ?>

<?php if($canDeleteThisContract): ?>
    <a href="../backend/contract_delete.php?id=<?= $row['no']; ?>"
       class="btn btn-sm btn-danger"
       onclick="return confirm('Delete this contract?')">
       Delete
    </a>
<?php endif; ?>

<?php if(!$canEditThisContract && !$canDeleteThisContract): ?>
    <span class="badge bg-secondary">View Only</span>
<?php endif; ?>

</td>

</tr>

<?php endwhile; ?>

</tbody>
</table>

</div>

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
<div class="col-md-6"><b>Created By</b><div id="m_createdby"></div></div>

</div>

<hr>

<h6><i class="fa fa-paperclip"></i> Attachments</h6>
<div id="filesContainer">Loading...</div>

<div id="uploadSection" class="mt-3">
<form action="../backend/upload_contract.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="contract_id" id="m_id">
    <div class="input-group">
        <input type="file" name="file" class="form-control" required>
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
    $('#m_createdby').text(row.data('createdby') || '-');
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

    let canUploadThisContract = row.data('canupload') == 1;
    $('#uploadSection').toggle(canUploadThisContract);

    $('#filesContainer').html("Loading...");
    $.post("../backend/get_contract_files.php",{id:id},function(data){
        $('#filesContainer').html(data);
    });

    new bootstrap.Modal(document.getElementById('contractModal')).show();

});

});
</script>

<script>
function toggleSidebar(){
    const sidebar = document.getElementById("sidebar");
    const main = document.querySelector(".main");
    const btn = document.getElementById("menuBtn");

    sidebar.classList.toggle("collapsed");
    main.classList.toggle("expanded");
    btn.classList.toggle("active");
}
</script>
</body>
</html>