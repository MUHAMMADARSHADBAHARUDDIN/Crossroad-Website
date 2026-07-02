<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

if(!hasPermission($mysqli, "office_inventory_view")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

$faviconVersion = file_exists("../image/logo.png") ? filemtime("../image/logo.png") : time();
$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$canAdd = hasPermission($mysqli, "office_inventory_add");
$canEdit = hasPermission($mysqli, "office_inventory_edit");
$canDelete = hasPermission($mysqli, "office_inventory_delete");
$search = trim($_GET['search'] ?? "");

function officeInventoryEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function officeInventoryFormatDate($value){
    $value = trim((string)($value ?? ''));

    if($value === "" || $value === "0000-00-00"){
        return "";
    }

    $timestamp = strtotime($value);

    if($timestamp === false){
        return $value;
    }

    return date("d/m/y", $timestamp);
}

function officeInventoryFormatDateTime($value){
    $value = trim((string)($value ?? ''));

    if($value === "" || $value === "0000-00-00 00:00:00"){
        return "";
    }

    $timestamp = strtotime($value);

    if($timestamp === false){
        return $value;
    }

    return date("d/m/y H:i", $timestamp);
}

if(isset($_POST['delete_office_id']) && $canDelete){
    $deleteId = (int)$_POST['delete_office_id'];

    if($deleteId > 0){
        $fetchStmt = $mysqli->prepare("
            SELECT owner, serial_number, brand, model
            FROM laptop_inventory
            WHERE id = ?
            LIMIT 1
        ");

        if(!$fetchStmt){
            die("SQL Error: " . $mysqli->error);
        }

        $fetchStmt->bind_param("i", $deleteId);
        $fetchStmt->execute();
        $deleteRow = $fetchStmt->get_result()->fetch_assoc();

        if($deleteRow){
            $deleteStmt = $mysqli->prepare("DELETE FROM laptop_inventory WHERE id = ? LIMIT 1");

            if(!$deleteStmt){
                die("SQL Error: " . $mysqli->error);
            }

            $deleteStmt->bind_param("i", $deleteId);

            if($deleteStmt->execute()){
                $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
                $time = date("Y-m-d H:i:s");

                $description = "User [$username] deleted office inventory.
Owner: {$deleteRow['owner']}
Serial Number: {$deleteRow['serial_number']}
Brand: {$deleteRow['brand']}
Model: {$deleteRow['model']}
IP Address: $ip
Time: $time";

                logActivity($mysqli, $username, $role, "DELETE OFFICE INVENTORY", $description);

                header("Location: office_inventory.php");
                exit();
            }
        }
    }
}

$result = $mysqli->query("
    SELECT *
    FROM laptop_inventory
    ORDER BY id DESC
");

if(!$result){
    die("SQL Error: " . $mysqli->error);
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Office Inventory</title>

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
    overflow-y:visible;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior-x:contain;
    width:100%;
}

#officeInventoryTable{
    width:100% !important;
    min-width:640px;
    table-layout:auto;
}

#officeInventoryTable th,
#officeInventoryTable td{
    white-space:normal !important;
    word-break:break-word;
    overflow-wrap:anywhere;
    vertical-align:middle;
}

#officeInventoryTable tbody tr{
    cursor:pointer;
}

#officeInventoryTable tbody tr:hover{
    background:#fff3cd !important;
}

#officeInventoryTable_wrapper{
    width:100%;
    overflow:visible;
}

#officeInventoryTable_wrapper .dataTables_length select{
    width:auto;
    min-width:72px;
    display:inline-block;
}

#officeInventoryTable_wrapper .pagination{
    flex-wrap:wrap;
    gap:4px;
}

#officeInventoryTable_wrapper .page-link{
    color:#856404;
}

#officeInventoryTable_wrapper .page-item.active .page-link{
    background-color:#ffc107;
    border-color:#ffc107;
    color:#000;
}

.office-hover-card{
    display:none;
    position:fixed;
    z-index:1050;
    left:0;
    top:0;
    min-width:280px;
    max-width:min(420px, calc(100vw - 48px));
    background:#fff;
    border:1px solid #dee2e6;
    border-radius:8px;
    box-shadow:0 8px 24px rgba(0,0,0,0.12);
    padding:12px;
    pointer-events:none;
    box-sizing:border-box;
}

.office-hover-card.is-visible{
    display:block;
}

.office-hover-line{
    color:#212529;
    font-weight:600;
    line-height:1.25;
    max-width:100%;
    white-space:normal;
    word-break:break-word;
    overflow-wrap:anywhere;
}

.office-detail-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
}

.office-detail-box{
    background:#f8f9fa;
    border:1px solid #e9ecef;
    border-radius:10px;
    padding:12px;
}

.office-detail-box span{
    display:block;
    color:#6c757d;
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    margin-bottom:4px;
}

