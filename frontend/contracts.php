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

function contractEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function bindStatementParams($stmt, $types, $params){
    if($types === "" || empty($params)){
        return;
    }

    $refs = [];

    foreach($params as $key => $value){
        $refs[$key] = &$params[$key];
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

$statusCase = "
CASE
    WHEN contract_end IS NOT NULL
         AND contract_end <> ''
         AND contract_end <> '0000-00-00'
         AND contract_end < CURDATE()
    THEN 'Closed'

    WHEN contract_end IS NOT NULL
         AND contract_end <> ''
         AND contract_end <> '0000-00-00'
         AND contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    THEN 'Expiring Soon'

    ELSE 'Active'
END
";

/* =========================================================
   AJAX SERVER-SIDE DATATABLE RESPONSE
========================================================= */
if(isset($_GET['ajax']) && $_GET['ajax'] == "1"){

    header("Content-Type: application/json");

    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
    $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;

    if($length <= 0 || $length > 100){
        $length = 10;
    }

    $search = "";
    if(isset($_GET['search']['value'])){
        $search = trim($_GET['search']['value']);
    }

    $statusFilter = trim($_GET['status_filter'] ?? "");

    $orderColumnIndex = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 0;
    $orderDirection = isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === "asc"
        ? "ASC"
        : "DESC";

    $orderColumnMap = [
        0 => "no",
        1 => "year_awarded",
        2 => "project_name",
        3 => "project_owner",
        4 => "auto_status",
        5 => "contract_start",
        6 => "contract_end",
        7 => "amount"
    ];

    /* ✅ DEFAULT LOAD BASED ON NUMBER */
    $orderBy = "no DESC";

    if(isset($orderColumnMap[$orderColumnIndex])){
        $selectedOrderColumn = $orderColumnMap[$orderColumnIndex];

        if($selectedOrderColumn === "auto_status"){
            $orderBy = "$statusCase $orderDirection";
        }
        elseif($selectedOrderColumn === "amount"){
            $orderBy = "CAST(amount AS DECIMAL(15,2)) $orderDirection";
        }
        else{
            $orderBy = "$selectedOrderColumn $orderDirection";
        }
    }

    /* TOTAL RECORDS */
    $totalResult = $mysqli->query("SELECT COUNT(*) AS total FROM project_inventory");
    $recordsTotal = $totalResult ? (int)$totalResult->fetch_assoc()['total'] : 0;

    $whereParts = [];
    $params = [];
    $types = "";

    /*
        ✅ MULTI SEARCH SUPPORT
        Example:
        2026, active

        This means:
        - row must match 2026 somewhere
        - AND row must match active somewhere

        Each comma item searches across:
        no, year, project name, owner, end user, contract no,
        service, po date, start date, end date, amount, status
    */
    $searchTerms = [];

    if($search !== ""){
        $rawTerms = explode(",", $search);

        foreach($rawTerms as $term){
            $term = trim($term);

            if($term !== ""){
                $searchTerms[] = $term;
            }
        }
    }

    foreach($searchTerms as $term){
        $searchLike = "%" . $term . "%";

        $whereParts[] = "
        (
            CAST(no AS CHAR) LIKE ?
            OR CAST(year_awarded AS CHAR) LIKE ?
            OR project_name LIKE ?
            OR project_owner LIKE ?
            OR end_user LIKE ?
            OR contract_no LIKE ?
            OR service LIKE ?
            OR po_date LIKE ?
            OR contract_start LIKE ?
            OR contract_end LIKE ?
            OR CAST(amount AS CHAR) LIKE ?
            OR $statusCase LIKE ?
        )
        ";

        for($i = 0; $i < 12; $i++){
            $params[] = $searchLike;
            $types .= "s";
        }
    }

    if($statusFilter !== ""){
        $whereParts[] = "$statusCase = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }

    $whereSql = "";

    if(count($whereParts) > 0){
        $whereSql = "WHERE " . implode(" AND ", $whereParts);
    }

    /* FILTERED COUNT */
    if(count($whereParts) > 0){
        $countStmt = $mysqli->prepare("
            SELECT COUNT(*) AS total
            FROM project_inventory
            $whereSql
        ");

        if(!$countStmt){
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => 0,
                "recordsFiltered" => 0,
                "data" => [],
                "error" => $mysqli->error
            ]);
            exit();
        }

        bindStatementParams($countStmt, $types, $params);

        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $recordsFiltered = (int)$countResult->fetch_assoc()['total'];
    } else {
        $recordsFiltered = $recordsTotal;
    }

    /* DATA QUERY */
    $sql = "
        SELECT
            no,
            year_awarded,
            project_name,
            project_owner,
            end_user,
            contract_no,
            service,
            po_date,
            contract_start,
            contract_end,
            amount,
            created_by,
            $statusCase AS auto_status
        FROM project_inventory
        $whereSql
        ORDER BY $orderBy
        LIMIT ?
        OFFSET ?
    ";

    $stmt = $mysqli->prepare($sql);

    if(!$stmt){
        echo json_encode([
            "draw" => $draw,
            "recordsTotal" => 0,
            "recordsFiltered" => 0,
            "data" => [],
            "error" => $mysqli->error
        ]);
        exit();
    }

    $typesWithLimit = $types . "ii";
    $paramsWithLimit = array_merge($params, [$length, $start]);

    bindStatementParams($stmt, $typesWithLimit, $paramsWithLimit);

    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];

    while($row = $result->fetch_assoc()){

        $created_by = $row['created_by'] ?? "";

        $canEditThisContract = hasContractEditAccess($mysqli, $created_by);
        $canDeleteThisContract = hasContractDeleteAccess($mysqli, $created_by);
        $canUploadThisContract = hasContractUploadAccess($mysqli, $created_by);

        $auto_status = $row['auto_status'] ?? "Active";

        if($auto_status === "Closed"){
            $statusHtml = '<span class="badge bg-danger">Closed</span>';
        }
        elseif($auto_status === "Expiring Soon"){
            $statusHtml = '<span class="badge bg-warning text-dark">Expiring Soon</span>';
        }
        else{
            $statusHtml = '<span class="badge bg-success">Active</span>';
        }

        $actionsHtml = "";

        if($canEditThisContract){
            $actionsHtml .= '
                <a href="contract_edit.php?id=' . contractEscape($row['no']) . '"
                   class="btn btn-sm btn-primary">
                    Edit
                </a>
            ';
        }

        if($canDeleteThisContract){
            $actionsHtml .= '
                <a href="../backend/contract_delete.php?id=' . contractEscape($row['no']) . '"
                   class="btn btn-sm btn-danger"
                   onclick="return confirm(\'Delete this contract?\')">
                    Delete
                </a>
            ';
        }

        if(!$canEditThisContract && !$canDeleteThisContract){
            $actionsHtml = '<span class="badge bg-secondary">View Only</span>';
        }

        $data[] = [
            "no" => contractEscape($row['no']),
            "year" => contractEscape($row['year_awarded']),
            "project_name" => contractEscape($row['project_name']),
            "owner" => contractEscape($row['project_owner']),
            "status" => $statusHtml,
            "start" => contractEscape($row['contract_start']),
            "end" => contractEscape($row['contract_end']),
            "amount" => "RM " . number_format((float)$row['amount'], 2),
            "actions" => $actionsHtml,

            "meta" => [
                "id" => $row['no'],
                "year" => $row['year_awarded'],
                "project" => $row['project_name'],
                "owner" => $row['project_owner'],
                "createdby" => $created_by,
                "canupload" => $canUploadThisContract ? "1" : "0",
                "enduser" => $row['end_user'],
                "contractno" => $row['contract_no'],
                "service" => $row['service'],
                "podate" => $row['po_date'],
                "start" => $row['contract_start'],
                "end" => $row['contract_end'],
                "status" => $auto_status,
                "amount" => $row['amount']
            ]
        ];
    }

    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $recordsTotal,
        "recordsFiltered" => $recordsFiltered,
        "data" => $data
    ]);

    exit();
}

