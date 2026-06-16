<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/search_helper.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

function serverStockoutEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function serverAdditionalInfoTableExists($mysqli){
    $result = $mysqli->query("SHOW TABLES LIKE 'stockout_additional_information'");
    return ($result && $result->num_rows > 0);
}

function serverStockoutDateTimeLocal($value){
    if(empty($value) || !strtotime($value)){
        return "";
    }

    return date("Y-m-d\TH:i", strtotime($value));
}

$faviconVersion = file_exists("../image/logo.png") ? filemtime("../image/logo.png") : time();
$canDelete = hasPermission($mysqli, "inventory_delete");
$canAddInformation = hasStockOutAdditionalInfoAccess($mysqli);
$canEditAdditionalInformation = hasStockOutAdditionalInfoEditAccess($mysqli);
$canDeleteAdditionalInformation = hasStockOutAdditionalInfoDeleteAccess($mysqli);
$search = trim($_GET['search'] ?? "");

$stmt = $mysqli->prepare("
    SELECT *
    FROM server_stockout_history
    ORDER BY stock_out_date DESC
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->execute();
$result = $stmt->get_result();
$additionalInformation = [];

if(serverAdditionalInfoTableExists($mysqli)){
    $noteStmt = $mysqli->prepare("
        SELECT id, stockout_id, additional_information, added_by, added_at
        FROM stockout_additional_information
        WHERE stockout_type = 'server'
        ORDER BY added_at ASC, id ASC
    ");

    if($noteStmt){
        $noteStmt->execute();
        $noteResult = $noteStmt->get_result();

        while($note = $noteResult->fetch_assoc()){
            $additionalInformation[(int)$note['stockout_id']][] = $note;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Server Stock Out History</title>
    <link rel="icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
    <link rel="shortcut icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
    <link rel="apple-touch-icon" href="../image/logo.png?v=<?= $faviconVersion ?>">
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<style>
html, body{ overflow-x:hidden !important; }
.main{ overflow-x:hidden !important; max-width:100%; }
.table-responsive{ overflow-x:hidden !important; width:100%; }

#serverStockOutTable{
    width:100% !important;
    table-layout:fixed;
}

#serverStockOutTable th,
#serverStockOutTable td{
    white-space:normal !important;
    word-break:break-word;
    overflow-wrap:anywhere;
    vertical-align:middle;
    font-size:13px;
}

#serverStockOutTable tbody tr:hover{
    background:#fff3cd !important;
}

#serverStockOutTable_wrapper{
    width:100%;
    overflow-x:hidden !important;
}

#serverStockOutTable_wrapper .row{
    margin-left:0 !important;
    margin-right:0 !important;
}

#serverStockOutTable_wrapper .dataTables_info{
    padding-top:0;
    font-size:14px;
    color:#6c757d;
}

#serverStockOutTable_wrapper .dataTables_paginate{
    padding-top:0;
}

#serverStockOutTable_wrapper .page-item .page-link{
    border-radius:8px;
    margin:0 3px;
    border:none;
}

#serverStockOutTable_wrapper .page-item.active .page-link{
    background-color:#ffc107;
    color:#000;
}

#serverStockOutTable tbody tr{
    cursor:pointer;
}

.stockout-note{
    border-left:3px solid #ffc107;
    background:#fffdf2;
    border-radius:0 8px 8px 0;
    padding:7px 8px;
    margin-bottom:6px;
}

.stockout-note:last-child{
    margin-bottom:0;
}

.stockout-note-meta{
    color:#6c757d;
    font-size:11px;
    margin-top:5px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}

.stockout-note-actions{
    display:flex;
    align-items:center;
    gap:5px;
    flex-wrap:wrap;
}

.stockout-note-edit,
.stockout-note-delete{
    border:0;
    color:#fff;
    border-radius:7px;
    font-size:10px;
    padding:3px 6px;
    line-height:1;
}

.stockout-note-edit{
    background:#0d6efd;
}

.stockout-note-edit:hover{
    background:#0b5ed7;
}

.stockout-note-delete{
    background:#dc3545;
}

