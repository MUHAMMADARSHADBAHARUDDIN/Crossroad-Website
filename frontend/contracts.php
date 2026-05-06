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

function contractFormatDate($value){
    $value = trim((string)($value ?? ''));

    if($value === "" || $value === "0000-00-00"){
        return "";
    }

    $timestamp = strtotime($value);

    if($timestamp === false){
        return $value;
    }

    return date("d/m/Y", $timestamp);
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

function contractTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function contractColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);

    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

/* =========================================================
   SAFE COLUMN / TABLE CHECK
========================================================= */
$hasProjectManager = contractColumnExists($mysqli, "project_inventory", "project_manager");
$hasAccountManager = contractColumnExists($mysqli, "project_inventory", "account_manager");

$hasContractTasksTable = contractTableExists($mysqli, "contract_tasks");
$hasContractTaskContractId = $hasContractTasksTable && contractColumnExists($mysqli, "contract_tasks", "contract_id");

$hasTaskIsCompleted = $hasContractTasksTable && contractColumnExists($mysqli, "contract_tasks", "is_completed");
$hasTaskCompleted = $hasContractTasksTable && contractColumnExists($mysqli, "contract_tasks", "completed");
$hasTaskStatus = $hasContractTasksTable && contractColumnExists($mysqli, "contract_tasks", "status");

$canUseContractTasks = ($hasContractTasksTable && $hasContractTaskContractId);

$projectManagerSelect = $hasProjectManager ? "pi.project_manager" : "'' AS project_manager";
$accountManagerSelect = $hasAccountManager ? "pi.account_manager" : "'' AS account_manager";

$statusCase = "
CASE
    WHEN pi.contract_end IS NOT NULL
         AND pi.contract_end <> ''
         AND pi.contract_end <> '0000-00-00'
         AND pi.contract_end < CURDATE()
    THEN 'Closed'

    WHEN pi.contract_end IS NOT NULL
         AND pi.contract_end <> ''
         AND pi.contract_end <> '0000-00-00'
         AND pi.contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    THEN 'Expiring Soon'

    ELSE 'Active'
END
";

/* =========================================================
   TASK PROGRESS SAFE SQL
========================================================= */
$taskJoinSql = "";
$taskTotalSelect = "0 AS task_total";
$taskDoneSelect = "0 AS task_done";
$progressSelect = "NULL AS progress_percent";

