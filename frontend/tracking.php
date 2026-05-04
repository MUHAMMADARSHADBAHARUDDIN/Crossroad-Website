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
        5 => "log_time"
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
            "id" => trackingEscape($row['id']),
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

<div class="activity-filter-hint mb-2">
    Use comma to search multiple terms. Example: <b>login, admin</b> or <b>contract, delete</b>.
</div>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <div>
        <i class="fa fa-clock"></i>
        <strong>Latest Activity:</strong> <?= trackingEscape($formattedTime) ?>
    </div>
</div>

<div id="activeFilterBox" class="active-filter-box"></div>

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
        ]
    });

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

        if($(e.target).closest('button, a').length){
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
                table.ajax.reload(null, false);
            }else{
                alert(data);
            }

        });
    });

    updateActiveFilterBox();

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