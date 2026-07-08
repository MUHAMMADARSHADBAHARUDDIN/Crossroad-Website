<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/search_helper.php";
require_once "../includes/inventory_report_schema.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

$faviconVersion = file_exists("../image/logo.png") ? filemtime("../image/logo.png") : time();

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
$mysqli->query("SET SESSION group_concat_max_len = 100000");

$sql = "
SELECT
    si.server_name,
    si.machine_type,
    si.brand,
    MAX(si.no) AS latest_no,
    COUNT(*) AS total_qty,
    SUM(CASE WHEN si.status = 'Okay' THEN 1 ELSE 0 END) AS ok_qty,
    SUM(CASE WHEN si.status = 'Faulty' THEN 1 ELSE 0 END) AS faulty_qty,
    GROUP_CONCAT(si.no SEPARATOR ' ') AS record_ids,
    GROUP_CONCAT(si.serial_number SEPARATOR ' ') AS serial_numbers,
    GROUP_CONCAT(si.location SEPARATOR ' ') AS locations,
    GROUP_CONCAT(si.status SEPARATOR ' ') AS statuses,
    GROUP_CONCAT(si.remark SEPARATOR ' ') AS remarks,
    GROUP_CONCAT(si.tester SEPARATOR ' ') AS testers,
    GROUP_CONCAT(si.received_by SEPARATOR ' ') AS received_by_values,
    GROUP_CONCAT(si.created_by SEPARATOR ' ') AS created_by_values,
    GROUP_CONCAT(si.date_testing SEPARATOR ' ') AS testing_dates,
    GROUP_CONCAT(COALESCE(sc.component_search, '') SEPARATOR ' ') AS component_search_values
FROM server_inventory si
LEFT JOIN (
    SELECT
        server_inventory_id,
        GROUP_CONCAT(CONCAT_WS(' ', component_type, part_number, serial_number) SEPARATOR ' ') AS component_search
    FROM server_components
    GROUP BY server_inventory_id
) sc ON sc.server_inventory_id = si.no
GROUP BY si.server_name, si.machine_type, si.brand
ORDER BY latest_no DESC
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

<link rel="icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
<link rel="shortcut icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
<link rel="apple-touch-icon" href="../image/logo.png?v=<?= $faviconVersion ?>">

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
    overflow-x:auto !important;
    overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior-x:contain;
    width:100%;
}

#serverInventoryTable{
    width:100% !important;
    min-width:860px;
    table-layout:auto;
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
    overflow-x:visible !important;
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

.server-stockout-component-list{
    max-height:320px;
    overflow-y:auto;
    display:grid !important;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:12px !important;
    padding:2px;
}

.server-component-choice-modal{
    border:0;
    border-radius:14px;
    overflow:hidden;
}

.server-component-choice-header{
    background:#212529;
    color:#fff;
    border-bottom:0;
    padding:16px 18px;
}

.server-component-choice-title{
    display:flex;
    align-items:center;
    gap:12px;
}

.server-component-choice-title h5{
    margin:0;
    font-size:18px;
}

.server-component-choice-title small{
    color:#ced4da;
}

.server-component-choice-icon{
    width:38px;
    height:38px;
    border-radius:10px;
    background:#ffc107;
    color:#000;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.server-component-choice-summary{
    background:#f8f9fa;
    border:1px solid #e9ecef;
    border-radius:12px;
    padding:12px;
    margin-bottom:14px;
}

.server-component-serial-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:#fff3cd;
    border:1px solid #ffe69c;
    color:#664d03;
    border-radius:999px;
    padding:5px 10px;
    font-weight:700;
    margin-bottom:8px;
}

.server-stockout-component-option{
    border:1px solid #e3e7ec;
    border-radius:12px;
    background:#fff;
    cursor:pointer;
    padding:14px;
    min-height:132px;
    transition:background-color .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    user-select:none;
    position:relative;
    overflow:hidden;
}