if($canUseContractTasks){

    if($hasTaskIsCompleted){
        $taskDoneSql = "SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END)";
    }
    elseif($hasTaskCompleted){
        $taskDoneSql = "SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END)";
    }
    elseif($hasTaskStatus){
        $taskDoneSql = "SUM(CASE WHEN LOWER(status) IN ('completed','complete','done') THEN 1 ELSE 0 END)";
    }
    else{
        $taskDoneSql = "SUM(0)";
    }

    $taskJoinSql = "
        LEFT JOIN (
            SELECT
                contract_id,
                COUNT(*) AS task_total,
                $taskDoneSql AS task_done
            FROM contract_tasks
            GROUP BY contract_id
        ) task_summary ON task_summary.contract_id = pi.no
    ";

    $taskTotalSelect = "COALESCE(task_summary.task_total, 0) AS task_total";
    $taskDoneSelect = "COALESCE(task_summary.task_done, 0) AS task_done";

    $progressSelect = "
        CASE
            WHEN COALESCE(task_summary.task_total, 0) > 0
            THEN ROUND((COALESCE(task_summary.task_done, 0) / task_summary.task_total) * 100)
            ELSE NULL
        END AS progress_percent
    ";
}

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

    $projectManagerOrder = $hasProjectManager ? "pi.project_manager" : "pi.no";
    $accountManagerOrder = $hasAccountManager ? "pi.account_manager" : "pi.no";

    $orderColumnMap = [
        0 => "pi.no",
        1 => "pi.year_awarded",
        2 => "pi.project_name",
        3 => "pi.project_owner",
        4 => $projectManagerOrder,
        5 => $accountManagerOrder,
        6 => "progress_percent",
        7 => "auto_status",
        8 => "pi.contract_start",
        9 => "pi.contract_end",
        10 => "pi.amount"
    ];

    $orderBy = "pi.no DESC";

    if(isset($orderColumnMap[$orderColumnIndex])){
        $selectedOrderColumn = $orderColumnMap[$orderColumnIndex];

        if($selectedOrderColumn === "auto_status"){
            $orderBy = "$statusCase $orderDirection";
        }
        elseif($selectedOrderColumn === "progress_percent"){
            $orderBy = "progress_percent $orderDirection";
        }
        elseif($selectedOrderColumn === "pi.amount"){
            $orderBy = "CAST(pi.amount AS DECIMAL(15,2)) $orderDirection";
        }
        else{
            $orderBy = "$selectedOrderColumn $orderDirection";
        }
    }

    $totalResult = $mysqli->query("SELECT COUNT(*) AS total FROM project_inventory");
    $recordsTotal = $totalResult ? (int)$totalResult->fetch_assoc()['total'] : 0;

    $whereParts = [];
    $params = [];
    $types = "";

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

        $conditionParts = [
            "CAST(pi.no AS CHAR) LIKE ?",
            "CAST(pi.year_awarded AS CHAR) LIKE ?",
            "pi.project_name LIKE ?",
            "pi.project_owner LIKE ?"
        ];

        if($hasProjectManager){
            $conditionParts[] = "pi.project_manager LIKE ?";
        }

        if($hasAccountManager){
            $conditionParts[] = "pi.account_manager LIKE ?";
        }

        $conditionParts[] = "pi.end_user LIKE ?";
        $conditionParts[] = "pi.contract_no LIKE ?";
        $conditionParts[] = "pi.service LIKE ?";
        $conditionParts[] = "pi.po_date LIKE ?";
        $conditionParts[] = "pi.contract_start LIKE ?";
        $conditionParts[] = "pi.contract_end LIKE ?";
        $conditionParts[] = "CAST(pi.amount AS CHAR) LIKE ?";
        $conditionParts[] = "$statusCase LIKE ?";

        $whereParts[] = "(" . implode(" OR ", $conditionParts) . ")";

        foreach($conditionParts as $unused){
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

    if(count($whereParts) > 0){
        $countStmt = $mysqli->prepare("
            SELECT COUNT(*) AS total
            FROM project_inventory pi
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

    $sql = "
        SELECT
            pi.no,
            pi.year_awarded,
            pi.project_name,
            pi.project_owner,
            $projectManagerSelect,
            $accountManagerSelect,
            pi.end_user,
            pi.contract_no,
            pi.service,
            pi.po_date,
            pi.contract_start,
            pi.contract_end,
            pi.amount,
            pi.created_by,
            $statusCase AS auto_status,
            $taskTotalSelect,
            $taskDoneSelect,
            $progressSelect
        FROM project_inventory pi
        $taskJoinSql
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

        $taskTotal = (int)($row['task_total'] ?? 0);
        $taskDone = (int)($row['task_done'] ?? 0);
        $progressPercent = $row['progress_percent'];

        if($taskTotal <= 0 || $progressPercent === null){
            $progressHtml = '<span class="text-muted small">No available</span>';
        } else {
            $progressPercent = (int)$progressPercent;

            $progressBarColor = "bg-danger";

            if($progressPercent >= 70){
                $progressBarColor = "bg-success";
            }
            elseif($progressPercent >= 40){
                $progressBarColor = "bg-warning";
            }

            $progressHtml = '
                <div class="progress contract-progress">
                    <div class="progress-bar ' . $progressBarColor . '"
                         role="progressbar"
                         style="width:' . $progressPercent . '%;">
                        ' . $progressPercent . '%
                    </div>
                </div>
                <small class="text-muted">' . $taskDone . '/' . $taskTotal . ' completed</small>
            ';
        }

        $actionsHtml = "";

        if($canEditThisContract){
            $actionsHtml .= '
                <a href="contract_edit.php?id=' . contractEscape($row['no']) . '"
                   class="btn btn-sm btn-primary contract-action-btn">
                    Edit
                </a>
            ';
        }

        if($canDeleteThisContract){
            $actionsHtml .= '
                <a href="../backend/contract_delete.php?id=' . contractEscape($row['no']) . '"
                   class="btn btn-sm btn-danger contract-action-btn"
                   onclick="return confirm(\'Delete this contract?\')">
                    Delete
                </a>
            ';
        }

        if(!$canEditThisContract && !$canDeleteThisContract){
            $actionsHtml = '<span class="badge bg-secondary">View Only</span>';
        }

        $formattedStart = contractFormatDate($row['contract_start']);
        $formattedEnd = contractFormatDate($row['contract_end']);
        $formattedPoDate = contractFormatDate($row['po_date']);

        $data[] = [
            "no" => contractEscape($row['no']),
            "year" => contractEscape($row['year_awarded']),
            "project_name" => contractEscape($row['project_name']),
            "owner" => contractEscape($row['project_owner']),
            "project_manager" => contractEscape($row['project_manager'] ?? ''),
            "account_manager" => contractEscape($row['account_manager'] ?? ''),
            "progress" => $progressHtml,
            "status" => $statusHtml,
            "start" => contractEscape($formattedStart),
            "end" => contractEscape($formattedEnd),
            "amount" => "RM " . number_format((float)$row['amount'], 2),
            "actions" => $actionsHtml,

            "meta" => [
                "id" => $row['no'],
                "year" => $row['year_awarded'],
                "project" => $row['project_name'],
                "owner" => $row['project_owner'],
                "projectmanager" => $row['project_manager'] ?? '',
                "accountmanager" => $row['account_manager'] ?? '',
                "createdby" => $created_by,
                "canupload" => $canUploadThisContract ? "1" : "0",
                "enduser" => $row['end_user'],
                "contractno" => $row['contract_no'],
                "service" => $row['service'],
                "podate" => $formattedPoDate,
                "start" => $formattedStart,
                "end" => $formattedEnd,
                "status" => $auto_status,
                "amount" => $row['amount'],
                "tasktotal" => $taskTotal,
                "taskdone" => $taskDone,
                "progresspercent" => $progressPercent
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
html,
body{
    overflow-x:hidden !important;
}

.main{
    max-width:100%;
    overflow-x:hidden !important;
}

.contract-page-wrap{
    width:100%;
    max-width:100%;
}

.contract-table-card{
    background:#fff;
    border-radius:14px;
    box-shadow:0 4px 14px rgba(0,0,0,0.06);
    padding:0;
    overflow:hidden;
}

.contract-table-responsive{
    width:100%;
    max-width:100%;
    overflow-x:hidden !important;
}

#contractsTable{
    width:100% !important;
    table-layout:fixed;
    margin-bottom:0 !important;
}

#contractsTable thead{
    background:#212529;
    color:#fff;
}

#contractsTable th,
#contractsTable td{
    vertical-align:middle;
    white-space:normal !important;
    overflow-wrap:anywhere;
    word-break:break-word;
    font-size:12px;
    padding:8px 6px;
}

/* ✅ SMALLER NO + YEAR, BIGGER PROJECT NAME */
#contractsTable .contract-col-no,
#contractsTable .contract-col-year{
    text-align:center;
}

#contractsTable th:nth-child(1),
#contractsTable td:nth-child(1){
    width:4% !important;
    max-width:50px !important;
}