.stockout-note-delete:hover{
    background:#bb2d3b;
}

.action-buttons{
    display:flex;
    gap:5px;
    flex-wrap:wrap;
}

.append-warning{
    background:#fff3cd;
    border:1px solid #ffe69c;
    color:#664d03;
    border-radius:10px;
    padding:10px 12px;
    font-size:13px;
}

.edit-warning{
    background:#e7f1ff;
    border:1px solid #b6d4fe;
    color:#084298;
    border-radius:10px;
    padding:10px 12px;
    font-size:13px;
}

.stockout-detail-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    margin-bottom:18px;
}

.stockout-detail-box{
    background:#f8f9fa;
    border:1px solid #e9ecef;
    border-radius:10px;
    padding:10px 12px;
}

.stockout-detail-box > span{
    display:block;
    color:#6c757d;
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    margin-bottom:4px;
}

.stockout-detail-box strong{
    display:block;
    color:#212529;
    word-break:break-word;
}

/* Status indicator inside popup */
.server-status-indicator{
    display:inline-flex !important;
    align-items:center;
    gap:8px;
    font-weight:700;
    color:#212529;
}

.server-status-text{
    display:inline !important;
    color:#212529 !important;
    font-size:14px !important;
    font-weight:700 !important;
    text-transform:none !important;
    margin-bottom:0 !important;
}

.server-status-box{
    width:14px;
    height:14px;
    border-radius:4px;
    display:inline-block !important;
    margin-bottom:0 !important;
    box-shadow:0 2px 6px rgba(0,0,0,.18);
}

.server-status-box.okay{
    background:#198754;
}

.server-status-box.faulty{
    background:#dc3545;
}

@media(max-width:768px){
    #serverStockOutTable_wrapper .server-stockout-bottom-row{
        gap:10px;
    }

    #serverStockOutTable_wrapper .dataTables_info,
    #serverStockOutTable_wrapper .dataTables_paginate{
        text-align:left !important;
        justify-content:flex-start !important;
    }

    .stockout-detail-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>