/* NORMAL PAGE LOAD SEARCH VALUE */
$search = "";
if(isset($_GET['search'])){
    $search = trim($_GET['search']);
}
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

<style>
#contractsTable tbody tr{
    cursor:pointer;
}

#contractsTable tbody tr:hover{
    background:#f8fbff;
}

/* ✅ ONLY STATUS HEADER FILTER IS CLICKABLE */
.header-filter{
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:5px;
    color:#0d6efd;
}

.header-filter:hover{
    text-decoration:underline;
}

.status-filter-menu{
    position:absolute;
    z-index:9999;
    display:none;
    background:#fff;
    border:1px solid #ddd;
    border-radius:10px;
    box-shadow:0 8px 20px rgba(0,0,0,0.12);
    min-width:180px;
    overflow:hidden;
}

.status-filter-menu button{
    width:100%;
    border:0;
    background:#fff;
    padding:10px 14px;
    text-align:left;
}

.status-filter-menu button:hover{
    background:#f5f7ff;
}

.contract-filter-hint{
    font-size:13px;
    color:#6c757d;
}

.active-filter-box{
    display:none;
    font-size:13px;
    background:#fff3cd;
    border:1px solid #ffe69c;
    color:#664d03;
    border-radius:8px;
    padding:8px 10px;
    margin-bottom:12px;
}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-3">Contracts</h2>