#contractsTable th:nth-child(2),
#contractsTable td:nth-child(2){
    width:5% !important;
    max-width:60px !important;
}

#contractsTable th:nth-child(3),
#contractsTable td:nth-child(3){
    width:24% !important;
}

#contractsTable tbody tr{
    cursor:pointer;
    transition:0.2s ease;
}

#contractsTable tbody tr:hover{
    background:#fff3cd !important;
    transform:scale(1.003);
}

.contract-action-btn{
    margin:2px;
    white-space:nowrap;
}

.contract-progress{
    height:18px;
    border-radius:12px;
    background:#e9ecef;
}

.contract-progress .progress-bar{
    font-size:11px;
    font-weight:600;
}

#contractsTable_wrapper{
    width:100%;
    max-width:100%;
    overflow-x:hidden !important;
}

#contractsTable_wrapper .dataTables_info{
    padding:14px 16px !important;
    font-size:14px;
    color:#6c757d;
}

#contractsTable_wrapper .dataTables_paginate{
    padding:10px 16px !important;
}

#contractsTable_wrapper .pagination{
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:4px;
}

#contractsTable_wrapper .page-link{
    border-radius:8px;
    border:none;
    margin:1px;
}

#contractsTable_wrapper .page-item.active .page-link{
    background:#ffc107;
    color:#000;
    font-weight:600;
}

.header-filter{
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:5px;
    color:#ffc107;
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

.processing-text{
    font-size:14px;
    color:#0d6efd;
}

/* =========================================================
   REAL CHECKLIST STYLE - NOT TABLE
========================================================= */
.task-card{
    background:#f8fafc;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:14px;
}

.task-checklist-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-bottom:12px;
    flex-wrap:wrap;
}

.task-summary-pill{
    background:#fff3cd;
    border:1px solid #ffe69c;
    color:#664d03;
    border-radius:999px;
    padding:6px 12px;
    font-size:13px;
    font-weight:700;
}