<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">
    <h2 class="mb-4">Assets Stock Out History</h2>

    <form method="GET" class="mb-2" onsubmit="return false;">
        <div class="input-group">
            <input type="text"
                   id="liveServerStockOutSearch"
                   name="search"
                   class="form-control"
                   placeholder="Search by Server Name / Serial / Remark / Additional Information..."
                   value="<?= serverStockoutEscape($search) ?>"
                   autocomplete="off">
            <button type="button" class="btn btn-warning">
                <i class="fa fa-search"></i>
            </button>
        </div>
    </form>

    <div class="table-responsive">
        <?php $serverStockoutDetailTemplates = []; ?>

        <table class="table table-striped table-hover" id="serverStockOutTable">
            <thead>
                <tr>
                    <th>Server Name</th>
                    <th>Serial Number</th>
                    <th>Original Remark</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
                <?php
                $id = (int)$row['id'];
                $notes = $additionalInformation[$id] ?? [];
                $date = !empty($row['stock_out_date']) ? date("d/m/y", strtotime($row['stock_out_date'])) : "-";
                $time = !empty($row['stock_out_date']) ? date("H:i:s", strtotime($row['stock_out_date'])) : "";
                $statusText = trim((string)($row['status'] ?? ''));
                $statusIndicatorClass = (strcasecmp($statusText, 'Okay') === 0) ? 'okay' : 'faulty';
                $statusDisplayText = $statusText !== '' ? $statusText : '-';
                $notesSearch = "";

                foreach($notes as $note){
                    $notesSearch .= " " . ($note['additional_information'] ?? '') . " " . ($note['added_by'] ?? '');
                }

                $searchText = strtolower(
                    ($row['server_name'] ?? '') . ' ' .
                    ($row['machine_type'] ?? '') . ' ' .
                    ($row['serial_number'] ?? '') . ' ' .
                    ($row['location'] ?? '') . ' ' .
                    ($row['status'] ?? '') . ' ' .
                    ($row['remark'] ?? '') . ' ' .
                    ($row['tester'] ?? '') . ' ' .
                    ($row['stock_out_by'] ?? '') . ' ' .
                    ($row['stock_out_date'] ?? '') . ' ' .
                    $date . ' ' .
                    $time . ' ' .
                    $notesSearch
                );

                $itemLabel = ($row['server_name'] ?? '') . ' / ' . ($row['serial_number'] ?? '');

                ob_start();
                ?>

                <div class="stockout-detail-grid">
                    <div class="stockout-detail-box">
                        <span>Machine Type</span>
                        <strong><?= serverStockoutEscape(($row['machine_type'] ?? '') !== '' ? $row['machine_type'] : '-') ?></strong>
                    </div>

                    <div class="stockout-detail-box">
                        <span>Status</span>
                        <strong>
                            <span class="server-status-indicator">
                                <span class="server-status-text"><?= serverStockoutEscape($statusDisplayText) ?></span>
                                <span class="server-status-box <?= serverStockoutEscape($statusIndicatorClass) ?>"></span>
                            </span>
                        </strong>
                    </div>

                    <div class="stockout-detail-box">
                        <span>Tester</span>
                        <strong><?= serverStockoutEscape(($row['tester'] ?? '') !== '' ? $row['tester'] : '-') ?></strong>
                    </div>

                    <div class="stockout-detail-box">
                        <span>Stocked Out By</span>
                        <strong><?= serverStockoutEscape(($row['stock_out_by'] ?? '') !== '' ? $row['stock_out_by'] : '-') ?></strong>
                    </div>

                    <div class="stockout-detail-box">
                        <span>Date</span>
                        <strong><?= serverStockoutEscape(trim($date . ' ' . $time)) ?></strong>
                    </div>
                </div>

                <h6 class="mb-2">Additional Information</h6>

                <?php if(empty($notes)): ?>
                    <div class="alert alert-light border mb-0 text-muted">
                        No additional information yet.
                    </div>
                <?php else: ?>
                    <?php foreach($notes as $note): ?>
                        <div class="stockout-note">
                            <div><?= nl2br(serverStockoutEscape($note['additional_information'])) ?></div>

                            <div class="stockout-note-meta">
                                <span>
                                    <?= serverStockoutEscape($note['added_by']) ?> -
                                    <?= serverStockoutEscape(date("d/m/y H:i", strtotime($note['added_at']))) ?>
                                </span>

                                <?php if($canEditAdditionalInformation || $canDeleteAdditionalInformation): ?>
                                    <div class="stockout-note-actions">
                                        <?php if($canEditAdditionalInformation): ?>
                                            <button type="button"
                                                    class="stockout-note-edit"
                                                    title="Edit this additional information"
                                                    onclick='openEditAdditionalInformationModal(<?= (int)$note['id'] ?>, <?= serverStockoutEscape(json_encode($note['additional_information'] ?? '')) ?>)'>
                                                <i class="fa fa-pen"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if($canDeleteAdditionalInformation): ?>
                                            <button type="button"
                                                    class="stockout-note-delete"
                                                    title="Delete this additional information"
                                                    onclick="deleteAdditionalInformation(<?= (int)$note['id'] ?>)">
                                                <i class="fa fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php
                $serverStockoutDetailTemplates[$id] = ob_get_clean();
                ?>

                <tr data-search="<?= serverStockoutEscape($searchText) ?>"
                    data-detail-id="server-stockout-detail-<?= $id ?>">
                    <td><?= serverStockoutEscape($row['server_name'] ?? '') ?></td>
                    <td><?= serverStockoutEscape($row['serial_number'] ?? '') ?></td>
                    <td><?= serverStockoutEscape(($row['remark'] ?? '') !== '' ? $row['remark'] : '-') ?></td>

                    <td>
                        <div class="action-buttons">
                            <?php if($canAddInformation): ?>
                                <button type="button"
                                        class="btn btn-sm btn-warning"
                                        title="Add additional information"
                                        onclick='openAdditionalInfoModal(<?= $id ?>, <?= serverStockoutEscape(json_encode($itemLabel)) ?>)'>
                                    <i class="fa fa-plus"></i>
                                </button>
                            <?php endif; ?>

                            <?php if($canDelete): ?>
                                <button type="button"
                                        class="btn btn-sm btn-danger"
                                        title="Delete history"
                                        onclick="deleteServerStockOutHistory(<?= $id ?>)">
                                    <i class="fa fa-trash"></i>
                                </button>
                            <?php endif; ?>

                            <?php if(!$canEditAdditionalInformation && !$canAddInformation && !$canDelete): ?>
                                <span class="badge bg-secondary">View Only</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="d-none" id="serverStockoutDetailTemplates">
        <?php foreach($serverStockoutDetailTemplates as $templateId => $templateHtml): ?>
            <div id="server-stockout-detail-<?= (int)$templateId ?>"><?= $templateHtml ?></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="serverStockoutDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="fa fa-circle-info text-warning"></i>
                    Assets Stock Out Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="serverStockoutDetailContent"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="additionalInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="fa fa-plus-circle text-warning"></i>
                    Add Information
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="additionalStockoutId">

                <div class="mb-3">
                    <strong>Server:</strong>
                    <span id="additionalItemLabel"></span>
                </div>

                <div class="append-warning mb-3">
                    <i class="fa fa-lock"></i>
                    This adds a new record only. The original stock out record and previous information are not overwritten here.
                </div>

                <label for="additionalInformationText" class="form-label">Additional Information</label>
                <textarea id="additionalInformationText"
                          class="form-control"
                          rows="4"
                          maxlength="5000"
                          placeholder="Enter new information for this stock out record..."></textarea>
            </div>

            <div class="modal-footer">
                <button type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    Cancel
                </button>

                <button type="button"
                        class="btn btn-warning"
                        id="saveAdditionalInfoBtn"
                        onclick="saveAdditionalInformation()">
                    <i class="fa fa-save"></i>
                    Save Additional Information
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editAdditionalInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="fa fa-pen text-warning"></i>
                    Edit Additional Information
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="editAdditionalInfoId">

                <div class="edit-warning mb-3">
                    <i class="fa fa-circle-info"></i>
                    This edits only the selected additional information. The original stock out remark stays unchanged.
                </div>

                <label for="editAdditionalInformationText" class="form-label">Additional Information</label>
                <textarea id="editAdditionalInformationText"
                          class="form-control"
                          rows="4"
                          maxlength="5000"
                          placeholder="Update this additional information..."></textarea>
            </div>

            <div class="modal-footer">
                <button type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    Cancel
                </button>

                <button type="button"
                        class="btn btn-primary"
                        id="saveEditAdditionalInfoBtn"
                        onclick="saveEditAdditionalInformation()">
                    <i class="fa fa-save"></i>
                    Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