<form method="GET" class="mb-2" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveContractSearch"
            name="search"
            class="form-control"
            placeholder="Search contracts... Example: 2026, active"
            value="<?= contractEscape($search) ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i> Search
        </button>
    </div>
</form>

<div class="contract-filter-hint mb-2">
    Use comma to search multiple terms, example: <b>2026, active</b>. Click the blue Status header to filter status.
</div>

<div id="activeFilterBox" class="active-filter-box"></div>

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

    <th>
        <span class="header-filter" id="statusHeaderFilter">
            Status <i class="fa fa-filter"></i>
        </span>
    </th>

    <th>Start</th>
    <th>End</th>
    <th>Amount</th>
    <th>Actions</th>
</tr>
</thead>

<tbody></tbody>

</table>

</div>

<!-- STATUS HEADER FILTER MENU -->
<div id="statusFilterMenu" class="status-filter-menu">
    <button type="button" data-status="">All Status</button>
    <button type="button" data-status="Active">Active</button>
    <button type="button" data-status="Expiring Soon">Expiring Soon</button>
    <button type="button" data-status="Closed">Closed</button>
</div>

<!-- CONTRACT MODAL -->
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

    let initialSearch = <?= json_encode($search) ?>;
    let typingTimer = null;

    let statusFilter = "";

    let contractsTable = $('#contractsTable').DataTable({
        processing: true,
        serverSide: true,
        pageLength: 10,
        searching: true,
        search: {
            search: initialSearch
        },
        order: [[0, "desc"]],
        dom: "rtip",
        ajax: {
            url: "contracts.php",
            type: "GET",
            data: function(d){
                d.ajax = 1;
                d.status_filter = statusFilter;
            }
        },
        columns: [
            { data: "no" },
            { data: "year" },
            { data: "project_name" },
            { data: "owner" },
            { data: "status" },
            { data: "start" },
            { data: "end" },
            { data: "amount" },
            {
                data: "actions",
                orderable: false,
                searchable: false
            }
        ],
        createdRow: function(row, data){
            $(row).attr("data-id", data.meta.id);
            $(row).attr("data-year", data.meta.year);
            $(row).attr("data-project", data.meta.project);
            $(row).attr("data-owner", data.meta.owner);
            $(row).attr("data-createdby", data.meta.createdby);
            $(row).attr("data-canupload", data.meta.canupload);
            $(row).attr("data-enduser", data.meta.enduser);
            $(row).attr("data-contractno", data.meta.contractno);
            $(row).attr("data-service", data.meta.service);
            $(row).attr("data-podate", data.meta.podate);
            $(row).attr("data-start", data.meta.start);
            $(row).attr("data-end", data.meta.end);
            $(row).attr("data-status", data.meta.status);
            $(row).attr("data-amount", data.meta.amount);
        }
    });

    function updateActiveFilterBox(){
        let filters = [];

        if(statusFilter !== ""){
            filters.push("Status: " + statusFilter);
        }

        let searchText = $("#liveContractSearch").val().trim();

        if(searchText !== ""){
            filters.push("Search: " + searchText);
        }

        if(filters.length > 0){
            $("#activeFilterBox")
                .html("<strong>Active Filter:</strong> " + filters.join(" | "))
                .show();
        }else{
            $("#activeFilterBox").hide().html("");
        }
    }

    function runContractSearch(value){
        contractsTable.search(value).draw();

        let newUrl = "contracts.php";

        if(value.trim() !== ""){
            newUrl += "?search=" + encodeURIComponent(value.trim());
        }

        window.history.replaceState(null, "", newUrl);
        updateActiveFilterBox();
    }

    $("#liveContractSearch").on("input", function(){
        let value = this.value;

        clearTimeout(typingTimer);

        typingTimer = setTimeout(function(){
            runContractSearch(value);
        }, 250);
    });

    $("#clearContractSearch").on("click", function(){
        $("#liveContractSearch").val("");

        statusFilter = "";

        $("#statusFilterMenu").hide();

        contractsTable.search("").draw();
        window.history.replaceState(null, "", "contracts.php");

        updateActiveFilterBox();
    });

    /* =========================================================
       ✅ STATUS HEADER FILTER ONLY
    ========================================================= */
    $("#statusHeaderFilter").on("click", function(e){
        e.preventDefault();
        e.stopPropagation();

        let menu = $("#statusFilterMenu");
        let offset = $(this).offset();

        menu.css({
            top: offset.top + $(this).outerHeight() + 6,
            left: offset.left
        }).toggle();
    });

    $("#statusFilterMenu button").on("click", function(e){
        e.preventDefault();
        e.stopPropagation();

        statusFilter = $(this).data("status");
        $("#statusFilterMenu").hide();

        contractsTable.ajax.reload();
        updateActiveFilterBox();
    });

    $(document).on("click", function(){
        $("#statusFilterMenu").hide();
    });

    /* =========================================================
       ✅ DATA ROW CLICK OPENS POPUP
    ========================================================= */
    function openContractModal(data){
        let meta = data.meta;

        $('#m_id').val(meta.id);

        $('#m_project').text(meta.project || '');
        $('#m_owner').text(meta.owner || '');
        $('#m_createdby').text(meta.createdby || '-');
        $('#m_year').text(meta.year || '');
        $('#m_enduser').text(meta.enduser || '');
        $('#m_contractno').text(meta.contractno || '');
        $('#m_service').text(meta.service || '');
        $('#m_podate').text(meta.podate || '');
        $('#m_start').text(meta.start || '');
        $('#m_end').text(meta.end || '');

        let status = meta.status || "Active";

        if(status === "Closed"){
            $('#m_status').html('<span class="badge bg-danger">Closed</span>');
        }
        else if(status === "Expiring Soon"){
            $('#m_status').html('<span class="badge bg-warning text-dark">Expiring Soon</span>');
        }
        else{
            $('#m_status').html('<span class="badge bg-success">Active</span>');
        }

        let amount = parseFloat(meta.amount);

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

        let canUploadThisContract = meta.canupload == 1;
        $('#uploadSection').toggle(canUploadThisContract);

        $('#filesContainer').html("Loading...");

        $.post("../backend/get_contract_files.php", {id: meta.id}, function(fileData){
            $('#filesContainer').html(fileData);
        });

        new bootstrap.Modal(document.getElementById('contractModal')).show();
    }

    $('#contractsTable tbody').on('click','tr',function(e){

        if($(e.target).closest('a,button').length){
            return;
        }

        let rowData = contractsTable.row(this).data();

        if(!rowData){
            return;
        }

        openContractModal(rowData);
    });

    updateActiveFilterBox();

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