.task-add-box{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:10px;
    margin-bottom:12px;
}

.task-checklist{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.contract-task-item{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:12px 14px;
    transition:0.2s ease;
}

.contract-task-item:hover{
    border-color:#ffc107;
    box-shadow:0 4px 12px rgba(0,0,0,0.06);
}

.contract-task-left{
    display:flex;
    align-items:flex-start;
    gap:11px;
    flex:1;
    min-width:0;
}

.contract-task-checkbox{
    width:20px;
    height:20px;
    cursor:pointer;
    margin-top:2px;
    flex:0 0 auto;
}

.contract-task-text{
    font-size:14px;
    font-weight:600;
    color:#212529;
    line-height:1.4;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.contract-task-meta{
    font-size:12px;
    color:#6c757d;
    margin-top:4px;
}

.contract-task-actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.task-icon-btn{
    border:none;
    border-radius:9px;
    width:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.task-completed{
    background:#f0fff4;
    border-color:#b7e4c7;
}

.task-completed .contract-task-text{
    color:#198754;
    text-decoration:line-through;
}

.task-empty-state{
    background:#fff;
    border:1px dashed #cbd5e1;
    border-radius:14px;
    padding:18px;
    text-align:center;
    color:#6c757d;
}

.task-loading{
    color:#0d6efd;
    font-size:14px;
}

@media(max-width: 1200px){
    #contractsTable th,
    #contractsTable td{
        font-size:11px;
        padding:7px 5px;
    }

    .contract-action-btn{
        display:inline-block;
        margin-bottom:4px;
    }
}

@media(max-width: 768px){

    .main{
        padding-left:14px !important;
        padding-right:14px !important;
    }

    .input-group{
        flex-direction:column;
        gap:8px;
    }

    .input-group input,
    .input-group button{
        width:100%;
        border-radius:8px !important;
    }

    .contract-table-card{
        background:transparent;
        box-shadow:none;
        overflow:visible;
    }

    #contractsTable,
    #contractsTable thead,
    #contractsTable tbody,
    #contractsTable th,
    #contractsTable td,
    #contractsTable tr{
        display:block;
        width:100% !important;
    }

    #contractsTable thead{
        display:none;
    }

    #contractsTable{
        table-layout:auto;
        border-collapse:separate !important;
        border-spacing:0 12px !important;
    }

    #contractsTable tbody tr{
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:14px;
        padding:12px;
        margin-bottom:12px;
        box-shadow:0 4px 14px rgba(0,0,0,0.06);
        transform:none !important;
    }

    #contractsTable tbody tr:hover{
        background:#fff8e1 !important;
        transform:none !important;
    }

    #contractsTable td{
        border:none !important;
        border-bottom:1px solid #f1f1f1 !important;
        padding:9px 2px !important;
        max-width:100% !important;
        font-size:14px;
    }

    #contractsTable td:last-child{
        border-bottom:none !important;
    }

    #contractsTable td::before{
        content:attr(data-label);
        display:block;
        font-weight:700;
        color:#555;
        margin-bottom:3px;
        font-size:12px;
        text-transform:uppercase;
        letter-spacing:0.3px;
    }

    .contract-col-actions{
        display:flex !important;
        flex-wrap:wrap;
        gap:6px;
        align-items:center;
    }

    .contract-col-actions::before{
        flex:0 0 100%;
        width:100%;
    }

    .contract-action-btn{
        flex:1 1 auto;
        min-width:80px;
        margin:0;
    }

    .contract-task-item{
        flex-direction:column;
        align-items:stretch;
    }

    .contract-task-actions{
        justify-content:flex-start;
        padding-left:31px;
    }

    #contractsTable_wrapper .dataTables_info,
    #contractsTable_wrapper .dataTables_paginate{
        width:100%;
        text-align:center !important;
        padding:8px !important;
    }

    #contractsTable_wrapper .pagination{
        justify-content:center;
    }
}

@media(max-width: 430px){
    h2{
        font-size:22px;
    }

    .contract-filter-hint{
        font-size:12px;
    }

    #contractsTable td{
        font-size:13px;
    }

    .modal-dialog{
        margin:10px;
    }
}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<div class="contract-page-wrap">

<h2 class="mb-3">Contracts</h2>

<form method="GET" class="mb-0" onsubmit="return false;">
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

<div class="contract-filter-hint mt-2 mb-2">
    Use comma to search multiple terms, example: <b>2026, active</b>. Click the yellow Status header to filter status.
</div>