.server-stockout-component-option:hover{
    border-color:#ffc107;
    box-shadow:0 10px 22px rgba(33,37,41,.08);
    transform:translateY(-2px);
}

.server-stockout-component-option.is-selected{
    background:#fffaf0;
    border-color:#ffc107;
    box-shadow:0 0 0 3px rgba(255,193,7,.22), 0 14px 28px rgba(255,193,7,.16);
}

.server-stockout-component-option input{
    position:absolute;
    opacity:0;
    pointer-events:none;
}

.server-stockout-component-state{
    border-radius:999px;
    background:#e9ecef;
    color:#495057;
    font-size:12px;
    font-weight:700;
    padding:4px 10px;
}

.server-stockout-component-option.is-selected .server-stockout-component-state{
    background:#ffc107;
    color:#000;
}

.server-component-card-head{
    display:flex;
    align-items:flex-start;
    gap:11px;
}

.server-component-card-icon{
    width:38px;
    height:38px;
    border-radius:10px;
    background:#f1f3f5;
    color:#495057;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    flex:0 0 auto;
}

.server-stockout-component-option.is-selected .server-component-card-icon{
    background:#ffc107;
    color:#000;
}

.server-component-card-title{
    font-weight:800;
    color:#212529;
    margin-bottom:5px;
}

.server-component-card-meta{
    color:#6c757d;
    font-size:12px;
    line-height:1.35;
}

.server-component-card-footer{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin-top:12px;
}

.server-component-visual-dot{
    width:26px;
    height:26px;
    border-radius:999px;
    border:1px solid #ced4da;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    background:#fff;
    transition:background-color .18s ease, border-color .18s ease, transform .18s ease;
}

.server-component-visual-dot i{
    font-size:12px;
    opacity:0;
}

.server-stockout-component-option.is-selected .server-component-visual-dot{
    background:#198754;
    border-color:#198754;
    transform:scale(1.04);
}

.server-stockout-component-option.is-selected .server-component-visual-dot i{
    opacity:1;
}

@media(max-width:992px){
    .table-responsive{
        overflow-x:auto !important;
        -webkit-overflow-scrolling:touch;
    }

    #serverInventoryTable{
        min-width:900px !important;
        max-width:none !important;
    }

    #serverInventoryTable th{
        white-space:nowrap !important;
        min-width:140px;
        max-width:none !important;
        word-break:normal !important;
        overflow-wrap:normal !important;
    }

    #serverInventoryTable td{
        white-space:normal !important;
        min-width:140px;
        max-width:320px !important;
        word-break:break-word !important;
        overflow-wrap:anywhere !important;
    }

    #serverInventoryTable th:nth-child(4),
    #serverInventoryTable td:nth-child(4){
        min-width:180px;
    }

    #serverInventoryTable td:nth-child(4),
    #serverInventoryTable td:nth-child(5){
        white-space:nowrap !important;
        max-width:none !important;
        word-break:normal !important;
        overflow-wrap:normal !important;
    }

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

<h2 class="mb-4">Assets Inventory</h2>

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
    ($row['record_ids'] ?? '') . ' ' .
    ($row['serial_numbers'] ?? '') . ' ' .
    ($row['locations'] ?? '') . ' ' .
    ($row['statuses'] ?? '') . ' ' .
    ($row['remarks'] ?? '') . ' ' .
    ($row['testers'] ?? '') . ' ' .
    ($row['received_by_values'] ?? '') . ' ' .
    ($row['created_by_values'] ?? '') . ' ' .
    ($row['testing_dates'] ?? '') . ' ' .
    ($row['component_search_values'] ?? '') . ' ' .
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
<h5>Assets Serial List</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body" id="serialContent"></div>

</div>
</div>
</div>

