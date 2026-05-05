<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/search_helper.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

$canAdd = hasPermission($mysqli, "inventory_add");

$search = "";

if(isset($_GET['search'])){
    $search = trim($_GET['search']);
}

/*
    ✅ SAME STYLE AS ASSET INVENTORY / STOCK OUT
    - No page refresh while searching
    - DataTables pagination
    - Comma search supported
    - No left/right scrollbar
    - Search also includes serial, location, tester, status, date testing
*/
$sql = "
SELECT
    server_name,
    machine_type,
    brand,
    COUNT(*) AS total_qty,
    SUM(CASE WHEN status = 'Okay' THEN 1 ELSE 0 END) AS ok_qty,
    SUM(CASE WHEN status = 'Faulty' THEN 1 ELSE 0 END) AS faulty_qty,
    GROUP_CONCAT(serial_number SEPARATOR ' ') AS serial_numbers,
    GROUP_CONCAT(location SEPARATOR ' ') AS locations,
    GROUP_CONCAT(status SEPARATOR ' ') AS statuses,
    GROUP_CONCAT(tester SEPARATOR ' ') AS testers,
    GROUP_CONCAT(date_testing SEPARATOR ' ') AS testing_dates
FROM server_inventory
GROUP BY server_name, machine_type, brand
ORDER BY server_name ASC
";

$stmt = $mysqli->prepare($sql);

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<title>Server Inventory</title>

<link rel="stylesheet" href="style.css">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>

<style>
html, body{
    overflow-x:hidden !important;
}

.main{
    overflow-x:hidden !important;
    max-width:100%;
}

.table-responsive{
    overflow-x:hidden !important;
    width:100%;
}

#serverInventoryTable{
    width:100% !important;
    table-layout:fixed;
}

#serverInventoryTable th,
#serverInventoryTable td{
    white-space:normal !important;
    word-break:break-word;
    overflow-wrap:anywhere;
    vertical-align:middle;
}

#serverInventoryTable tbody tr{
    cursor:pointer;
}

#serverInventoryTable tbody tr:hover{
    background:#fff3cd !important;
}

#serverInventoryTable_wrapper{
    width:100%;
    overflow-x:hidden !important;
}

#serverInventoryTable_wrapper .row{
    margin-left:0 !important;
    margin-right:0 !important;
}

#serverInventoryTable_wrapper .dataTables_length{
    padding-left:0;
}

#serverInventoryTable_wrapper .dataTables_info{
    padding-top:0;
    font-size:14px;
    color:#6c757d;
}

#serverInventoryTable_wrapper .dataTables_paginate{
    padding-top:0;
}

#serverInventoryTable_wrapper .page-item .page-link{
    border-radius:8px;
    margin:0 3px;
    border:none;
}

#serverInventoryTable_wrapper .page-item.active .page-link{
    background-color:#ffc107;
    color:#000;
}

.server-search-hint{
    font-size:13px;
    color:#6c757d;
    margin-top:-8px;
    margin-bottom:15px;
}

@media(max-width:768px){
    #serverInventoryTable_wrapper .server-bottom-row{
        gap:10px;
    }

    #serverInventoryTable_wrapper .dataTables_info,
    #serverInventoryTable_wrapper .dataTables_paginate{
        text-align:left !important;
        justify-content:flex-start !important;
    }
}
</style>

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">Server Inventory</h2>

<form method="GET" class="mb-2" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveServerSearch"
            name="search"
            class="form-control"
            placeholder="Search server... Example: Dell, Okay"
            value="<?= htmlspecialchars($search) ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i>
        </button>
    </div>
</form>

<?php if($canAdd): ?>
<a href="server_add.php" class="btn btn-warning mb-3">
    <i class="fa fa-plus"></i> Add Server
</a>
<?php endif; ?>

<div class="table-responsive">

<table class="table table-striped table-hover" id="serverInventoryTable">
<thead>
<tr>
    <th>Server Name</th>
    <th>Machine Type</th>
    <th>Brand</th>
    <th>Status</th>
    <th>Quantity</th>
</tr>
</thead>

<tbody>

<?php while($row = $result->fetch_assoc()): ?>

<?php
$statusSearchText = "";

if(($row['ok_qty'] ?? 0) > 0){
    $statusSearchText .= " okay active";
}

if(($row['faulty_qty'] ?? 0) > 0){
    $statusSearchText .= " faulty";
}

$searchText = strtolower(
    ($row['server_name'] ?? '') . ' ' .
    ($row['machine_type'] ?? '') . ' ' .
    ($row['brand'] ?? '') . ' ' .
    ($row['serial_numbers'] ?? '') . ' ' .
    ($row['locations'] ?? '') . ' ' .
    ($row['statuses'] ?? '') . ' ' .
    ($row['testers'] ?? '') . ' ' .
    ($row['testing_dates'] ?? '') . ' ' .
    $statusSearchText . ' ' .
    ($row['total_qty'] ?? '')
);
?>

