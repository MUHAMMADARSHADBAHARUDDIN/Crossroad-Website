<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/search_helper.php";
require_once "../includes/inventory_report_schema.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

function stockoutEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function stockoutAdditionalInfoTableExists($mysqli){
    $result = $mysqli->query("SHOW TABLES LIKE 'stockout_additional_information'");
    return ($result && $result->num_rows > 0);
}

function stockoutDateTimeLocal($value){
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
    FROM stock_out_history
    ORDER BY stock_out_date DESC
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->execute();
$result = $stmt->get_result();

$additionalInformation = [];

if(stockoutAdditionalInfoTableExists($mysqli)){
    $noteStmt = $mysqli->prepare("
        SELECT id, stockout_id, additional_information, added_by, added_at
        FROM stockout_additional_information
        WHERE stockout_type = 'asset'
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
    <title>Asset Stock Out History</title>
    <link rel="icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
    <link rel="shortcut icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
    <link rel="apple-touch-icon" href="../image/logo.png?v=<?= $faviconVersion ?>">
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.js"></script>

<style>
html, body{ overflow-x:hidden !important; }
.main{ overflow-x:hidden !important; max-width:100%; }
.table-responsive{ overflow-x:auto !important; overflow-y:hidden; width:100%; -webkit-overflow-scrolling:touch; overscroll-behavior-x:contain; }

#stockOutTable{
    width:100% !important;
    min-width:900px;
    table-layout:auto;
}

#stockOutTable th,
#stockOutTable td{
    white-space:normal !important;
    word-break:break-word;
    overflow-wrap:anywhere;
    vertical-align:middle;
    font-size:13px;
}

#stockOutTable tbody tr:hover{
    background:#fff3cd !important;
}

#stockOutTable_wrapper{
    width:100%;
    overflow-x:visible !important;
}

#stockOutTable_wrapper .row{
    margin-left:0 !important;
    margin-right:0 !important;
}

#stockOutTable_wrapper .dataTables_length{
    padding-left:0;
}

#stockOutTable_wrapper .dataTables_info{
    padding-top:0;
    font-size:14px;
    color:#6c757d;
}

#stockOutTable_wrapper .dataTables_paginate{
    padding-top:0;
}

#stockOutTable_wrapper .page-item .page-link{
    border-radius:8px;
    margin:0 3px;
    border:none;
}

#stockOutTable_wrapper .page-item.active .page-link{
    background-color:#ffc107;
    color:#000;
}

#stockOutTable tbody tr{
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