<div class="modal fade" id="serverStockOutComponentModal">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content server-component-choice-modal shadow">

      <div class="modal-header server-component-choice-header">
        <div class="server-component-choice-title">
            <span class="server-component-choice-icon">
                <i class="fa fa-microchip"></i>
            </span>
            <div>
                <h5 class="modal-title">Choose Components</h5>
                <small>Decide what stays as parts and what leaves with the server.</small>
            </div>
        </div>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="server-component-choice-summary">
          <div id="serverStockOutComponentSerial" class="server-component-serial-pill"></div>
          <div class="small text-muted">
            Highlighted components move to Parts Inventory. Plain components leave with the server stock out.
          </div>
        </div>
        <div id="serverStockOutComponentList" class="server-stockout-component-list d-flex flex-column gap-2"></div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-warning" onclick="continueServerStockOutRemark()">OK</button>
      </div>

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

        <div class="form-floating mb-3">
          <input type="text" id="serverTicketNumberInput" class="form-control" placeholder="Ticket Number">
          <label>Ticket Number</label>
        </div>

        <label for="serverRemarkInput" class="form-label">Remark</label>
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

<div class="modal fade" id="serverComponentModal">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5>Server Components</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <p class="text-muted mb-2" id="serverComponentSerial"></p>
        <div id="serverComponentContent"></div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