<tr
data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>"
onclick="viewServer(<?= htmlspecialchars(json_encode($row['server_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>, <?= htmlspecialchars(json_encode($row['machine_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)"
>

<td><?= htmlspecialchars($row['server_name'] ?? ''); ?></td>
<td><?= htmlspecialchars($row['machine_type'] ?? ''); ?></td>
<td><?= htmlspecialchars($row['brand'] ?? ''); ?></td>

<td>
    <?php if($row['ok_qty'] > 0): ?>
        <span class="badge bg-success"><?= htmlspecialchars($row['ok_qty']) ?> Okay</span>
    <?php endif; ?>

    <?php if($row['faulty_qty'] > 0): ?>
        <span class="badge bg-danger"><?= htmlspecialchars($row['faulty_qty']) ?> Faulty</span>
    <?php endif; ?>
</td>

<td><?= htmlspecialchars($row['total_qty']); ?></td>

</tr>

<?php endwhile; ?>

</tbody>
</table>

</div>

</div>

<div class="modal fade" id="serialModal">
<div class="modal-dialog modal-xl">
<div class="modal-content">

<div class="modal-header">
<h5>Server Serial List</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body" id="serialContent"></div>

</div>
</div>
</div>

<div class="modal fade" id="serverRemarkModal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Server Stock Out</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <p id="serverSelectedSerial"></p>

        <textarea id="serverRemarkInput" class="form-control"
            placeholder="Enter stock out reason..."></textarea>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" onclick="submitServerStockOut()">Confirm</button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="serverDetailModal">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5>Server Details</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" id="serverDetailContent"></div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
function viewServer(name, type){
    $.post("../backend/get_server_serials.php",
    {name:name, type:type},
    function(data){
        $("#serialContent").html(data);
        new bootstrap.Modal(document.getElementById('serialModal')).show();
    });
}

let serverSelectedId = 0;

function openServerRemarkModal(id, serial){
    serverSelectedId = id;

    document.getElementById("serverSelectedSerial").innerHTML =
        "Serial: <b>" + serial + "</b>";

    document.getElementById("serverRemarkInput").value = "";

    var modal = new bootstrap.Modal(document.getElementById('serverRemarkModal'));
    modal.show();
}

function submitServerStockOut(){
    let remark = document.getElementById("serverRemarkInput").value;

    $.post("../backend/server_stock_out.php",
    {
        id: serverSelectedId,
        remark: remark
    }, function(data){
        if(data.trim() == "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

function deleteServerDirect(id, serial){
    if(!confirm("Delete server serial " + serial + " permanently?\n\nThis will NOT go to Server Stock Out History.")){
        return;
    }

    $.post("../backend/delete_server_direct.php",
    {
        id: id
    }, function(data){
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

function viewServerDetail(id){
    $.post("../backend/get_server_detail.php",{id:id},function(data){
        $("#serverDetailContent").html(data);
        new bootstrap.Modal(document.getElementById('serverDetailModal')).show();
    });
}
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

<script>
let serverInventoryTable;

/*
    ✅ DATATABLE PAGINATION + LIVE SEARCH
    - No page refresh
    - No left/right scrollbar
    - Info bottom left
    - Page numbers bottom right
    - Comma search supported
*/
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if(settings.nTable.id !== "serverInventoryTable"){
        return true;
    }

    const input = document.getElementById("liveServerSearch");
    const keyword = input ? input.value.toLowerCase().trim() : "";

    if(keyword === ""){
        return true;
    }

    const terms = keyword
        .split(",")
        .map(term => term.trim())
        .filter(term => term !== "");

    const rowNode = settings.aoData[dataIndex].nTr;
    const searchText = rowNode ? (rowNode.getAttribute("data-search") || "") : "";

    for(let i = 0; i < terms.length; i++){
        if(!searchText.includes(terms[i])){
            return false;
        }
    }

    return true;
});

$(document).ready(function(){

    serverInventoryTable = $("#serverInventoryTable").DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        ordering: true,
        searching: true,
        autoWidth: false,
        scrollX: false,
        order: [[0, "asc"]],
        dom:
            "<'row mb-2 align-items-center'<'col-md-6'l>>" +
            "rt" +
            "<'row mt-3 align-items-center server-bottom-row'<'col-md-6'i><'col-md-6 d-flex justify-content-end'p>>",
        language: {
            zeroRecords: "No records found",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ server records"
        },
        columnDefs: [
            { width: "26%", targets: 0 },
            { width: "22%", targets: 1 },
            { width: "18%", targets: 2 },
            { width: "22%", targets: 3 },
            { width: "12%", targets: 4 }
        ]
    });

    $("#liveServerSearch").on("input", function(){
        serverInventoryTable.draw();

        let keyword = this.value.trim();
        let newUrl = "server_inventory.php";

        if(keyword !== ""){
            newUrl += "?search=" + encodeURIComponent(keyword);
        }

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    serverInventoryTable.draw();
});
</script>

</body>
</html>