<div id="activeFilterBox" class="active-filter-box"></div>

<?php if($canAddContract): ?>
<a href="contract_add.php" class="btn btn-warning mb-3">
    <i class="fa fa-plus"></i> Add Contract
</a>
<?php endif; ?>

<div class="contract-table-card">
<div class="contract-table-responsive">

<table id="contractsTable" class="table table-hover table-striped align-middle">

<thead>
<tr>
    <th>No</th>
    <th>Year</th>
    <th>Project Name</th>
    <th>Owner</th>
    <th>Project Manager</th>
    <th>Account Manager</th>
    <th>Progress</th>

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
</div>

</div>

</div>

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
<div class="col-md-6"><b>Project Manager</b><div id="m_projectmanager"></div></div>
<div class="col-md-6"><b>Account Manager</b><div id="m_accountmanager"></div></div>
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

<hr>

<h6><i class="fa fa-list-check"></i> Contract Checklist</h6>
<div id="tasksContainer" class="task-card">
    <div class="task-loading">
        <i class="fa fa-spinner fa-spin"></i> Loading tasks...
    </div>
</div>

</div>
</div>
</div>
</div>

<!-- EDIT TASK MODAL -->
<div class="modal fade" id="editTaskModal">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">

<div class="modal-header bg-primary text-white">
    <h5 class="modal-title">
        <i class="fa fa-pen"></i> Edit Task
    </h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <input type="hidden" id="editTaskId">

    <label class="form-label">Task</label>
    <textarea id="editTaskText" class="form-control" rows="4"></textarea>
</div>

<div class="modal-footer">
    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button class="btn btn-warning" onclick="updateContractTask()">
        <i class="fa fa-save"></i> Save
    </button>
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
let contractsTable;

function loadContractTasks(){
    let contractId = $("#m_id").val();

    if(!contractId){
        $("#tasksContainer").html("<div class='task-empty-state'>No contract selected.</div>");
        return;
    }

    $("#tasksContainer").html("<div class='task-loading'><i class='fa fa-spinner fa-spin'></i> Loading checklist...</div>");

    $.post("../backend/get_contract_tasks.php", {
        contract_id: contractId
    }, function(data){
        $("#tasksContainer").html(data);
    }).fail(function(){
        $("#tasksContainer").html("<div class='alert alert-danger mb-0'>Failed to load checklist.</div>");
    });
}

function reloadContractTableProgress(){
    if(typeof contractsTable !== "undefined" && contractsTable){
        contractsTable.ajax.reload(null, false);
    }
}

function addContractTask(){
    let contractId = $("#m_id").val();
    let taskText = $("#newContractTaskText").val().trim();

    if(!contractId){
        alert("No contract selected.");
        return;
    }

    if(taskText === ""){
        alert("Please enter a task.");
        return;
    }

    $("#addTaskBtn").prop("disabled", true).html("<i class='fa fa-spinner fa-spin'></i>");

    $.post("../backend/add_contract_task.php", {
        contract_id: contractId,
        task_text: taskText
    }, function(data){
        if(data.trim() === "success"){
            $("#newContractTaskText").val("");
            loadContractTasks();
            reloadContractTableProgress();
        }else{
            alert(data);
        }
    }).fail(function(){
        alert("Failed to add task.");
    }).always(function(){
        $("#addTaskBtn").prop("disabled", false).html("<i class='fa fa-plus'></i> Add");
    });
}

function toggleContractTask(id, isCompleted, checkboxEl){
    let checkbox = $(checkboxEl);
    let item = checkbox.closest(".contract-task-item");

    checkbox.prop("disabled", true);
    item.toggleClass("task-completed", isCompleted);

    $.post("../backend/toggle_contract_task.php", {
        id: id,
        is_completed: isCompleted ? 1 : 0
    }, function(data){
        if(data.trim() === "success"){
            loadContractTasks();
            reloadContractTableProgress();
        }else{
            checkbox.prop("checked", !isCompleted);
            item.toggleClass("task-completed", !isCompleted);
            alert(data);
        }
    }).fail(function(){
        checkbox.prop("checked", !isCompleted);
        item.toggleClass("task-completed", !isCompleted);
        alert("Failed to update checklist.");
    }).always(function(){
        checkbox.prop("disabled", false);
    });
}

function openEditTaskModal(id, taskText){
    $("#editTaskId").val(id);
    $("#editTaskText").val(taskText);

    new bootstrap.Modal(document.getElementById("editTaskModal")).show();
}