.office-detail-box strong{
    display:block;
    color:#212529;
    word-break:break-word;
}

@media(max-width:768px){
    #officeInventoryTable{
        min-width:640px;
    }

    #officeInventoryTable th,
    #officeInventoryTable td{
        font-size:13px;
        padding:8px;
    }

    .office-detail-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">Office Inventory</h2>

<form method="GET" class="mb-2" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveOfficeSearch"
            name="search"
            class="form-control"
            placeholder="Search... Example: owner, serial, license"
            value="<?= officeInventoryEscape($search) ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i>
        </button>
    </div>
</form>

<?php if($canAdd): ?>
<a href="office_add.php" class="btn btn-warning mb-3">
    <i class="fa fa-plus"></i> Add Office Inventory
</a>
<?php endif; ?>

<div class="table-responsive">
<?php $officeInventoryDetailTemplates = []; ?>
<table class="table table-striped table-hover align-middle" id="officeInventoryTable">
<thead>
<tr>
    <th>Owner</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php while($row = $result->fetch_assoc()): ?>
    <?php
    $id = (int)$row['id'];
    $office365 = trim((string)($row['office365_license'] ?? ''));
    $antivirus = trim((string)($row['antivirus_license'] ?? ''));
    $hoverValues = array_values(array_filter([
        trim((string)($row['brand'] ?? '')),
        trim((string)($row['model'] ?? '')),
        trim((string)($row['serial_number'] ?? '')),
        $office365,
        $antivirus
    ], function($value){
        return $value !== '';
    }));
    $hoverText = !empty($hoverValues) ? implode(', ', $hoverValues) : '-';
    $deliveryDate = officeInventoryFormatDate($row['delivery_date'] ?? '');
    $createdAt = officeInventoryFormatDateTime($row['created_at'] ?? '');
    $updatedAt = officeInventoryFormatDateTime($row['updated_at'] ?? '');
    $licenseExpiredDate = officeInventoryFormatDate($row['license_expired_date'] ?? '');
    $searchText = strtolower(
        ($row['owner'] ?? '') . ' ' .
        ($row['brand'] ?? '') . ' ' .
        ($row['model'] ?? '') . ' ' .
        ($row['serial_number'] ?? '') . ' ' .
        ($office365 !== '' ? $office365 : '') . ' ' .
        ($antivirus !== '' ? $antivirus : '') . ' ' .
        ($row['license_type'] ?? '') . ' ' .
        ($row['license_ownership'] ?? '') . ' ' .
        ($row['license_family'] ?? '') . ' ' .
        ($row['license_family_details'] ?? '') . ' ' .
        ($row['delivery_date'] ?? '') . ' ' .
        $deliveryDate . ' ' .
        ($row['license_expired_date'] ?? '') . ' ' .
        $licenseExpiredDate
    );

    ob_start();
    ?>
    <div class="office-detail-grid">
        <div class="office-detail-box">
            <span>Owner</span>
            <strong><?= officeInventoryEscape(($row['owner'] ?? '') !== '' ? $row['owner'] : '-') ?></strong>
        </div>
        <div class="office-detail-box">
            <span>Delivery Date</span>
            <strong><?= officeInventoryEscape($deliveryDate !== '' ? $deliveryDate : '-') ?></strong>
        </div>
        <div class="office-detail-box">
            <span>Serial Number</span>
            <strong><?= officeInventoryEscape(($row['serial_number'] ?? '') !== '' ? $row['serial_number'] : '-') ?></strong>
        </div>
        <div class="office-detail-box">
            <span>Brand</span>
            <strong><?= officeInventoryEscape(($row['brand'] ?? '') !== '' ? $row['brand'] : '-') ?></strong>
        </div>
        <div class="office-detail-box">
            <span>Model</span>
            <strong><?= officeInventoryEscape(($row['model'] ?? '') !== '' ? $row['model'] : '-') ?></strong>
        </div>
        <div class="office-detail-box">
            <span>Created By</span>
            <strong><?= officeInventoryEscape(($row['created_by'] ?? '') !== '' ? $row['created_by'] : '-') ?></strong>
        </div>
        <div class="office-detail-box">
            <span>Created At</span>
            <strong><?= officeInventoryEscape($createdAt !== '' ? $createdAt : '-') ?></strong>
        </div>
        <div class="office-detail-box">
            <span>Last Updated</span>
            <strong><?= officeInventoryEscape($updatedAt !== '' ? $updatedAt : '-') ?></strong>
        </div>
    </div>
    <?php
    $officeInventoryDetailTemplates[$id] = ob_get_clean();
    ?>
    <tr
        class="office-owner-row"
        data-search="<?= officeInventoryEscape($searchText) ?>"
        data-detail-id="office-inventory-detail-<?= $id ?>"
        onclick="openOfficeInventoryDetail(this)"
    >
        <td class="office-owner-cell">
            <span class="fw-semibold"><?= officeInventoryEscape($row['owner'] ?? '') ?></span>
            <div class="office-hover-card">
                <div class="office-hover-line"><?= officeInventoryEscape($hoverText) ?></div>
            </div>
        </td>
        <td>
            <div class="d-flex flex-wrap gap-1">
                <?php if($canEdit): ?>
                    <a href="office_edit.php?id=<?= $id ?>"
                       class="btn btn-sm btn-primary"
                       onclick="event.stopPropagation();"
                       title="Edit">
                        <i class="fa fa-pen"></i>
                    </a>
                <?php endif; ?>

                <?php if($canDelete): ?>
                    <form method="POST" class="d-inline" onsubmit="event.stopPropagation(); return confirm('Delete this office inventory record?');">
                        <input type="hidden" name="delete_office_id" value="<?= $id ?>">
                        <button type="submit"
                                class="btn btn-sm btn-danger"
                                onclick="event.stopPropagation();"
                                title="Delete">
                            <i class="fa fa-trash"></i>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if(!$canEdit && !$canDelete): ?>
                    <span class="badge bg-secondary">View Only</span>
                <?php endif; ?>
            </div>
        </td>
    </tr>
