<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/search_helper.php";

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
    ✅ TABLE PAGINATION FIX
    - DataTables handles next page / previous page
    - Live search works without refresh
    - Comma search supported: dell, storage
*/

$mysqli->query("SET SESSION group_concat_max_len = 100000");

$stmt = $mysqli->prepare("
SELECT
    part_number,
    brand,
    description,
    MAX(date_received) AS date_received,
    COUNT(*) AS total_qty,
    MIN(created_by) AS created_by,
    GROUP_CONCAT(serial_number SEPARATOR ' ') AS serial_numbers
FROM asset_inventory
GROUP BY part_number, brand, description
ORDER BY part_number ASC
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<title>Asset Inventory</title>

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

#assetInventoryTable{
    width:100% !important;
    table-layout:fixed;
}

#assetInventoryTable th,
#assetInventoryTable td{
    white-space:normal !important;
    word-break:break-word;
    overflow-wrap:anywhere;
    vertical-align:middle;
}

#assetInventoryTable tbody tr{
    cursor:pointer;
}

#assetInventoryTable tbody tr:hover{
    background:#fff3cd !important;
}

#assetInventoryTable_wrapper{
    width:100%;
    overflow-x:hidden !important;
}

#assetInventoryTable_wrapper .row{
    margin-left:0 !important;
    margin-right:0 !important;
}

#assetInventoryTable_wrapper .dataTables_length{
    padding-left:0;
}

#assetInventoryTable_wrapper .dataTables_info{
    padding-top:0;
    font-size:14px;
    color:#6c757d;
}

#assetInventoryTable_wrapper .dataTables_paginate{
    padding-top:0;
}

#assetInventoryTable_wrapper .page-item .page-link{
    border-radius:8px;
    margin:0 3px;
    border:none;
}

#assetInventoryTable_wrapper .page-item.active .page-link{
    background-color:#ffc107;
    color:#000;
}

.asset-search-hint{
    font-size:13px;
    color:#6c757d;
    margin-top:-8px;
    margin-bottom:15px;
}

@media(max-width:768px){
    #assetInventoryTable_wrapper .asset-bottom-row{
        gap:10px;
    }

    #assetInventoryTable_wrapper .dataTables_info,
    #assetInventoryTable_wrapper .dataTables_paginate{
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

<h2 class="mb-4">Asset Inventory</h2>

<form method="GET" class="mb-2" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveAssetSearch"
            name="search"
            class="form-control"
            placeholder="Search... Example: dell, storage"
            value="<?php echo htmlspecialchars($search); ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i>
        </button>
    </div>
</form>

<?php if($canAdd): ?>
<a href="asset_add.php" class="btn btn-warning mb-3">
    <i class="fa fa-plus"></i> Stock Receive
</a>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-striped table-hover" id="assetInventoryTable">
<thead>
<tr>
    <th>Part Number</th>
    <th>Brand</th>
    <th>Description</th>
    <th>Quantity</th>
</tr>
</thead>

<tbody>

<?php while($row = $result->fetch_assoc()): ?>

<?php
$searchText = strtolower(
    ($row['part_number'] ?? '') . ' ' .
    ($row['brand'] ?? '') . ' ' .
    ($row['description'] ?? '') . ' ' .
    ($row['serial_numbers'] ?? '') . ' ' .
    ($row['created_by'] ?? '') . ' ' .
    ($row['date_received'] ?? '') . ' ' .
    ($row['total_qty'] ?? '')
);
?>

<tr
data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>"
onclick="viewSerial(<?= htmlspecialchars(json_encode($row['part_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)"
>

<td><?php echo htmlspecialchars($row['part_number'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($row['brand'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($row['description'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($row['total_qty'] ?? '0'); ?></td>

</tr>

<?php endwhile; ?>

</tbody>
</table>
</div>

</div>

<div class="modal fade" id="serialModal">
<div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5>Serial Numbers</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" id="serialContent"></div>

    </div>
  </div>
</div>

<div class="modal fade" id="confirmModal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Confirm Stock Out</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body text-center">
        <p id="confirmText"></p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" onclick="deleteSerial()">Confirm</button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="remarkModal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">Stock Out Remark</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <p id="selectedSerial"></p>

        <textarea id="remarkInput" class="form-control"
            placeholder="Enter remark..."></textarea>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="submitStockOut()">Submit</button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="detailModal">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5>Asset Details</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" id="detailContent"></div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
function viewSerial(part){
    $.post("../backend/get_serials.php",{part:part},function(data){
        $("#serialContent").html(data);
        var modal = new bootstrap.Modal(document.getElementById('serialModal'));
        modal.show();
    });
}

let selectedId = 0;

function openRemarkModal(id, serial){
    selectedId = id;

    document.getElementById("selectedSerial").innerHTML =
        "Serial: <b>" + serial + "</b>";

    document.getElementById("remarkInput").value = "";

    var modal = new bootstrap.Modal(document.getElementById('remarkModal'));
    modal.show();
}

function submitStockOut(){
    let remark = document.getElementById("remarkInput").value;

    $.post("../backend/stock_out.php",
    {
        id: selectedId,
        remark: remark
    }, function(data){
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

function deleteAssetDirect(id, serial){
    if(!confirm("Delete asset serial " + serial + " permanently?\n\nThis will NOT go to Stock Out History.")){
        return;
    }

    $.post("../backend/delete_asset_direct.php",
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

let deleteId = 0;

function confirmDelete(id, serial){
    deleteId = id;
    document.getElementById("confirmText").innerHTML =
        "Stock out serial: <b>" + serial + "</b>?";

    var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
}

function deleteSerial(){
    $.post("../frontend/asset_delete.php", {id: deleteId}, function(data){
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

function viewDetail(id){
    $.post("../backend/get_asset_detail.php",{id:id},function(data){
        $("#detailContent").html(data);

        var modal = new bootstrap.Modal(document.getElementById('detailModal'));
        modal.show();
    });
}
</script>

<script>
let assetTable;

/*
    ✅ DATATABLE PAGINATION + LIVE SEARCH
    - No left/right scrollbar
    - Info shows bottom left
    - Page number shows bottom right
    - Comma search supported
*/
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if(settings.nTable.id !== "assetInventoryTable"){
        return true;
    }

    const input = document.getElementById("liveAssetSearch");
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

    assetTable = $("#assetInventoryTable").DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        ordering: true,
        searching: true,
        autoWidth: false,
        scrollX: false,
        dom:
            "<'row mb-2 align-items-center'<'col-md-6'l>>" +
            "rt" +
            "<'row mt-3 align-items-center asset-bottom-row'<'col-md-6'i><'col-md-6 d-flex justify-content-end'p>>",
        language: {
            zeroRecords: "No records found",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ assets"
        },
        columnDefs: [
            { width: "20%", targets: 0 },
            { width: "18%", targets: 1 },
            { width: "47%", targets: 2 },
            { width: "15%", targets: 3 }
        ]
    });

    $("#liveAssetSearch").on("input", function(){
        assetTable.draw();

        let keyword = this.value.trim();
        let newUrl = "asset_inventory.php";

        if(keyword !== ""){
            newUrl += "?search=" + encodeURIComponent(keyword);
        }

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    assetTable.draw();
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