.stockout-detail-box span{
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

@media(max-width:992px){
    .table-responsive{
        overflow-x:auto !important;
        -webkit-overflow-scrolling:touch;
    }

    #stockOutTable{
        min-width:940px !important;
        max-width:none !important;
    }

    #stockOutTable th{
        white-space:nowrap !important;
        min-width:140px;
        max-width:none !important;
        word-break:normal !important;
        overflow-wrap:normal !important;
    }

    #stockOutTable td{
        white-space:normal !important;
        min-width:140px;
        max-width:360px !important;
        word-break:break-word !important;
        overflow-wrap:anywhere !important;
    }

    #stockOutTable th:nth-child(3),
    #stockOutTable td:nth-child(3){
        min-width:320px;
    }

    #stockOutTable th:nth-child(4),
    #stockOutTable td:nth-child(4){
        min-width:170px;
    }

    #stockOutTable td:nth-child(4){
        white-space:nowrap !important;
        max-width:none !important;
        word-break:normal !important;
        overflow-wrap:normal !important;
    }

    #stockOutTable_wrapper .stockout-bottom-row{
        gap:10px;
    }

    #stockOutTable_wrapper .dataTables_info,
    #stockOutTable_wrapper .dataTables_paginate{
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
    <h2 class="mb-4">Parts Stock Out History</h2>

    <form method="GET" class="mb-2" onsubmit="return false;">
        <div class="input-group">
            <input type="text"
                   id="liveStockOutSearch"
                   name="search"
                   class="form-control"
                   placeholder="Search by Part Number / Serial / Ticket / Remark / Additional Information..."
                   value="<?= stockoutEscape($search) ?>"
                   autocomplete="off">
            <button type="button" class="btn btn-warning">
                <i class="fa fa-search"></i>
            </button>
        </div>
    </form>

    <div class="table-responsive">
        <?php $stockoutDetailTemplates = []; ?>

        <table class="table table-striped table-hover" id="stockOutTable">
            <thead>
                <tr>
                    <th>Part Number</th>
                    <th>Serial Number</th>
                    <th>Ticket</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php if($result && $result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): ?>
                    <?php
                    $id = (int)$row['id'];
                    $notes = $additionalInformation[$id] ?? [];
                    $rawDate = $row['stock_out_date'] ?? '';
                    $date = (!empty($rawDate) && strtotime($rawDate)) ? date("d/m/y", strtotime($rawDate)) : "-";
                    $time = (!empty($rawDate) && strtotime($rawDate)) ? date("H:i:s", strtotime($rawDate)) : "";
                    $ticketNumber = trim((string)($row['ticket_number'] ?? ''));
                    $notesSearch = "";

                    foreach($notes as $note){
                        $noteDate = !empty($note['added_at']) && strtotime($note['added_at'])
                            ? date("d/m/y H:i", strtotime($note['added_at']))
                            : "";

                        $notesSearch .= " "
                            . ($note['id'] ?? '') . " "
                            . ($note['additional_information'] ?? '') . " "
                            . ($note['added_by'] ?? '') . " "
                            . ($note['added_at'] ?? '') . " "
                            . $noteDate;
                    }

                    $searchText = strtolower(
                        $id . ' ' .
                        ($row['part_number'] ?? '') . ' ' .
                        ($row['serial_number'] ?? '') . ' ' .
                        $ticketNumber . ' ' .
                        ($row['location'] ?? '') . ' ' .
                        ($row['remark'] ?? '') . ' ' .
                        ($row['quantity'] ?? '') . ' ' .
                        ($row['stock_out_by'] ?? '') . ' ' .
                        ($row['stock_out_date'] ?? '') . ' ' .
                        $date . ' ' .
                        $time . ' ' .
                        $notesSearch
                    );

                    $itemLabel = ($row['part_number'] ?? '') . ' / ' . ($row['serial_number'] ?? '');

                    ob_start();
                    ?>

                    <div class="stockout-detail-grid">
                        <div class="stockout-detail-box">
                            <span>Stocked Out By</span>
                            <strong><?= stockoutEscape(($row['stock_out_by'] ?? '') !== '' ? $row['stock_out_by'] : '-') ?></strong>
                        </div>

                        <div class="stockout-detail-box">
                            <span>Date</span>
                            <strong><?= stockoutEscape(trim($date . ' ' . $time)) ?></strong>
                        </div>

                        <div class="stockout-detail-box">
                            <span>Original Remark</span>
                            <strong><?= nl2br(stockoutEscape(($row['remark'] ?? '') !== '' ? $row['remark'] : '-')) ?></strong>
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
                                <div><?= nl2br(stockoutEscape($note['additional_information'])) ?></div>

                                <div class="stockout-note-meta">
                                    <span>
                                        <?= stockoutEscape($note['added_by']) ?> -
                                        <?= stockoutEscape(date("d/m/y H:i", strtotime($note['added_at']))) ?>
                                    </span>

                                    <?php if($canEditAdditionalInformation || $canDeleteAdditionalInformation): ?>
                                        <div class="stockout-note-actions">
                                            <?php if($canEditAdditionalInformation): ?>
                                                <button type="button"
                                                        class="stockout-note-edit"
                                                        title="Edit this additional information"
                                                        onclick='openEditAdditionalInformationModal(<?= (int)$note['id'] ?>, <?= stockoutEscape(json_encode($note['additional_information'] ?? '')) ?>)'>
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
                    $stockoutDetailTemplates[$id] = ob_get_clean();
                    ?>

                    <tr data-search="<?= stockoutEscape($searchText) ?>"
                        data-detail-id="stockout-detail-<?= $id ?>">
                        <td><?= stockoutEscape($row['part_number'] ?? '') ?></td>
                        <td><?= stockoutEscape($row['serial_number'] ?? '') ?></td>
                        <td><?= stockoutEscape($ticketNumber !== '' ? $ticketNumber : '-') ?></td>

                        <td>
                            <div class="action-buttons">
                                <?php if($canAddInformation): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-warning"
                                            title="Add additional information"
                                            onclick='openAdditionalInfoModal(<?= $id ?>, <?= stockoutEscape(json_encode($itemLabel)) ?>)'>
                                        <i class="fa fa-plus"></i>
                                    </button>
                                <?php endif; ?>

                                <?php if($canDelete): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-danger"
                                            title="Delete history"
                                            onclick="deleteStockOutHistory(<?= $id ?>)">
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
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="d-none" id="stockoutDetailTemplates">
        <?php foreach($stockoutDetailTemplates as $templateId => $templateHtml): ?>
            <div id="stockout-detail-<?= (int)$templateId ?>"><?= $templateHtml ?></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="stockoutDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="fa fa-circle-info text-warning"></i>
                    Stock Out Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="stockoutDetailContent"></div>
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
                    <strong>Asset:</strong>
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
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "stockout_type=asset&stockout_id=" + encodeURIComponent(id) +
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

function deleteStockOutHistory(id){
    if(!confirm("Delete this stock out history record and its additional information?")){
        return;
    }

    fetch("../backend/delete_stockout_history.php", {
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
    });
}

function openStockoutDetail(row){
    const detailId = row.getAttribute("data-detail-id");
    const template = detailId ? document.getElementById(detailId) : null;

    if(!template){
        return;
    }

    document.getElementById("stockoutDetailContent").innerHTML = template.innerHTML;

    new bootstrap.Modal(document.getElementById("stockoutDetailModal")).show();
}

let stockOutTable;

$.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if(settings.nTable.id !== "stockOutTable"){
        return true;
    }

    const input = document.getElementById("liveStockOutSearch");
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
    stockOutTable = $("#stockOutTable").DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        ordering: true,
        searching: true,
        autoWidth: false,
        scrollX: false,
        order: [],
        dom: "<'row mb-2 align-items-center'<'col-md-6'l>>rt<'row mt-3 align-items-center stockout-bottom-row'<'col-md-6'i><'col-md-6 d-flex justify-content-end'p>>",
        language: {
            zeroRecords:"No records found",
            lengthMenu:"Show _MENU_ entries",
            info:"Showing _START_ to _END_ of _TOTAL_ stock out records"
        },
        columnDefs: [
            {width:"24%", targets:0},
            {width:"24%", targets:1},
            {width:"34%", targets:2},
            {width:"18%", targets:3, orderable:false, searchable:false}
        ]
    });

    $("#stockOutTable tbody").on("click", "tr", function(event){
        if($(event.target).closest("button, a, input, textarea, select, label").length){
            return;
        }

        openStockoutDetail(this);
    });

    $("#liveStockOutSearch").on("input", function(){
        stockOutTable.draw();

        let keyword = this.value.trim();
        let newUrl = "stock_out.php" + (keyword !== "" ? "?search=" + encodeURIComponent(keyword) : "");

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    stockOutTable.draw();
});
</script>
</body>
</html>