<?php endwhile; ?>
</tbody>
</table>
</div>

<div class="d-none" id="officeInventoryDetailTemplates">
    <?php foreach($officeInventoryDetailTemplates as $templateId => $templateHtml): ?>
        <div id="office-inventory-detail-<?= (int)$templateId ?>"><?= $templateHtml ?></div>
    <?php endforeach; ?>
</div>

</div>

<div class="modal fade" id="officeInventoryDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">
          <i class="fa fa-circle-info text-warning"></i>
          Office Inventory Details
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="officeInventoryDetailContent"></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
let officeInventoryTable;

function hideOfficeHoverCards(){
    document.querySelectorAll(".office-hover-card.is-visible").forEach(function(card){
        card.classList.remove("is-visible");
    });
}

function openOfficeInventoryDetail(row){
    hideOfficeHoverCards();

    const detailId = row.getAttribute("data-detail-id");
    const template = detailId ? document.getElementById(detailId) : null;

    if(!template){
        return;
    }

    document.getElementById("officeInventoryDetailContent").innerHTML = template.innerHTML;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("officeInventoryDetailModal")).show();
}

function positionOfficeHoverCard(card, event){
    const gap = 14;
    const cardRect = card.getBoundingClientRect();
    let left = event.clientX + gap;
    let top = event.clientY + gap;

    if(left + cardRect.width > window.innerWidth - gap){
        left = event.clientX - cardRect.width - gap;
    }

    if(top + cardRect.height > window.innerHeight - gap){
        top = event.clientY - cardRect.height - gap;
    }

    card.style.left = Math.max(gap, left) + "px";
    card.style.top = Math.max(gap, top) + "px";
}

document.addEventListener("mousemove", function(event){
    const row = event.target.closest(".office-owner-row");

    document.querySelectorAll(".office-hover-card.is-visible").forEach(function(card){
        if(!row || !row.contains(card)){
            card.classList.remove("is-visible");
        }
    });

    if(!row){
        return;
    }

    const card = row.querySelector(".office-hover-card");

    if(!card){
        return;
    }

    card.classList.add("is-visible");
    positionOfficeHoverCard(card, event);
});

document.addEventListener("mouseleave", function(){
    hideOfficeHoverCards();
});

$.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if(settings.nTable.id !== "officeInventoryTable"){
        return true;
    }

    const input = document.getElementById("liveOfficeSearch");
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
    officeInventoryTable = $("#officeInventoryTable").DataTable({
        pageLength:10,
        lengthMenu:[10,25,50,100],
        ordering:true,
        searching:true,
        autoWidth:false,
        scrollX:false,
        order:[],
        dom:"<'row mb-2 align-items-center'<'col-md-6'l>>rt<'row mt-3 align-items-center office-bottom-row'<'col-md-6'i><'col-md-6 d-flex justify-content-end'p>>",
        language:{
            zeroRecords:"No records found",
            lengthMenu:"Show _MENU_ entries",
            info:"Showing _START_ to _END_ of _TOTAL_ office records"
        },
        columnDefs:[
            {width:"75%", targets:0},
            {width:"25%", targets:1, orderable:false, searchable:false}
        ]
    });

    $("#liveOfficeSearch").on("input", function(){
        officeInventoryTable.draw();

        let keyword = this.value.trim();
        let newUrl = "office_inventory.php" + (keyword !== "" ? "?search=" + encodeURIComponent(keyword) : "");

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    officeInventoryTable.draw();
});
</script>

<?php include "layout/footer.php"; ?>