function toggleSidebar(){
    const sidebar = document.getElementById("sidebar");
    const main = document.querySelector(".main");
    const btn = document.getElementById("menuBtn");

    sidebar.classList.toggle("collapsed");
    main.classList.toggle("expanded");
    btn.classList.toggle("active");
}

function openAdditionalInfoModal(id, label){
    document.getElementById("additionalStockoutId").value = id;
    document.getElementById("additionalItemLabel").textContent = label;
    document.getElementById("additionalInformationText").value = "";

    new bootstrap.Modal(document.getElementById("additionalInfoModal")).show();
}

function saveAdditionalInformation(){
    const id = document.getElementById("additionalStockoutId").value;
    const information = document.getElementById("additionalInformationText").value.trim();
    const button = document.getElementById("saveAdditionalInfoBtn");

    if(information === ""){
        alert("Please enter additional information.");
        return;
    }

    button.disabled = true;

    fetch("../backend/add_stockout_additional_info.php", {
        method:"POST",
        headers:{"Content-Type":"application/x-www-form-urlencoded"},
        body:"stockout_type=server&stockout_id=" + encodeURIComponent(id) +
             "&additional_information=" + encodeURIComponent(information)
    })
    .then(response => response.text())
    .then(data => {
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
            button.disabled = false;
        }
    })
    .catch(() => {
        alert("Failed to save additional information.");
        button.disabled = false;
    });
}

