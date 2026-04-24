<?php
global $mysqli;
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

if($_SESSION['role'] !== "Administrator"){
    die("Access Denied");
}

$latestResult = $mysqli->query("
    SELECT log_time FROM activity_logs
    ORDER BY log_time DESC
    LIMIT 1
");

$latestData = $latestResult->fetch_assoc();
$latestTime = $latestData ? $latestData['log_time'] : null;

$formattedTime = $latestTime
    ? date("d M Y, h:i A", strtotime($latestTime))
    : "No activity yet";

$result = $mysqli->query("
    SELECT * FROM activity_logs
    ORDER BY log_time DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Activity Tracking</title>

<link rel="icon" href="../image/logo.png">
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<style>

/* REMOVE SIDE SCROLL */
.table-responsive {
    overflow-x: hidden !important;
}

/* TABLE FIX */
#logsTable {
    width: 100% !important;
    table-layout: fixed; /* IMPORTANT */
}

/* FORCE TEXT WRAP */
#logsTable td {
    white-space: normal !important;
    word-wrap: break-word;
}

/* LIMIT DESCRIPTION WIDTH */
#logsTable td:nth-child(4) {
    max-width: 250px;
}

/* HEADER STYLE */
#logsTable thead {
    background: #4e73df;
    color: white;
}

/* HOVER EFFECT */
#logsTable tbody tr:hover {
    background: #f5f7ff;
    cursor: pointer;
}

/* ===== DATATABLE UI ===== */
#logsTable_wrapper .dt-top,
#logsTable_wrapper .dt-bottom {
    padding: 0 15px;
}

/* Search */
#logsTable_wrapper .dataTables_filter {
    position: relative;
}

#logsTable_wrapper .dataTables_filter input {
    border-radius: 20px;
    border: 1px solid #ddd;
    padding: 6px 14px 6px 35px;
}

#logsTable_wrapper .dataTables_filter::before {
    content: "\f002";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #aaa;
}

/* Pagination */
#logsTable_wrapper .page-item .page-link {
    border-radius: 8px;
    margin: 0 3px;
    border: none;
}

#logsTable_wrapper .page-item.active .page-link {
    background-color: #4e73df;
    color: #fff;
}

</style>

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<h2 style="margin-bottom:20px;">Activity Tracking</h2>

<div class="alert alert-info d-flex justify-content-between align-items-center">
    <div>
        <i class="fa fa-clock"></i>
        <strong>Latest Activity:</strong> <?= $formattedTime ?>
    </div>
</div>

<div class="card shadow-sm">
<div class="card-body p-0">

<div class="table-responsive">

<table id="logsTable" class="table table-striped">

<thead>
<tr>
    <th>Username</th>
    <th>Role</th>
    <th>Action</th>
    <th>Description</th>
    <th>Date & Time</th>
</tr>
</thead>

<tbody>
<?php if($result && $result->num_rows > 0): ?>
<?php while($row = $result->fetch_assoc()): ?>

<tr
data-username="<?= htmlspecialchars($row['username']) ?>"
data-role="<?= htmlspecialchars($row['role']) ?>"
data-action="<?= htmlspecialchars($row['action_type']) ?>"
data-description="<?= htmlspecialchars($row['description']) ?>"
data-time="<?= date("d M Y, h:i A", strtotime($row['log_time'])) ?>"
>
<td><?= $row['username'] ?></td>
<td><?= $row['role'] ?></td>
<td><?= $row['action_type'] ?></td>
<td><?= $row['description'] ?></td>
<td><?= date("d M Y, h:i A", strtotime($row['log_time'])) ?></td>
</tr>

<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="5" class="text-center text-muted">No activity logs found</td>
</tr>
<?php endif; ?>
</tbody>

</table>

</div>
</div>
</div>

</div>

<?php include "layout/footer.php"; ?>

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function(){

let table = $('#logsTable').DataTable({
    pageLength: 10,
    autoWidth: false, // IMPORTANT
    dom:
        "<'dt-top d-flex justify-content-between align-items-center mb-3'<'dt-length'l><'dt-search'f>>" +
        "t" +
        "<'dt-bottom d-flex justify-content-between align-items-center mt-3'<'dt-info'i><'dt-pagination'p>>",
    language: {
        search: "",
        searchPlaceholder: "Search activity..."
    }
});

/* ROW CLICK */
$('#logsTable tbody').on('click', 'tr', function(){

    let row = $(this);
    let description = row.data('description');

    let points = description.split(/[,.;]/);
    let listHTML = "";

    points.forEach(function(item){
        if(item.trim() !== ""){
            listHTML += `<li>${item.trim()}</li>`;
        }
    });

    $('#mUsername').text(row.data('username'));
    $('#mRole').text(row.data('role'));
    $('#mAction').text(row.data('action'));
    $('#mTime').text(row.data('time'));
    $('#mDescription').html(listHTML);

    new bootstrap.Modal(document.getElementById('logModal')).show();
});

});
</script>

<!-- MODAL -->
<div class="modal fade" id="logModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content shadow">

<div class="modal-header">
<h5 class="modal-title">
<i class="fa fa-info-circle"></i> Activity Details
</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<p><strong>Username:</strong> <span id="mUsername"></span></p>
<p><strong>Role:</strong> <span id="mRole"></span></p>
<p><strong>Action:</strong> <span id="mAction"></span></p>
<p><strong>Date & Time:</strong> <span id="mTime"></span></p>

<hr>

<strong>Description:</strong>
<ul id="mDescription"></ul>
</div>

</div>
</div>
</div>

</body>
</html>