function updateContractTask(){
    let id = $("#editTaskId").val();
    let taskText = $("#editTaskText").val().trim();

    if(taskText === ""){
        alert("Task cannot be empty.");
        return;
    }

    $.post("../backend/update_contract_task.php", {
        id: id,
        task_text: taskText
    }, function(data){
        if(data.trim() === "success"){
            bootstrap.Modal.getInstance(document.getElementById("editTaskModal")).hide();
            loadContractTasks();
            reloadContractTableProgress();
        }else{
            alert(data);
        }
    }).fail(function(){
        alert("Failed to update task.");
    });
}

function deleteContractTask(id){
    if(!confirm("Delete this task?")){
        return;
    }

    $.post("../backend/delete_contract_task.php", {
        id: id
    }, function(data){
        if(data.trim() === "success"){
            loadContractTasks();
            reloadContractTableProgress();
        }else{
            alert(data);
        }
    }).fail(function(){
        alert("Failed to delete task.");
    });
}

$(document).ready(function(){

    let initialSearch = <?= json_encode($search) ?>;
    let typingTimer = null;
    let statusFilter = "";

    contractsTable = $('#contractsTable').DataTable({
        processing: true,
        serverSide: true,
        pageLength: 10,
        autoWidth: false,
        searching: true,
        search: {
            search: initialSearch
        },
        order: [[0, "desc"]],
        dom:
            "t" +
            "<'contract-dt-footer d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3'ip>",
        language: {
            processing: "<span class='processing-text'><i class='fa fa-spinner fa-spin'></i> Loading contracts...</span>"
        },
        ajax: {
            url: "contracts.php",
            type: "GET",
            data: function(d){
                d.ajax = 1;
                d.status_filter = statusFilter;
            }
        },
        columnDefs: [
            { targets: 0, width: "4%" },
            { targets: 1, width: "5%" },
            { targets: 2, width: "24%" }
        ],
        columns: [
            { data: "no", className: "contract-col-no" },
            { data: "year", className: "contract-col-year" },
            { data: "project_name", className: "contract-col-project" },
            { data: "owner", className: "contract-col-owner" },
            { data: "project_manager", className: "contract-col-project-manager" },
            { data: "account_manager", className: "contract-col-account-manager" },
            { data: "progress", className: "contract-col-progress" },
            { data: "status", className: "contract-col-status" },
            { data: "start", className: "contract-col-start" },
            { data: "end", className: "contract-col-end" },
            { data: "amount", className: "contract-col-amount" },
            {
                data: "actions",
                orderable: false,
                searchable: false,
                className: "contract-col-actions"
            }
        ],
        createdRow: function(row, data){
            $(row).attr("data-id", data.meta.id);
            $(row).attr("data-year", data.meta.year);
            $(row).attr("data-project", data.meta.project);
            $(row).attr("data-owner", data.meta.owner);
            $(row).attr("data-projectmanager", data.meta.projectmanager);
            $(row).attr("data-accountmanager", data.meta.accountmanager);
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

            $("td:eq(0)", row).attr("data-label", "No");
            $("td:eq(1)", row).attr("data-label", "Year");
            $("td:eq(2)", row).attr("data-label", "Project Name");
            $("td:eq(3)", row).attr("data-label", "Owner");
            $("td:eq(4)", row).attr("data-label", "Project Manager");
            $("td:eq(5)", row).attr("data-label", "Account Manager");
            $("td:eq(6)", row).attr("data-label", "Progress");
            $("td:eq(7)", row).attr("data-label", "Status");
            $("td:eq(8)", row).attr("data-label", "Start");
            $("td:eq(9)", row).attr("data-label", "End");
            $("td:eq(10)", row).attr("data-label", "Amount");
            $("td:eq(11)", row).attr("data-label", "Actions");
        }
    });

    function adjustContractTable(){
        setTimeout(function(){
            contractsTable.columns.adjust();
        }, 150);
    }

    $(window).on("resize", adjustContractTable);

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

    $(document).on("keypress", "#newContractTaskText", function(e){
        if(e.which === 13){
            e.preventDefault();
            addContractTask();
        }
    });

    function openContractModal(data){
        let meta = data.meta;

        $('#m_id').val(meta.id);

        $('#m_project').text(meta.project || '');
        $('#m_owner').text(meta.owner || '');
        $('#m_projectmanager').text(meta.projectmanager || '-');
        $('#m_accountmanager').text(meta.accountmanager || '-');
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

        loadContractTasks();

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
    adjustContractTable();

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