function deleteAdditionalInformation(id){
    event.stopPropagation();

    if(!confirm("Delete this additional information?")){
        return;
    }

    fetch("../backend/delete_stockout_additional_info.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "id=" + encodeURIComponent(id)
    })
    .then(response => response.text())
    .then(data => {
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    })
    .catch(() => alert("Failed to delete additional information."));
}

function openEditAdditionalInformationModal(id, currentInformation){
    event.stopPropagation();

    document.getElementById("editAdditionalInfoId").value = id;
    document.getElementById("editAdditionalInformationText").value = currentInformation || "";

    new bootstrap.Modal(document.getElementById("editAdditionalInfoModal")).show();
}

function saveEditAdditionalInformation(){
    const id = document.getElementById("editAdditionalInfoId").value;
    const information = document.getElementById("editAdditionalInformationText").value.trim();
    const button = document.getElementById("saveEditAdditionalInfoBtn");

    if(information === ""){
        alert("Please enter additional information.");
        return;
    }

    button.disabled = true;

    const body = new URLSearchParams();
    body.append("id", id);
    body.append("additional_information", information);

    fetch("../backend/update_stockout_additional_info.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: body.toString()
    })
    .then(response => response.text())
    .then(data => {
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
            button.disabled = false;
        }
    })
    .catch(() => {
        alert("Failed to update additional information.");
        button.disabled = false;
    });
}

function deleteServerStockOutHistory(id){
    if(!confirm("Delete this server stock out history record and its additional information?")){
        return;
    }

    fetch("../backend/delete_server_stockout_history.php", {
        method:"POST",
        headers:{"Content-Type":"application/x-www-form-urlencoded"},
        body:"id=" + encodeURIComponent(id)
    })
    .then(response => response.text())
    .then(data => {
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

function openServerStockoutDetail(row){
    const detailId = row.getAttribute("data-detail-id");
    const template = detailId ? document.getElementById(detailId) : null;

    if(!template){
        return;
    }

    document.getElementById("serverStockoutDetailContent").innerHTML = template.innerHTML;

    new bootstrap.Modal(document.getElementById("serverStockoutDetailModal")).show();
}

let serverStockOutTable;

$.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if(settings.nTable.id !== "serverStockOutTable"){
        return true;
    }

    const input = document.getElementById("liveServerStockOutSearch");
    const keyword = input ? input.value.toLowerCase().trim() : "";

    if(keyword === ""){
        return true;
    }

    const terms = keyword.split(",").map(term => term.trim()).filter(term => term !== "");
    const rowNode = settings.aoData[dataIndex].nTr;
    const searchText = rowNode ? (rowNode.getAttribute("data-search") || "") : "";

    return terms.every(term => searchText.includes(term));
});

$(document).ready(function(){
    serverStockOutTable = $("#serverStockOutTable").DataTable({
        pageLength:10,
        lengthMenu:[10,25,50,100],
        ordering:true,
        searching:true,
        autoWidth:false,
        scrollX:false,
        order:[],
        dom:"<'row mb-2 align-items-center'<'col-md-6'l>>rt<'row mt-3 align-items-center server-stockout-bottom-row'<'col-md-6'i><'col-md-6 d-flex justify-content-end'p>>",
        language:{
            zeroRecords:"No records found",
            lengthMenu:"Show _MENU_ entries",
            info:"Showing _START_ to _END_ of _TOTAL_ server stock out records"
        },
        columnDefs:[
            {width:"26%",targets:0},
            {width:"26%",targets:1},
            {width:"32%",targets:2},
            {width:"16%",targets:3,orderable:false,searchable:false}
        ]
    });

    $("#serverStockOutTable tbody").on("click", "tr", function(event){
        if($(event.target).closest("button, a, input, textarea, select, label").length){
            return;
        }

        openServerStockoutDetail(this);
    });

    $("#liveServerStockOutSearch").on("input", function(){
        serverStockOutTable.draw();

        let keyword = this.value.trim();
        let newUrl = "server_stockout.php" + (keyword !== "" ? "?search=" + encodeURIComponent(keyword) : "");

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    serverStockOutTable.draw();
});
</script>
</body>
</html>