function escapeHtml(value){
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

const serverModalFlow = {
    detailReturnsToSerial: false,
    componentReturnsToDetail: false,
    stockOutComponentReturnsToSerial: false,
    remarkReturnsToStockOutComponent: false,
    remarkReturnsToSerial: false,
    pendingShowDetail: false,
    pendingShowComponent: false,
    pendingShowStockOutComponent: false,
    pendingShowRemark: false
};

function getServerModal(id){
    return bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
}

function isServerModalShown(id){
    const element = document.getElementById(id);
    return element && element.classList.contains("show");
}

function showServerSerialModal(){
    getServerModal("serialModal").show();
}

function showServerDetailModal(){
    getServerModal("serverDetailModal").show();
}

function showServerComponentModal(){
    getServerModal("serverComponentModal").show();
}

function showServerStockOutComponentModal(){
    getServerModal("serverStockOutComponentModal").show();
}

function showServerRemarkModal(){
    getServerModal("serverRemarkModal").show();
}

document.getElementById("serialModal").addEventListener("hidden.bs.modal", function(){
    if(serverModalFlow.pendingShowDetail){
        serverModalFlow.pendingShowDetail = false;
        showServerDetailModal();
        return;
    }

    if(serverModalFlow.pendingShowStockOutComponent){
        serverModalFlow.pendingShowStockOutComponent = false;
        showServerStockOutComponentModal();
        return;
    }

    if(serverModalFlow.pendingShowRemark){
        serverModalFlow.pendingShowRemark = false;
        showServerRemarkModal();
    }
});

document.getElementById("serverDetailModal").addEventListener("hidden.bs.modal", function(){
    if(serverModalFlow.pendingShowComponent){
        serverModalFlow.pendingShowComponent = false;
        showServerComponentModal();
        return;
    }

    if(serverModalFlow.detailReturnsToSerial){
        serverModalFlow.detailReturnsToSerial = false;
        showServerSerialModal();
    }
});

document.getElementById("serverComponentModal").addEventListener("hidden.bs.modal", function(){
    if(!serverModalFlow.componentReturnsToDetail){
        return;
    }

    serverModalFlow.componentReturnsToDetail = false;
    showServerDetailModal();
});

document.getElementById("serverStockOutComponentModal").addEventListener("hidden.bs.modal", function(){
    if(serverModalFlow.pendingShowRemark){
        serverModalFlow.pendingShowRemark = false;
        showServerRemarkModal();
        return;
    }

    if(!serverModalFlow.stockOutComponentReturnsToSerial){
        return;
    }

    serverModalFlow.stockOutComponentReturnsToSerial = false;
    showServerSerialModal();
});

document.getElementById("serverRemarkModal").addEventListener("hidden.bs.modal", function(){
    if(serverModalFlow.remarkReturnsToStockOutComponent){
        serverModalFlow.remarkReturnsToStockOutComponent = false;
        showServerStockOutComponentModal();
        return;
    }

    if(!serverModalFlow.remarkReturnsToSerial){
        return;
    }

    serverModalFlow.remarkReturnsToSerial = false;
    showServerSerialModal();
});

function viewServer(name, type){
    $.post("../backend/get_server_serials.php",
    {name:name, type:type},
    function(data){
        $("#serialContent").html(data);
        serverModalFlow.detailReturnsToSerial = false;
        serverModalFlow.componentReturnsToDetail = false;
        serverModalFlow.stockOutComponentReturnsToSerial = false;
        serverModalFlow.remarkReturnsToStockOutComponent = false;
        serverModalFlow.remarkReturnsToSerial = false;
        serverModalFlow.pendingShowDetail = false;
        serverModalFlow.pendingShowComponent = false;
        serverModalFlow.pendingShowStockOutComponent = false;
        serverModalFlow.pendingShowRemark = false;
        showServerSerialModal();
    });
}

let serverSelectedId = 0;
let serverSelectedSerial = "";
let serverStockOutSelectionActive = false;

function renderServerStockOutComponentChoices(serial, components){
    const serialElement = document.getElementById("serverStockOutComponentSerial");
    const list = document.getElementById("serverStockOutComponentList");

    serialElement.innerHTML = "<i class='fa fa-barcode'></i> Serial: " + escapeHtml(serial || "-");

    if(!Array.isArray(components) || components.length === 0){
        list.innerHTML = "<div class='alert alert-secondary mb-0'>No components recorded for this server.</div>";
        return;
    }

    list.innerHTML = components.map(function(component){
        const id = String(component.id || "");
        const componentType = component.component_type || "Component";
        const partNumber = component.part_number || "-";
        const serialNumber = component.serial_number || "-";

        return "<div class='server-stockout-component-option' role='checkbox' tabindex='0' aria-checked='false'>" +
            "<input class='server-keep-component-check' type='checkbox' value='" + escapeHtml(id) + "'>" +
            "<div class='server-component-card-head'>" +
                "<span class='server-component-card-icon'><i class='fa fa-microchip'></i></span>" +
                "<div>" +
                    "<div class='server-component-card-title'>" + escapeHtml(componentType) + "</div>" +
                    "<div class='server-component-card-meta'>Part Number: " + escapeHtml(partNumber) + "</div>" +
                    "<div class='server-component-card-meta'>Serial Number: " + escapeHtml(serialNumber) + "</div>" +
                "</div>" +
            "</div>" +
            "<div class='server-component-card-footer'>" +
                "<span class='server-stockout-component-state'>Leave with Server</span>" +
                "<span class='server-component-visual-dot'><i class='fa fa-check'></i></span>" +
            "</div>" +
        "</div>";
    }).join("");
}

function syncServerStockOutComponentTile(tile){
    const checkbox = tile.querySelector(".server-keep-component-check");
    const state = tile.querySelector(".server-stockout-component-state");
    const isSelected = !!(checkbox && checkbox.checked);

    tile.classList.toggle("is-selected", isSelected);
    tile.setAttribute("aria-checked", isSelected ? "true" : "false");

    if(state){
        state.textContent = isSelected ? "Move to Parts Inventory" : "Leave with Server";
    }
}

function toggleServerStockOutComponentTile(tile){
    const checkbox = tile.querySelector(".server-keep-component-check");

    if(!checkbox){
        return;
    }

    checkbox.checked = !checkbox.checked;
    syncServerStockOutComponentTile(tile);
}

document.addEventListener("click", function(event){
    const tile = event.target.closest(".server-stockout-component-option");

    if(!tile){
        return;
    }

    toggleServerStockOutComponentTile(tile);
});

document.addEventListener("keydown", function(event){
    const tile = event.target.closest(".server-stockout-component-option");

    if(!tile || (event.key !== "Enter" && event.key !== " ")){
        return;
    }

    event.preventDefault();
    toggleServerStockOutComponentTile(tile);
});

function openServerRemarkModal(id, serial, components){
    serverSelectedId = id;
    serverSelectedSerial = serial || "";
    serverStockOutSelectionActive = Array.isArray(components) && components.length > 0;

    document.getElementById("serverSelectedSerial").innerHTML =
        "Serial: <b>" + escapeHtml(serverSelectedSerial) + "</b>";

    document.getElementById("serverTicketNumberInput").value = "";
    document.getElementById("serverRemarkInput").value = "";
    renderServerStockOutComponentChoices(serverSelectedSerial, components || []);

    serverModalFlow.remarkReturnsToStockOutComponent = false;
    serverModalFlow.remarkReturnsToSerial = false;
    serverModalFlow.stockOutComponentReturnsToSerial = isServerModalShown("serialModal");

    if(serverStockOutSelectionActive){
        if(serverModalFlow.stockOutComponentReturnsToSerial){
            serverModalFlow.pendingShowStockOutComponent = true;
            getServerModal("serialModal").hide();
            return;
        }

        showServerStockOutComponentModal();
        return;
    }

    serverModalFlow.remarkReturnsToSerial = isServerModalShown("serialModal");

    if(serverModalFlow.remarkReturnsToSerial){
        serverModalFlow.pendingShowRemark = true;
        getServerModal("serialModal").hide();
        return;
    }

    showServerRemarkModal();
}

function continueServerStockOutRemark(){
    serverModalFlow.remarkReturnsToStockOutComponent = isServerModalShown("serverStockOutComponentModal");
    serverModalFlow.remarkReturnsToSerial = false;

    if(serverModalFlow.remarkReturnsToStockOutComponent){
        serverModalFlow.pendingShowRemark = true;
        getServerModal("serverStockOutComponentModal").hide();
        return;
    }

    showServerRemarkModal();
}

function submitServerStockOut(){
    let ticketNumber = document.getElementById("serverTicketNumberInput").value;
    let remark = document.getElementById("serverRemarkInput").value;
    let keepComponentIds = Array.from(document.querySelectorAll(".server-keep-component-check:checked"))
        .map(function(field){
            return field.value;
        });

    $.post("../backend/server_stock_out.php",
    {
        id: serverSelectedId,
        ticket_number: ticketNumber,
        remark: remark,
        component_selection_active: serverStockOutSelectionActive ? "1" : "0",
        keep_component_ids: keepComponentIds
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

        serverModalFlow.detailReturnsToSerial = isServerModalShown("serialModal");

        if(serverModalFlow.detailReturnsToSerial){
            serverModalFlow.pendingShowDetail = true;
            getServerModal("serialModal").hide();
            return;
        }

        showServerDetailModal();
    });
}

function openServerComponentModal(serial, components){
    document.getElementById("serverComponentSerial").textContent = "Server Serial: " + (serial || "-");

    if(!Array.isArray(components) || components.length === 0){
        document.getElementById("serverComponentContent").innerHTML =
            "<div class='alert alert-secondary mb-0'>No components recorded for this server.</div>";
    }
    else{
        let rows = components.map(function(component){
            return "<tr>" +
                "<td>" + escapeHtml(component.component_type || "-") + "</td>" +
                "<td>" + escapeHtml(component.part_number || "-") + "</td>" +
                "<td>" + escapeHtml(component.serial_number || "-") + "</td>" +
            "</tr>";
        }).join("");

        document.getElementById("serverComponentContent").innerHTML =
            "<table class='table table-sm table-bordered align-middle mb-0'>" +
                "<thead class='table-light'>" +
                    "<tr><th>Component</th><th>Part Number</th><th>Serial Number</th></tr>" +
                "</thead>" +
                "<tbody>" + rows + "</tbody>" +
            "</table>";
    }

    serverModalFlow.componentReturnsToDetail = isServerModalShown("serverDetailModal");

    if(serverModalFlow.componentReturnsToDetail){
        serverModalFlow.pendingShowComponent = true;
        getServerModal("serverDetailModal").hide();
        return;
    }

    showServerComponentModal();
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
        order: [],
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
