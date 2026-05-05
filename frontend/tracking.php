<?php
global $mysqli;
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

/* ✅ ACTIVITY TRACKER ONLY REAL ADMIN */
if(($_SESSION['role'] ?? '') !== "Administrator"){
    die("Access denied.");
}

$username = $_SESSION['username'] ?? "Unknown";
$role = $_SESSION['role'] ?? "Unknown";

/* =========================
   HELPER FUNCTIONS
========================= */
function trackingEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function bindTrackingParams($stmt, $types, $params){
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

function buildTrackingSearchWhere($search, &$params, &$types){
    $searchTerms = [];

    if(trim($search) !== ""){
        $rawTerms = explode(",", $search);

        foreach($rawTerms as $term){
            $term = trim($term);

            if($term !== ""){
                $searchTerms[] = $term;
            }
        }
    }

    $whereParts = [];

    foreach($searchTerms as $term){
        $searchLike = "%" . $term . "%";

        $whereParts[] = "
        (
            CAST(id AS CHAR) LIKE ?
            OR username LIKE ?
            OR role LIKE ?
            OR action_type LIKE ?
            OR description LIKE ?
            OR CAST(log_time AS CHAR) LIKE ?
        )
        ";

        for($i = 0; $i < 6; $i++){
            $params[] = $searchLike;
            $types .= "s";
        }
    }

    if(count($whereParts) > 0){
        return "WHERE " . implode(" AND ", $whereParts);
    }

    return "";
}

function cleanLogIds($idsRaw){
    if(!is_array($idsRaw)){
        $idsRaw = explode(",", (string)$idsRaw);
    }

    $ids = [];

    foreach($idsRaw as $id){
        $id = (int)$id;

        if($id > 0){
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/* =========================
   DELETE SINGLE LOG AJAX
========================= */
if($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === "delete_log"){

    header("Content-Type: text/plain");

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if($id <= 0){
        exit("Invalid log ID");
    }

    $checkStmt = $mysqli->prepare("
        SELECT id, username, role, action_type, log_time
        FROM activity_logs
        WHERE id = ?
        LIMIT 1
    ");

    if(!$checkStmt){
        exit("Prepare failed: " . $mysqli->error);
    }

    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $logData = $checkResult->fetch_assoc();

    if(!$logData){
        exit("Log not found");
    }

    $deleteStmt = $mysqli->prepare("
        DELETE FROM activity_logs
        WHERE id = ?
    ");

    if(!$deleteStmt){
        exit("Prepare failed: " . $mysqli->error);
    }

    $deleteStmt->bind_param("i", $id);

    if($deleteStmt->execute()){

        $ip = $_SERVER['REMOTE_ADDR'];
        $time = date("Y-m-d H:i:s");

        $description = "Admin [$username] deleted an activity log.
Deleted Log ID: {$logData['id']}
Deleted Log Username: {$logData['username']}
Deleted Log Role: {$logData['role']}
Deleted Log Action: {$logData['action_type']}
Deleted Log Time: {$logData['log_time']}
IP Address: $ip
Time: $time";

        logActivity(
            $mysqli,
            $username,
            $role,
            "DELETE ACTIVITY LOG",
            $description
        );

        exit("success");
    }

    exit("Delete failed: " . $mysqli->error);
}

/* =========================
   DELETE MULTIPLE LOGS AJAX
========================= */
if($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === "delete_selected_logs"){

    header("Content-Type: text/plain");

    $ids = cleanLogIds($_POST['ids'] ?? []);

    if(empty($ids)){
        exit("No logs selected");
    }

    $placeholders = implode(",", array_fill(0, count($ids), "?"));
    $types = str_repeat("i", count($ids));

    $checkStmt = $mysqli->prepare("
        SELECT id, username, role, action_type, log_time
        FROM activity_logs
        WHERE id IN ($placeholders)
        ORDER BY id ASC
    ");

    if(!$checkStmt){
        exit("Prepare failed: " . $mysqli->error);
    }

    bindTrackingParams($checkStmt, $types, $ids);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    $existingLogs = [];
    $existingIds = [];

    while($row = $checkResult->fetch_assoc()){
        $existingLogs[] = $row;
        $existingIds[] = (int)$row['id'];
    }

    if(empty($existingIds)){
        exit("Selected logs not found");
    }

    $deletePlaceholders = implode(",", array_fill(0, count($existingIds), "?"));
    $deleteTypes = str_repeat("i", count($existingIds));

    $deleteStmt = $mysqli->prepare("
        DELETE FROM activity_logs
        WHERE id IN ($deletePlaceholders)
    ");

    if(!$deleteStmt){
        exit("Prepare failed: " . $mysqli->error);
    }

    bindTrackingParams($deleteStmt, $deleteTypes, $existingIds);

    if($deleteStmt->execute()){

        $deletedCount = $deleteStmt->affected_rows;
        $ip = $_SERVER['REMOTE_ADDR'];
        $time = date("Y-m-d H:i:s");

        $idPreview = implode(", ", array_slice($existingIds, 0, 50));

        if(count($existingIds) > 50){
            $idPreview .= " ...";
        }

        $description = "Admin [$username] deleted multiple activity logs.
Deleted Count: $deletedCount
Deleted Log IDs: $idPreview
IP Address: $ip
Time: $time";

        logActivity(
            $mysqli,
            $username,
            $role,
            "DELETE MULTIPLE ACTIVITY LOGS",
            $description
        );

        exit("success|$deletedCount");
    }

    exit("Delete failed: " . $mysqli->error);
}

/* =========================
   DELETE OLD LOGS AJAX
========================= */
if($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === "delete_old_logs"){

    header("Content-Type: text/plain");

    $days = isset($_POST['days']) ? (int)$_POST['days'] : 0;
    $allowedDays = [30, 60, 90];

    if(!in_array($days, $allowedDays, true)){
        exit("Invalid day option");
    }

    $cutoffDate = date("Y-m-d H:i:s", strtotime("-$days days"));

    $countStmt = $mysqli->prepare("
        SELECT COUNT(*) AS total
        FROM activity_logs
        WHERE log_time < ?
    ");

    if(!$countStmt){
        exit("Prepare failed: " . $mysqli->error);
    }

    $countStmt->bind_param("s", $cutoffDate);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $deleteCount = (int)$countResult->fetch_assoc()['total'];

    if($deleteCount <= 0){
        exit("success|0");
    }

    $deleteStmt = $mysqli->prepare("
        DELETE FROM activity_logs
        WHERE log_time < ?
    ");

    if(!$deleteStmt){
        exit("Prepare failed: " . $mysqli->error);
    }

    $deleteStmt->bind_param("s", $cutoffDate);

    if($deleteStmt->execute()){

        $deletedCount = $deleteStmt->affected_rows;
        $ip = $_SERVER['REMOTE_ADDR'];
        $time = date("Y-m-d H:i:s");

        $description = "Admin [$username] deleted old activity logs.
Delete Rule: Older than $days days
Cutoff Date: $cutoffDate
Deleted Count: $deletedCount
IP Address: $ip
Time: $time";

        logActivity(
            $mysqli,
            $username,
            $role,
            "DELETE OLD ACTIVITY LOGS",
            $description
        );

        exit("success|$deletedCount");
    }

    exit("Delete failed: " . $mysqli->error);
}

/* =========================
   AJAX SERVER-SIDE DATATABLE
========================= */
if(isset($_GET['ajax']) && $_GET['ajax'] == "1"){

    header("Content-Type: application/json");

    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
    $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;

    if($start < 0){
        $start = 0;
    }

    if($length <= 0 || $length > 100){
        $length = 10;
    }

    $search = "";
    if(isset($_GET['search']['value'])){
        $search = trim($_GET['search']['value']);
    }

    $orderColumnIndex = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 5;
    $orderDirection = isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === "asc"
        ? "ASC"
        : "DESC";

    $orderColumnMap = [
        0 => "id",
        1 => "username",
        2 => "role",
        3 => "action_type",
        4 => "description",
        5 => "log_time",
        6 => "id"
    ];

    $orderBy = "log_time DESC";

    if(isset($orderColumnMap[$orderColumnIndex])){
        $orderBy = $orderColumnMap[$orderColumnIndex] . " " . $orderDirection;
    }

    /* TOTAL RECORDS */
    $totalResult = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM activity_logs
    ");

    $recordsTotal = $totalResult ? (int)$totalResult->fetch_assoc()['total'] : 0;

    $params = [];
    $types = "";

    $whereSql = buildTrackingSearchWhere($search, $params, $types);

    /* FILTERED COUNT */
    if($whereSql !== ""){
        $countStmt = $mysqli->prepare("
            SELECT COUNT(*) AS total
            FROM activity_logs
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

        bindTrackingParams($countStmt, $types, $params);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $recordsFiltered = (int)$countResult->fetch_assoc()['total'];
    } else {
        $recordsFiltered = $recordsTotal;
    }

    /* DATA QUERY */
    $sql = "
        SELECT
            id,
            username,
            role,
            action_type,
            description,
            log_time
        FROM activity_logs
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

    $paramsWithLimit = array_merge($params, [$length, $start]);
    $typesWithLimit = $types . "ii";

    bindTrackingParams($stmt, $typesWithLimit, $paramsWithLimit);

    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];

    while($row = $result->fetch_assoc()){

        $descriptionRaw = $row['description'] ?? "";

        $descriptionShort = mb_strlen($descriptionRaw) > 160
            ? mb_substr($descriptionRaw, 0, 160) . "..."
            : $descriptionRaw;

        $formattedTime = !empty($row['log_time'])
            ? date("d M Y, h:i A", strtotime($row['log_time']))
            : "-";

        $selectHtml = '
            <input
                type="checkbox"
                class="form-check-input log-row-check"
                data-id="' . trackingEscape($row['id']) . '"
                onclick="event.stopPropagation();">
        ';

        $actionsHtml = '
            <button
                type="button"
                class="btn btn-sm btn-danger delete-log-btn"
                data-id="' . trackingEscape($row['id']) . '"
                title="Delete Log">
                <i class="fa fa-trash"></i>
            </button>
        ';

        $data[] = [
            "select" => $selectHtml,
            "username" => trackingEscape($row['username']),
            "role" => trackingEscape($row['role']),
            "action" => trackingEscape($row['action_type']),
            "description" => nl2br(trackingEscape($descriptionShort)),
            "time" => trackingEscape($formattedTime),
            "actions" => $actionsHtml,

            "meta" => [
                "id" => $row['id'],
                "username" => $row['username'],
                "role" => $row['role'],
                "action" => $row['action_type'],
                "description" => $descriptionRaw,
                "time" => $formattedTime
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

/* =========================
   NORMAL PAGE LOAD
========================= */
$latestResult = $mysqli->query("
    SELECT log_time
    FROM activity_logs
    ORDER BY log_time DESC
    LIMIT 1
");

$latestData = $latestResult ? $latestResult->fetch_assoc() : null;
$latestTime = $latestData ? $latestData['log_time'] : null;

$formattedTime = $latestTime
    ? date("d M Y, h:i A", strtotime($latestTime))
    : "No activity yet";

$initialSearch = "";

if(isset($_GET['search'])){
    $initialSearch = trim($_GET['search']);
}
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
.table-responsive {
    overflow-x: hidden !important;
}

#logsTable {
    width: 100% !important;
    table-layout: fixed;
}

#logsTable td {
    white-space: normal !important;
    word-wrap: break-word;
    vertical-align: middle;
}

#logsTable td:nth-child(5) {
    max-width: 300px;
}

#logsTable thead {
    background: #4e73df;
    color: white;
}

#logsTable tbody tr:hover {
    background: #f5f7ff;
    cursor: pointer;
}

#logsTable_wrapper .dt-top,
#logsTable_wrapper .dt-bottom {
    padding: 0 15px;
}

#logsTable_wrapper .page-item .page-link {
    border-radius: 8px;
    margin: 0 3px;
    border: none;
}

#logsTable_wrapper .page-item.active .page-link {
    background-color: #4e73df;
    color: #fff;
}

.activity-filter-hint{
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

.processing-text{
    font-size:14px;
    color:#0d6efd;
}

.activity-toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
    justify-content:space-between;
    margin-bottom:15px;
}

.activity-toolbar-left,
.activity-toolbar-right{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}

.activity-toolbar-right{
    flex-wrap:nowrap;
}

.old-delete-group{
    width:auto;
    min-width:390px;
    flex-wrap:nowrap;
}

.old-delete-group .form-select{
    min-width:190px;
}

.old-delete-group .btn{
    white-space:nowrap;
}

.selected-count-badge{
    background:#eef2ff;
    color:#1d4ed8;
    border:1px solid #bfdbfe;
    padding:6px 10px;
    border-radius:20px;
    font-size:13px;
    font-weight:600;
}

#logsTable th:first-child,
#logsTable td:first-child{
    width:45px !important;
    text-align:center;
}

#logsTable th:last-child,
#logsTable td:last-child{
    width:90px !important;
    text-align:center;
}

@media(max-width:768px){
    .activity-toolbar{
        align-items:flex-start;
    }

    .activity-toolbar-left,
    .activity-toolbar-right{
        width:100%;
    }

    .old-delete-group{
        width:100%;
        min-width:0;
    }

    .old-delete-group .form-select{
        min-width:0;
    }
}
</style>

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<h2 style="margin-bottom:20px;">Activity Tracking</h2>

<form method="GET" class="mb-2" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveActivitySearch"
            name="search"
            class="form-control"
            placeholder="Search activity... Example: login, admin"
            value="<?= trackingEscape($initialSearch) ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i> Search
        </button>
    </div>
</form>

<div class="alert alert-info d-flex justify-content-between align-items-center">
    <div>
        <i class="fa fa-clock"></i>
        <strong>Latest Activity:</strong> <?= trackingEscape($formattedTime) ?>
    </div>
</div>

<div id="activeFilterBox" class="active-filter-box"></div>

<div class="activity-toolbar">

    <div class="activity-toolbar-left">
        <span class="selected-count-badge">
            Selected: <span id="selectedLogCount">0</span>
        </span>

        <button type="button" class="btn btn-danger btn-sm" id="deleteSelectedLogsBtn" disabled>
            <i class="fa fa-trash"></i> Delete Selected
        </button>
    </div>

    <div class="activity-toolbar-right">
        <div class="input-group input-group-sm old-delete-group">
            <select class="form-select" id="deleteOldDays">
                <option value="">Delete old logs...</option>
                <option value="30">Older than 30 days</option>
                <option value="60">Older than 60 days</option>
                <option value="90">Older than 90 days</option>
            </select>

            <button type="button" class="btn btn-outline-danger" id="deleteOldLogsBtn">
                <i class="fa fa-calendar-xmark"></i> Delete Old Logs
            </button>
        </div>
    </div>

</div>

<div class="card shadow-sm">
<div class="card-body p-0">

<div class="table-responsive">

<table id="logsTable" class="table table-striped">

<thead>
<tr>
    <th>
        <input type="checkbox" class="form-check-input" id="selectAllVisibleLogs">
    </th>
    <th>Username</th>
    <th>Role</th>
    <th>Action</th>
    <th>Description</th>
    <th>Date & Time</th>
    <th style="width:90px;">Action</th>
</tr>
</thead>

<tbody></tbody>

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

    let initialSearch = <?= json_encode($initialSearch) ?>;
    let typingTimer = null;
    let selectedLogIds = new Set();

    let table = $('#logsTable').DataTable({
        processing: true,
        serverSide: true,
        pageLength: 10,
        autoWidth: false,
        searching: true,
        search: {
            search: initialSearch
        },
        order: [[5, "desc"]],
        dom:
            "t" +
            "<'dt-bottom d-flex justify-content-between align-items-center mt-3'<'dt-info'i><'dt-pagination'p>>",
        language: {
            processing: "<span class='processing-text'><i class='fa fa-spinner fa-spin'></i> Loading activity...</span>"
        },
        ajax: {
            url: "tracking.php",
            type: "GET",
            data: function(d){
                d.ajax = 1;
            }
        },
        columns: [
            {
                data: "select",
                orderable: false,
                searchable: false
            },
            { data: "username" },
            { data: "role" },
            { data: "action" },
            { data: "description" },
            { data: "time" },
            {
                data: "actions",
                orderable: false,
                searchable: false
            }
        ],
        drawCallback: function(){
            syncVisibleCheckboxes();
        }
    });

    function updateSelectedCount(){
        let count = selectedLogIds.size;

        $("#selectedLogCount").text(count);
        $("#deleteSelectedLogsBtn").prop("disabled", count === 0);
    }

    function syncVisibleCheckboxes(){
        let visibleChecks = $("#logsTable tbody .log-row-check");

        visibleChecks.each(function(){
            let id = String($(this).data("id"));
            this.checked = selectedLogIds.has(id);
        });

        let allVisibleChecked = visibleChecks.length > 0;

        visibleChecks.each(function(){
            if(!this.checked){
                allVisibleChecked = false;
            }
        });

        $("#selectAllVisibleLogs").prop("checked", allVisibleChecked);
        updateSelectedCount();
    }

    function updateActiveFilterBox(){
        let searchText = $("#liveActivitySearch").val().trim();

        if(searchText !== ""){
            $("#activeFilterBox")
                .html("<strong>Active Search:</strong> " + searchText)
                .show();
        }else{
            $("#activeFilterBox").hide().html("");
        }
    }

    function runActivitySearch(value){
        table.search(value).draw();

        let newUrl = "tracking.php";

        if(value.trim() !== ""){
            newUrl += "?search=" + encodeURIComponent(value.trim());
        }

        window.history.replaceState(null, "", newUrl);
        updateActiveFilterBox();
    }

    $("#liveActivitySearch").on("input", function(){
        let value = this.value;

        clearTimeout(typingTimer);

        typingTimer = setTimeout(function(){
            runActivitySearch(value);
        }, 250);
    });

    $('#logsTable tbody').on('click', 'tr', function(e){

        if($(e.target).closest('button, a, input').length){
            return;
        }

        let rowData = table.row(this).data();

        if(!rowData || !rowData.meta){
            return;
        }

        let description = rowData.meta.description || "";

        let points = description.split(/[,.;\n]/);
        let listHTML = "";

        points.forEach(function(item){
            if(item.trim() !== ""){
                listHTML += `<li>${item.trim()}</li>`;
            }
        });

        $('#mUsername').text(rowData.meta.username || "");
        $('#mRole').text(rowData.meta.role || "");
        $('#mAction').text(rowData.meta.action || "");
        $('#mTime').text(rowData.meta.time || "");
        $('#mDescription').html(listHTML);

        new bootstrap.Modal(document.getElementById('logModal')).show();
    });

    $('#logsTable tbody').on('change', '.log-row-check', function(e){
        e.stopPropagation();

        let id = String($(this).data('id'));

        if(this.checked){
            selectedLogIds.add(id);
        }else{
            selectedLogIds.delete(id);
        }

        syncVisibleCheckboxes();
    });

    $("#selectAllVisibleLogs").on("change", function(){
        let checked = this.checked;

        $("#logsTable tbody .log-row-check").each(function(){
            let id = String($(this).data("id"));
            this.checked = checked;

            if(checked){
                selectedLogIds.add(id);
            }else{
                selectedLogIds.delete(id);
            }
        });

        updateSelectedCount();
    });

    $('#logsTable tbody').on('click', '.delete-log-btn', function(e){
        e.stopPropagation();

        let id = $(this).data('id');

        if(!confirm("Delete this activity log?")){
            return;
        }

        $.post("tracking.php", {
            action: "delete_log",
            id: id
        }, function(data){

            if(data.trim() === "success"){
                selectedLogIds.delete(String(id));
                table.ajax.reload(null, false);
                updateSelectedCount();
            }else{
                alert(data);
            }

        });
    });

    $("#deleteSelectedLogsBtn").on("click", function(){
        let ids = Array.from(selectedLogIds);

        if(ids.length === 0){
            alert("Please select at least one log.");
            return;
        }

        if(!confirm("Delete " + ids.length + " selected activity log(s)?")){
            return;
        }

        $.post("tracking.php", {
            action: "delete_selected_logs",
            ids: ids
        }, function(data){

            let response = data.trim();

            if(response.startsWith("success")){
                let parts = response.split("|");
                let count = parts[1] || ids.length;

                selectedLogIds.clear();
                $("#selectAllVisibleLogs").prop("checked", false);

                table.ajax.reload(null, false);
                updateSelectedCount();

                alert(count + " selected activity log(s) deleted.");
            }else{
                alert(data);
            }

        });
    });

    $("#deleteOldLogsBtn").on("click", function(){
        let days = $("#deleteOldDays").val();

        if(days === ""){
            alert("Please choose 30 days, 60 days, or 90 days.");
            return;
        }

        if(!confirm("Delete all activity logs older than " + days + " days?")){
            return;
        }

        $.post("tracking.php", {
            action: "delete_old_logs",
            days: days
        }, function(data){

            let response = data.trim();

            if(response.startsWith("success")){
                let parts = response.split("|");
                let count = parts[1] || 0;

                selectedLogIds.clear();
                $("#selectAllVisibleLogs").prop("checked", false);
                $("#deleteOldDays").val("");

                table.ajax.reload(null, false);
                updateSelectedCount();

                alert(count + " old activity log(s) deleted.");
            }else{
                alert(data);
            }

        });
    });

    updateActiveFilterBox();
    updateSelectedCount();

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