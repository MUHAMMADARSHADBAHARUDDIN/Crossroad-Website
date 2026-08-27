<?php
global $mysqli;
require_once "../includes/security.php";
startSecureSession(false);

if(!isset($_SESSION['username'])){
    header("location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/date_helpers.php";
require_once "../includes/contract_schema.php";
require_once "../includes/contract_task_schema.php";

ensureContractProjectSchema($mysqli);
ensureContractTaskCompletionSchema($mysqli);

if(!hasContractViewAccess($mysqli)){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

$canAddContract = hasContractAddAccess($mysqli);
$canViewClaim = hasContractClaimViewAccess($mysqli);
$contractImportResult = $_SESSION['contract_import_result'] ?? null;
unset($_SESSION['contract_import_result']);

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

function contractSearchValuesForTerm($term){
    $term = trim((string)($term ?? ""));

    if($term === ""){
        return [];
    }

    $values = [$term];
    $prefix = contractProjectCodeFindKnownPrefix($term);

    if($prefix !== ""){
        $values[] = $prefix;

        $canonicalEndUsers = contractProjectCodeCanonicalEndUsers();

        if(isset($canonicalEndUsers[$prefix])){
            $values[] = $canonicalEndUsers[$prefix];
        }

        // Keep known-code expansion small. Expanding every alias creates a very
        // large OR query and makes each server-side key search noticeably slow.
    }

    $uniqueValues = [];
    $seen = [];

    foreach($values as $value){
        $value = trim((string)$value);

        if($value === ""){
            continue;
        }

        $key = strtoupper(preg_replace('/\s+/', ' ', $value));

        if(isset($seen[$key])){
            continue;
        }

        $seen[$key] = true;
        $uniqueValues[] = $value;
    }

    return $uniqueValues;
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
$hasTaskClaimAmount = $hasContractTasksTable && contractColumnExists($mysqli, "contract_tasks", "claim_amount");

$canUseContractTasks = ($hasContractTasksTable && $hasContractTaskContractId);

$projectManagerSelect = $hasProjectManager ? "pi.project_manager" : "'' AS project_manager";
$accountManagerSelect = $hasAccountManager ? "pi.account_manager" : "'' AS account_manager";

$contractEndDateSql = appSqlDateValue("pi.contract_end");

$statusCase = "
CASE
    WHEN $contractEndDateSql IS NOT NULL
         AND $contractEndDateSql < CURDATE()
    THEN 'Closed'

    WHEN $contractEndDateSql IS NOT NULL
         AND $contractEndDateSql BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
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
$taskClaimSelect = "0 AS claim_total";
$progressSelect = "NULL AS progress_percent";
$progressSearchSql = "''";

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

    $taskClaimSql = ($hasTaskClaimAmount && $canViewClaim)
        ? "SUM(COALESCE(claim_amount, 0))"
        : "SUM(0)";

    $taskJoinSql = "
        LEFT JOIN (
            SELECT
                contract_id,
                COUNT(*) AS task_total,
                $taskDoneSql AS task_done,
                $taskClaimSql AS claim_total
            FROM contract_tasks
            GROUP BY contract_id
        ) task_summary ON task_summary.contract_id = pi.no
    ";

    $taskTotalSelect = "COALESCE(task_summary.task_total, 0) AS task_total";
    $taskDoneSelect = "COALESCE(task_summary.task_done, 0) AS task_done";
    $taskClaimSelect = "COALESCE(task_summary.claim_total, 0) AS claim_total";

    $progressSelect = "
        CASE
            WHEN COALESCE(task_summary.task_total, 0) > 0
            THEN ROUND((COALESCE(task_summary.task_done, 0) / task_summary.task_total) * 100)
            ELSE NULL
        END AS progress_percent
    ";

    $progressSearchSql = "
        CASE
            WHEN COALESCE(task_summary.task_total, 0) > 0
            THEN CAST(ROUND((COALESCE(task_summary.task_done, 0) / task_summary.task_total) * 100) AS CHAR)
            ELSE ''
        END
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

    $orderColumnIndex = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : null;
    $orderDirection = isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === "asc"
        ? "ASC"
        : "DESC";

    $projectManagerOrder = $hasProjectManager ? "pi.project_manager" : "pi.no";
    $accountManagerOrder = $hasAccountManager ? "pi.account_manager" : "pi.no";

    $orderColumnMap = [
        0 => "pi.project_code",
        1 => "pi.year_awarded", 2 => "pi.status", 3 => "pi.project_name",
        4 => $projectManagerOrder, 5 => $accountManagerOrder, 6 => "pi.project_owner",
        7 => "pi.end_user", 8 => "pi.contract_no", 9 => "pi.service", 10 => "pi.po_date",
        11 => "pi.contract_start", 12 => "pi.contract_end", 13 => "progress_percent",
        14 => "pi.amount", 15 => "pi.payment_term", 16 => "pi.no_of_pm"
    ];

    $orderBy = "pi.no DESC";

    if($orderColumnIndex !== null && isset($orderColumnMap[$orderColumnIndex])){
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
        $termConditionParts = [];
        $searchValues = contractSearchValuesForTerm($term);

        foreach($searchValues as $searchValue){
            $searchLike = "%" . $searchValue . "%";

            $conditionParts = [
                "pi.project_code LIKE ?",
                "CAST(pi.no AS CHAR) LIKE ?",
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
            $conditionParts[] = "CAST(pi.po_date AS CHAR) LIKE ?";
            $conditionParts[] = "CAST(pi.contract_start AS CHAR) LIKE ?";
            $conditionParts[] = "CAST(pi.contract_end AS CHAR) LIKE ?";
            $conditionParts[] = "CAST(pi.amount AS CHAR) LIKE ?";
            $conditionParts[] = "COALESCE(pi.created_by, '') LIKE ?";
            $conditionParts[] = "COALESCE(pi.status, '') LIKE ?";
            $conditionParts[] = "$statusCase LIKE ?";

            $termConditionParts[] = "(" . implode(" OR ", $conditionParts) . ")";

            foreach($conditionParts as $unused){
                $params[] = $searchLike;
                $types .= "s";
            }
        }

        if(!empty($termConditionParts)){
            $whereParts[] = "(" . implode(" OR ", $termConditionParts) . ")";
        }
    }

    if($statusFilter !== ""){
        $whereParts[] = "COALESCE(NULLIF(pi.status, ''), $statusCase) = ?";
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
            pi.project_code,
            pi.year_awarded,
            pi.status,
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
            pi.payment_term,
            pi.no_of_pm,
            pi.pm_y1_q1, pi.pm_y1_q2, pi.pm_y1_q3, pi.pm_y1_q4,
            pi.pm_y2_q1, pi.pm_y2_q2, pi.pm_y2_q3, pi.pm_y2_q4,
            pi.pm_y3_q1, pi.pm_y3_q2, pi.pm_y3_q3, pi.pm_y3_q4,
            pi.pm_y4_q1, pi.pm_y4_q2, pi.pm_y4_q3, pi.pm_y4_q4,
            pi.created_by,
            $statusCase AS auto_status,
            $taskTotalSelect,
            $taskDoneSelect,
            $taskClaimSelect,
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
        $canAddTaskThisContract = hasContractTaskAddAccess($mysqli, $created_by);
        $canUploadTaskDocumentThisContract = hasContractTaskDocumentUploadAccess($mysqli, $created_by);

        $auto_status = trim((string)($row['status'] ?? '')) ?: ($row['auto_status'] ?? "Active");

        if(strcasecmp($auto_status, "Closed") === 0 || strcasecmp($auto_status, "Drop") === 0){
            $statusHtml = '<span class="badge bg-danger">Closed</span>';
        }
        elseif(strcasecmp($auto_status, "Expiring Soon") === 0 || strcasecmp($auto_status, "Awarded") === 0){
            $statusHtml = '<span class="badge bg-warning text-dark">Expiring Soon</span>';
        }
        else{
            $statusHtml = '<span class="badge bg-success">Active</span>';
        }

        $taskTotal = (int)($row['task_total'] ?? 0);
        $taskDone = (int)($row['task_done'] ?? 0);
        $claimTotal = (float)($row['claim_total'] ?? 0);
        $contractAmount = (float)($row['amount'] ?? 0);
        $leftoverAmount = max(0, $contractAmount - $claimTotal);
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
                   class="btn btn-sm btn-primary contract-action-btn"
                   title="Edit contract"
                   aria-label="Edit contract">
                    <i class="fa fa-pen" aria-hidden="true"></i>
                    <span class="visually-hidden">Edit</span>
                </a>
            ';
        }

        if($canDeleteThisContract){
            $actionsHtml .= '
                <a href="../backend/contract_delete.php?id=' . contractEscape($row['no']) . '"
                   class="btn btn-sm btn-danger contract-action-btn"
                   title="Delete contract"
                   aria-label="Delete contract"
                   onclick="return confirm(\'Delete this contract?\')">
                    <i class="fa fa-trash" aria-hidden="true"></i>
                    <span class="visually-hidden">Delete</span>
                </a>
            ';
        }

        if(!$canEditThisContract && !$canDeleteThisContract){
            $actionsHtml = '<span class="badge bg-secondary">View Only</span>';
        }

        $formattedStart = contractFormatDate($row['contract_start']);
        $formattedEnd = contractFormatDate($row['contract_end']);
        $formattedPoDate = contractFormatDate($row['po_date']);
        $displayProjectCode = contractProjectCodeDisplay($row['project_code'] ?? "");
        $meta = [
            "id" => $row['no'],
            "projectcode" => $displayProjectCode,
            "project" => $row['project_name'],
            "owner" => $row['project_owner'],
            "projectmanager" => $row['project_manager'] ?? '',
            "accountmanager" => $row['account_manager'] ?? '',
            "createdby" => $created_by,
            "canupload" => $canUploadThisContract ? "1" : "0",
            "canaddtask" => $canAddTaskThisContract ? "1" : "0",
            "canuploadtaskdocument" => $canUploadTaskDocumentThisContract ? "1" : "0",
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
        ];

        if($canViewClaim){
            $meta["claimamount"] = $claimTotal;
            $meta["leftoveramount"] = $leftoverAmount;
        }

        $data[] = [
            "no" => contractEscape($row['no']),
            "project_code" => contractEscape($displayProjectCode),
            "year_awarded" => contractEscape($row['year_awarded'] ?? ''),
            "contract_no" => contractEscape($row['contract_no']),
            "project_name" => contractEscape($row['project_name']),
            "owner" => contractEscape($row['project_owner']),
            "project_manager" => contractEscape($row['project_manager'] ?? ''),
            "account_manager" => contractEscape($row['account_manager'] ?? ''),
            "end_user" => contractEscape($row['end_user'] ?? ''),
            "po_number" => contractEscape($row['contract_no'] ?? ''),
            "service" => contractEscape($row['service'] ?? ''),
            "po_date" => contractEscape($formattedPoDate),
            "progress" => $progressHtml,
            "status" => $statusHtml,
            "start" => contractEscape($formattedStart),
            "end" => contractEscape($formattedEnd),
            "amount" => "RM " . number_format((float)$row['amount'], 2),
            "payment_term" => contractEscape($row['payment_term'] ?? ''),
            "no_of_pm" => contractEscape($row['no_of_pm'] ?? ''),
            "actions" => $actionsHtml,
            "meta" => $meta
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

$focusContractId = isset($_GET['focus_contract']) ? (int)$_GET['focus_contract'] : 0;
$focusTaskId = isset($_GET['focus_task']) ? (int)$_GET['focus_task'] : 0;
$csrfToken = ensureCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contracts</title>

<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
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
    width:100%;
    max-width:100%;
    min-width:0;
    overflow:hidden;
}

.contract-table-responsive{
    width:100%;
    max-width:100%;
    min-width:0;
    overflow-x:auto !important;
    overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior-x:contain;
}

.contract-table-scroll{
    display:block;
    width:100%;
    max-width:100%;
    min-width:0;
    overflow-x:auto !important;
    overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior-x:contain;
}

#contractsTable{
    width:max-content !important;
    min-width:3300px !important;
    max-width:none !important;
    table-layout:auto !important;
    margin-bottom:0 !important;
}

.contract-search-form{
    width:100%;
}

.contract-dt-footer .dataTables_length label{
    display:flex;
    align-items:center;
    gap:8px;
    margin:0;
    white-space:nowrap;
}

.contract-dt-footer .dataTables_length select{
    width:auto;
    min-width:72px;
    padding:5px 28px 5px 10px;
    border:1px solid #ced4da;
    border-radius:7px;
    background-color:#fff;
}

#contractsTable thead{
    background:#212529;
    color:#fff;
}

#contractsTable th,
#contractsTable td{
    vertical-align:middle;
    min-width:120px;
    white-space:normal;
    overflow-wrap:break-word;
    word-break:normal;
    font-size:12px;
    padding:8px 6px;
}

#contractsTable th{
    white-space:nowrap !important;
    word-break:normal !important;
    overflow-wrap:normal !important;
}

#contractsTable .contract-col-project{
    min-width:360px;
    max-width:460px;
}

#contractsTable .contract-col-project-manager,
#contractsTable .contract-col-account-manager,
#contractsTable .contract-col-owner,
#contractsTable td:nth-child(8){
    min-width:180px;
    max-width:240px;
}

#contractsTable th:nth-child(n+18):nth-child(-n+21),
#contractsTable td:nth-child(n+18):nth-child(-n+21){
    min-width:210px;
    max-width:240px;
}

/* ✅ SMALLER CODE + CONTRACT NO, BIGGER PROJECT NAME */
#contractsTable .contract-col-project-code,
#contractsTable .contract-col-contract-no,
#contractsTable .contract-col-year-awarded,
#contractsTable .contract-col-project-manager,
#contractsTable .contract-col-account-manager{
    text-align:center;
}

#contractsTable th:nth-child(1),
#contractsTable td:nth-child(1){
    width:120px !important;
    min-width:120px !important;
    max-width:120px !important;
}

#contractsTable th:nth-child(2),
#contractsTable td:nth-child(2){
    width:110px !important;
    min-width:110px !important;
    max-width:110px !important;
}

#contractsTable th:nth-child(3),
#contractsTable td:nth-child(3){
    width:120px !important;
    min-width:120px !important;
}

#contractsTable tbody tr{
    cursor:pointer;
    transition:background-color 0.18s ease, box-shadow 0.18s ease;
}

#contractsTable tbody tr:hover{
    background:#fff3cd !important;
    box-shadow:inset 3px 0 0 #ffc107;
}

#contractsTable tbody tr.contract-focus-row{
    background:#fff3cd !important;
    outline:3px solid #ffc107;
    outline-offset:-3px;
    box-shadow:0 0 0 5px rgba(255,193,7,0.18);
}

.contract-action-btn{
    margin:2px;
    white-space:nowrap;
    width:34px;
    height:34px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
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
    min-width:0;
    overflow-x:hidden !important;
}

#contractsTable_wrapper .dataTables_scroll,
#contractsTable_wrapper .dataTables_scrollBody{
    width:100%;
    max-width:100%;
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
   CONTRACT DETAIL + EXCEL-INSPIRED CHECKLIST
========================================================= */
.contract-detail-modal .modal-dialog{
    max-width:1080px;
}

.contract-detail-modal .modal-content,
.contract-tools-modal .modal-content{
    overflow:hidden;
    border:0;
    border-radius:18px;
    box-shadow:0 24px 70px rgba(15,23,42,.24);
}

.contract-detail-modal .modal-header,
.contract-tools-modal .modal-header{
    padding:18px 22px;
    border:0;
    background:linear-gradient(135deg,#173866 0%,#2f5a9c 100%);
    color:#fff;
}

.contract-detail-modal .modal-body{
    padding:22px;
    background:#f5f7fb;
}

.contract-overview-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    margin-bottom:16px;
}

.contract-overview-card{
    padding:14px 16px;
    border:1px solid #dbe3ee;
    border-radius:13px;
    background:#fff;
}

.contract-overview-label{
    display:block;
    margin-bottom:4px;
    color:#64748b;
    font-size:12px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
}

.contract-overview-value{
    color:#182334;
    font-size:15px;
    font-weight:650;
    overflow-wrap:anywhere;
}

.contract-detail-toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    padding:12px 14px;
    margin-bottom:16px;
    border:1px solid #dbe3ee;
    border-radius:13px;
    background:#fff;
}

.contract-detail-toolbar-copy strong{
    display:block;
    color:#182334;
}

.contract-detail-toolbar-copy span{
    color:#64748b;
    font-size:12px;
}

.contract-detail-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.contract-detail-action{
    min-height:40px;
    border-radius:9px;
    font-weight:650;
}

.attachment-count-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:22px;
    height:22px;
    margin-left:5px;
    padding:0 7px;
    border-radius:999px;
    background:#e8eef8;
    color:#173866;
    font-size:11px;
    font-weight:800;
}

.contract-checklist-panel{
    overflow:hidden;
    border:1px solid #d6deea;
    border-radius:14px;
    background:#fff;
}

.task-card{
    background:#fff;
}

.task-checklist-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:15px 17px 11px;
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

.task-progress-track{
    height:8px;
    margin:0 17px 16px;
    overflow:hidden;
    border-radius:999px;
    background:#e8edf4;
}

.task-progress-value{
    height:100%;
    border-radius:inherit;
    transition:width .2s ease;
}

.contract-checklist-scroll{
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
}

.contract-checklist-table{
    width:100%;
    min-width:900px;
    margin:0;
    border-collapse:separate;
    border-spacing:0;
}

.contract-checklist-table thead th{
    padding:10px 12px;
    border-right:1px solid rgba(255,255,255,.35);
    border-bottom:0;
    background:#315b9e;
    color:#fff;
    font-size:12px;
    font-weight:750;
    letter-spacing:.02em;
    white-space:nowrap;
}

.contract-checklist-table thead th:last-child{
    border-right:0;
}

.contract-task-item{
    transition:background-color .16s ease, box-shadow .16s ease;
}

.contract-task-item td{
    padding:11px 12px;
    border-right:1px solid #dbe2ea;
    border-bottom:1px solid #dbe2ea;
    color:#273244;
    font-size:13px;
    vertical-align:middle;
}

.contract-task-item td:last-child{
    border-right:0;
}

.contract-task-item:hover{
    background:#fff8df;
}

.contract-task-item.contract-task-focus{
    background:#fff1bf;
    box-shadow:inset 4px 0 0 #ffc107;
}

.contract-task-checkbox{
    width:18px;
    height:18px;
    cursor:pointer;
}

.contract-task-text{
    font-size:13px;
    font-weight:700;
    color:#212529;
    line-height:1.35;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.contract-task-meta{
    font-size:11px;
    color:#6c757d;
    margin-top:3px;
}

.task-status-wrap{
    display:flex;
    align-items:center;
    gap:8px;
    white-space:nowrap;
}

.task-status-cell{
    cursor:pointer;
    user-select:none;
}

.task-status-cell:hover{
    background:#fff3cd;
}

.task-status-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:4px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:750;
}

.task-status-badge.pending{
    background:#fff3cd;
    color:#795b00;
}

.task-status-badge.completed{
    background:#dff5e7;
    color:#146c43;
}

.task-date-cell,
.task-created-cell{
    min-width:145px;
}

.task-remark-cell{
    min-width:170px;
    color:#566274 !important;
}

.task-ticked-by{
    display:block;
    margin-top:5px;
    padding-top:5px;
    border-top:1px dashed #cbd5e1;
    color:#4b6078;
    font-size:12px;
}

.task-document-cell{
    min-width:118px;
}

.task-document-button{
    display:inline-flex;
    align-items:center;
    gap:6px;
    min-height:34px;
    padding:6px 9px;
    border:1px solid #b7c5d9;
    border-radius:8px;
    background:#fff;
    color:#315b9e;
    font-size:12px;
    font-weight:700;
}

.task-document-button:hover{
    border-color:#315b9e;
    background:#eef4ff;
}

.task-document-indicator{
    display:inline-flex;
    align-items:center;
    gap:4px;
    margin-left:8px;
    color:#0d6efd;
    font-weight:600;
}

.task-claim-indicator{
    color:#f59f00;
}

.contract-task-actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    justify-content:flex-end;
    white-space:nowrap;
}

.task-icon-btn{
    border:none;
    border-radius:9px;
    width:32px;
    height:32px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.task-completed{
    background:#f3fbf6;
}

.task-completed .contract-task-text{
    color:#146c43;
}

.task-empty-state{
    margin:0 17px 17px;
    border:1px dashed #b9c5d5;
    border-radius:12px;
    padding:24px;
    text-align:center;
    color:#6c757d;
}

.task-loading{
    color:#0d6efd;
    font-size:14px;
}

.task-document-upload-box{
    background:#fff8e1;
    border:1px solid #ffe69c;
    border-radius:10px;
    padding:10px;
}

.contract-tools-modal .modal-body{
    padding:20px;
    background:#f7f9fc;
}

.contract-tools-section{
    padding:16px;
    border:1px solid #dbe3ee;
    border-radius:13px;
    background:#fff;
}

.contract-upload-feedback{
    display:none;
    margin-top:10px;
}

#contractModal,
#contractAttachmentsModal,
#contractImportModal,
#addTaskModal,
#editTaskModal,
#taskDocumentModal{
    z-index:1200;
}

.modal-backdrop.show{
    z-index:1190;
}

.task-document-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}

.task-document-info{
    min-width:0;
    overflow-wrap:anywhere;
}

.task-document-actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.contract-mobile-modal-footer{
    display:none;
}

@media(max-width: 1200px){
    #contractsTable th,
    #contractsTable td{
        white-space:normal !important;
        word-break:break-word !important;
        overflow-wrap:anywhere !important;
        font-size:11px;
        padding:7px 5px;
    }

    .contract-action-btn{
        display:inline-block;
        margin-bottom:4px;
    }
}

@media(max-width: 768px){
    html,
    body{
        width:100%;
        max-width:100%;
        overflow-x:hidden !important;
        overscroll-behavior-x:none;
    }

    body.modal-open{
        width:100%;
        max-width:100%;
        overflow-x:hidden !important;
    }

    .topbar,
    .main,
    .main.expanded,
    .contract-page-wrap{
        width:100%;
        max-width:100%;
        min-width:0;
        overflow-x:hidden !important;
    }

    .main{
        padding-left:14px !important;
        padding-right:14px !important;
    }

    /* Keep this wide table page beside the open navigation instead of
       allowing its controls and table to sit underneath the sidebar. */
    body.sidebar-open .main,
    body.sidebar-mobile-open .main{
        margin-left:230px !important;
        width:calc(100% - 230px) !important;
        max-width:calc(100% - 230px) !important;
    }

    .contract-search-form .input-group{
        flex-direction:column;
        gap:8px;
    }

    .contract-search-form .input-group input,
    .contract-search-form .input-group button{
        width:100%;
        border-radius:8px !important;
    }

    .contract-search-form .form-control,
    .contract-search-form .btn{
        min-height:44px;
    }

    .contract-table-card{
        width:100%;
        max-width:100%;
        min-width:0;
        background:#fff;
        box-shadow:0 4px 14px rgba(0,0,0,0.06);
        overflow-x:hidden !important;
        overflow-y:hidden;
    }

    .contract-table-responsive{
        display:block;
        width:100%;
        max-width:100%;
        min-width:0;
        overflow-x:hidden !important;
        overflow-y:hidden;
    }

    .contract-table-scroll{
        display:block;
        width:100%;
        max-width:100%;
        min-width:0;
        overflow-x:auto !important;
        overflow-y:hidden;
        -webkit-overflow-scrolling:touch;
        overscroll-behavior-x:contain;
        contain:paint;
    }

    #contractsTable_wrapper,
    .contract-dt-footer{
        width:100% !important;
        max-width:100% !important;
        min-width:0 !important;
        overflow-x:hidden !important;
    }

    .contract-table-responsive .row,
    .contract-table-responsive .dataTables_info,
    .contract-table-responsive .dataTables_paginate{
        max-width:100%;
    }

    .contract-table-responsive #contractsTable_wrapper{
        width:100% !important;
        min-width:0 !important;
        max-width:100% !important;
        overflow-x:hidden !important;
    }

    #contractsTable,
    #contractsTable thead,
    #contractsTable tbody,
    #contractsTable th,
    #contractsTable td,
    #contractsTable tr{
        width:auto !important;
    }

    #contractsTable thead{
        display:table-header-group !important;
    }

    #contractsTable tbody{
        display:table-row-group !important;
    }

    #contractsTable th{
        display:table-cell !important;
        min-width:110px;
        max-width:none !important;
        white-space:nowrap !important;
        word-break:normal !important;
        overflow-wrap:normal !important;
    }

    #contractsTable td{
        display:table-cell !important;
        min-width:110px;
        max-width:260px !important;
        white-space:normal !important;
        word-break:break-word !important;
        overflow-wrap:anywhere !important;
    }

    #contractsTable{
        display:table !important;
        width:max-content !important;
        min-width:3300px !important;
        max-width:none !important;
        table-layout:auto !important;
        border-collapse:collapse !important;
        border-spacing:0 !important;
    }

    #contractsTable tbody tr{
        display:table-row !important;
        background:inherit;
        border:0;
        border-radius:0;
        padding:0;
        margin-bottom:0;
        box-shadow:none;
        transform:none !important;
    }

    #contractsTable tbody tr:hover{
        background:#fff8e1 !important;
        transform:none !important;
    }

    #contractsTable td{
        display:table-cell !important;
        border-bottom-width:1px !important;
        padding:8px 6px !important;
        max-width:260px !important;
        min-width:110px;
        font-size:12px;
    }

    #contractsTable .contract-col-project,
    #contractsTable .contract-col-owner,
    #contractsTable .contract-col-project-manager,
    #contractsTable .contract-col-account-manager{
        min-width:170px;
    }

    #contractsTable .contract-col-actions{
        min-width:150px;
    }

    #contractsTable .contract-col-start,
    #contractsTable .contract-col-end,
    #contractsTable .contract-col-status,
    #contractsTable .contract-col-progress,
    #contractsTable .contract-col-amount,
    #contractsTable .contract-col-actions{
        white-space:nowrap !important;
        max-width:none !important;
        word-break:normal !important;
        overflow-wrap:normal !important;
    }

    #contractsTable td:last-child{
        border-bottom-width:1px !important;
    }

    #contractsTable td::before{
        content:none !important;
        display:none !important;
    }

    .contract-col-actions{
        display:table-cell !important;
    }

    .contract-col-actions::before{
        content:none !important;
        display:none !important;
    }

    .contract-action-btn{
        min-width:34px;
        max-width:34px;
        margin:2px;
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

    .status-filter-menu{
        max-width:calc(100vw - 24px);
    }

    #contractModal .modal-dialog,
    #contractAttachmentsModal .modal-dialog,
    #addTaskModal .modal-dialog,
    #editTaskModal .modal-dialog,
    #taskDocumentModal .modal-dialog{
        width:calc(100% - 20px);
        max-width:calc(100% - 20px);
        margin:10px auto;
    }

    #contractModal .modal-content,
    #contractAttachmentsModal .modal-content,
    #addTaskModal .modal-content,
    #editTaskModal .modal-content,
    #taskDocumentModal .modal-content{
        max-height:calc(100dvh - 20px);
        overflow:hidden;
    }

    #contractModal .modal-header,
    #contractAttachmentsModal .modal-header,
    #addTaskModal .modal-header,
    #editTaskModal .modal-header,
    #taskDocumentModal .modal-header{
        position:sticky;
        top:0;
        z-index:3;
    }

    #contractModal .modal-body,
    #contractAttachmentsModal .modal-body,
    #addTaskModal .modal-body,
    #editTaskModal .modal-body,
    #taskDocumentModal .modal-body{
        overflow-x:hidden !important;
        overflow-y:auto;
        -webkit-overflow-scrolling:touch;
        padding:16px !important;
    }

    #filesContainer,
    #taskDocumentContainer{
        max-width:100%;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }

    #uploadSection .input-group,
    .task-document-upload-box .input-group{
        flex-direction:column;
        gap:8px;
    }

    #uploadSection .input-group .form-control,
    #uploadSection .input-group .btn,
    .task-document-upload-box .input-group .form-control,
    .task-document-upload-box .input-group .btn{
        width:100%;
        border-radius:8px !important;
    }

    .contract-mobile-modal-footer{
        display:flex;
        position:sticky;
        bottom:0;
        z-index:3;
        background:#fff;
    }

    .contract-overview-grid{
        grid-template-columns:1fr;
    }

    .contract-detail-toolbar{
        align-items:stretch;
    }

    .contract-detail-actions,
    .contract-detail-action{
        width:100%;
    }

    .contract-checklist-table{
        min-width:820px;
    }

    .contract-checklist-table .contract-task-actions{
        justify-content:flex-start;
        flex-wrap:nowrap;
        padding-left:0;
    }
}

@media(max-width: 430px){
    h2{
        font-size:22px;
    }

    .contract-filter-hint{
        font-size:12px;
    }

    .contract-search-form .form-control{
        font-size:13px;
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

<form method="GET" class="contract-search-form mb-0" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveContractSearch"
            name="search"
            class="form-control"
            placeholder="Search project code, contract no, project name... Example: PRO/IWK/001, 2026"
            value="<?= contractEscape($search) ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i> Search
        </button>
    </div>
</form>

<div class="contract-filter-hint mt-2 mb-2">
    Search supports Project Code, Contract No, Project Name, owner, dates, end user, and status. Use comma for multiple terms, example: <b>PRO/IWK/001, active</b>.
    Click the yellow Status header to filter status.
</div>

<div id="activeFilterBox" class="active-filter-box"></div>

<?php if(is_array($contractImportResult)): ?>
<div class="alert alert-<?= contractEscape($contractImportResult['status'] ?? 'info') ?> alert-dismissible fade show" role="alert">
    <?= contractEscape($contractImportResult['message'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if($canAddContract): ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex gap-2 flex-wrap">
        <a href="contract_add.php" class="btn btn-warning">
            <i class="fa fa-plus"></i> Add Contract
        </a>
        <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#contractImportModal">
            <i class="fa fa-file-import"></i> Import
        </button>
    </div>
    <label class="d-flex align-items-center gap-2 mb-0">
        <span>Show</span>
        <select id="contractPageLength" class="form-select form-select-sm" style="width:82px">
            <option value="10">10</option><option value="25">25</option>
            <option value="50">50</option><option value="100">100</option>
        </select>
        <span>rows</span>
    </label>
</div>
<?php else: ?>
<div class="d-flex justify-content-end align-items-center mb-3">
    <label class="d-flex align-items-center gap-2 mb-0">
        <span>Show</span>
        <select id="contractPageLength" class="form-select form-select-sm" style="width:82px">
            <option value="10">10</option><option value="25">25</option>
            <option value="50">50</option><option value="100">100</option>
        </select>
        <span>rows</span>
    </label>
</div>
<?php endif; ?>

<div class="contract-table-card">
<div class="contract-table-responsive">

<table id="contractsTable" class="table table-hover table-striped align-middle">

<thead>
<tr>
    <th>Project Code</th>
    <th>Year Awarded</th>
    <th><span class="header-filter" id="statusHeaderFilter">Status <i class="fa fa-filter"></i></span></th>
    <th>Project Name</th>
    <th>Project Manager</th>
    <th>Account Manager</th>
    <th>Owner</th>
    <th>End User</th>
    <th>PO Number</th>
    <th>Service</th>
    <th>PO / SST Date</th>
    <th>Start</th>
    <th>End</th>
    <th>Progress</th>
    <th>Amount</th>
    <th>Payment Term</th>
    <th>No. of PM</th>
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
    <button type="button" data-status="In Progress">In Progress</button>
    <button type="button" data-status="Awarded">Awarded</button>
    <button type="button" data-status="Closed">Closed</button>
    <button type="button" data-status="Drop">Drop</button>
</div>

<?php if($canAddContract): ?>
<div class="modal fade" id="contractImportModal" tabindex="-1" aria-labelledby="contractImportModalTitle" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<form id="contractImportForm" action="../backend/import_contracts.php" method="POST" enctype="multipart/form-data" class="modal-content">
    <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="contractImportModalTitle"><i class="fa fa-file-excel"></i> Import Contracts</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= contractEscape($csrfToken) ?>">
        <label class="form-label" for="projectImportFile">Crossroad Project Excel File</label>
        <input type="file" id="projectImportFile" name="project_file" class="form-control" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        <div class="alert alert-info small mt-3 mb-0">
            Only the <strong>Project List</strong> sheet will be read. Payment Milestone and all other sheets are ignored. Existing contracts remain unchanged; only new contracts are added.
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" id="contractImportSubmit" class="btn btn-warning"><i class="fa fa-upload"></i> Import Contracts</button>
    </div>
</form>
</div>
</div>
<?php endif; ?>

<!-- CONTRACT MODAL -->
<div class="modal fade contract-detail-modal" id="contractModal" tabindex="-1" aria-labelledby="contractModalTitle" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content border-0 shadow-lg rounded-4">

<div class="modal-header">
    <h5 class="modal-title" id="contractModalTitle">
        <i class="fa fa-file-contract"></i> <span id="contractModalTitleText">Contract Details</span>
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body">
<input type="hidden" name="contract_id" id="m_id">

<div class="contract-overview-grid">
<div class="contract-overview-card">
    <span class="contract-overview-label">Project</span>
    <div id="m_project" class="contract-overview-value"></div>
</div>
<div class="contract-overview-card">
    <span class="contract-overview-label">Contract Number</span>
    <div id="m_contractno" class="contract-overview-value"></div>
</div>
<div class="contract-overview-card">
    <span class="contract-overview-label">Contract Amount</span>
    <div id="m_amount" class="contract-overview-value text-success"></div>
    <?php if($canViewClaim): ?>
    <div id="m_claimSummary" class="mt-2 small d-none">
        <div>Amount: <span id="m_originalAmount" class="fw-bold text-success"></span></div>
        <div>Claim Amount: <span id="m_claimAmount" class="fw-bold text-danger"></span></div>
        <div>Leftover: <span id="m_leftoverAmount" class="fw-bold text-success"></span></div>
    </div>
    <?php endif; ?>
</div>
<div class="contract-overview-card">
    <span class="contract-overview-label">Created By</span>
    <div id="m_createdby" class="contract-overview-value"></div>
</div>
</div>

<div class="contract-detail-toolbar">
    <div class="contract-detail-toolbar-copy">
        <strong>Contract workspace</strong>
        <span>Documents and new checklist items open in a focused window.</span>
    </div>
    <div class="contract-detail-actions">
        <button type="button" class="btn btn-outline-primary contract-detail-action" id="openContractAttachmentsBtn" onclick="openContractAttachmentsModal()">
            <i class="fa fa-paperclip"></i> Attachments
            <span class="attachment-count-badge" id="contractAttachmentCount">...</span>
        </button>
        <button type="button" class="btn btn-warning contract-detail-action d-none" id="openAddTaskBtn" onclick="openAddTaskModal()">
            <i class="fa fa-plus"></i> Add Checklist Item
        </button>
    </div>
</div>

<div class="contract-checklist-panel">
<div id="tasksContainer" class="task-card" aria-live="polite">
    <div class="task-loading">
        <i class="fa fa-spinner fa-spin"></i> Loading tasks...
    </div>
</div>

</div>
</div>
<div class="modal-footer contract-mobile-modal-footer">
    <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">
        Close
    </button>
</div>
</div>
</div>
</div>

<!-- CONTRACT ATTACHMENTS MODAL -->
<div class="modal fade contract-tools-modal" id="contractAttachmentsModal" tabindex="-1" aria-labelledby="contractAttachmentsModalTitle" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header">
    <div>
        <h5 class="modal-title" id="contractAttachmentsModalTitle"><i class="fa fa-paperclip"></i> Contract Attachments</h5>
        <div class="small opacity-75">View, download or upload contract documents.</div>
    </div>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
    <div class="contract-tools-section">
        <div id="filesContainer" aria-live="polite">Loading...</div>
    </div>
    <div id="uploadSection" class="contract-tools-section mt-3">
        <form id="contractUploadForm" action="../backend/upload_contract.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="contract_id" id="attachmentContractId">
            <label class="form-label fw-semibold" for="contractAttachmentFile">Upload a document</label>
            <div class="input-group">
                <input type="file" name="file" id="contractAttachmentFile" class="form-control" required>
                <button class="btn btn-warning" id="contractUploadBtn" type="submit"><i class="fa fa-upload"></i> Upload</button>
            </div>
            <div class="contract-upload-feedback alert mb-0" id="contractUploadFeedback" role="status"></div>
        </form>
    </div>
</div>
</div>
</div>
</div>

<!-- ADD CHECKLIST MODAL -->
<div class="modal fade contract-tools-modal" id="addTaskModal" tabindex="-1" aria-labelledby="addTaskModalTitle" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header">
    <div>
        <h5 class="modal-title" id="addTaskModalTitle"><i class="fa fa-list-check"></i> Add Checklist Item</h5>
        <div class="small opacity-75">Create one clear checklist entry at a time.</div>
    </div>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
    <div class="contract-tools-section">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Checklist Type <span class="text-danger">*</span></label>
                <select id="newTaskType" class="form-select">
                    <option value="">Select type</option>
                    <option value="preventive">Preventive Maintenance</option>
                    <option value="kickoff">Kickoff</option>
                    <option value="training">Training</option>
                    <option value="meeting">Meeting</option>
                    <option value="corrective">Corrective Maintenance</option>
                    <option value="claim">Claim</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-8 task-entry-field task-common-field d-none">
                <label class="form-label">Remark</label>
                <input type="text" id="newTaskRemark" class="form-control" placeholder="Add a note for this checklist item" autocomplete="off">
            </div>
            <div class="col-md-4 task-entry-field task-maintenance-field d-none">
                <label class="form-label" id="newTaskMaintenanceLabel">Preventive Maintenance Number</label>
                <select id="newTaskMaintenanceNumber" class="form-select">
                    <option value="">Select number</option>
                    <?php for($number = 1; $number <= 20; $number++): ?>
                        <option value="<?= $number ?>"><?= $number ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-8 task-entry-field task-claim-field d-none">
                <label class="form-label">Claim Remark</label>
                <input type="text" id="newTaskClaimRemark" class="form-control" placeholder="Claim for..." autocomplete="off">
            </div>
            <div class="col-md-8 task-entry-field task-other-field d-none">
                <label class="form-label">Checklist Item</label>
                <input type="text" id="newContractTaskText" class="form-control" placeholder="Example: Purchase Order (PO)" autocomplete="off">
            </div>
            <div class="col-md-3 task-entry-field task-common-field d-none">
                <label class="form-label">Start Date</label>
                <input type="date" id="newTaskStartDate" class="form-control">
            </div>
            <div class="col-md-3 task-entry-field task-common-field d-none">
                <label class="form-label">End Date</label>
                <input type="date" id="newTaskEndDate" class="form-control">
            </div>
            <?php if($canViewClaim): ?>
            <div class="col-md-3 task-entry-field task-claim-field d-none">
                <label class="form-label">Claim Amount</label>
                <input type="number" id="newTaskClaimAmount" class="form-control" min="0.01" step="0.01" placeholder="0.00">
            </div>
            <?php endif; ?>
            <div class="col-md-3 task-entry-field task-claim-field d-none">
                <label class="form-label">Invoice</label>
                <input type="text" id="newTaskInvoice" class="form-control" placeholder="Invoice no." autocomplete="off">
            </div>
            <div class="col-12 task-entry-field task-common-field d-none" id="newTaskDocumentWrapper">
                <label class="form-label">Checklist Document <span class="text-muted fw-normal">(optional)</span></label>
                <input type="file" id="newTaskDocument" class="form-control">
                <small class="text-muted">Maximum file size 100MB. ZIP files are allowed.</small>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="button" class="btn btn-warning" id="addTaskBtn" onclick="addContractTask()"><i class="fa fa-plus"></i> Add Item</button>
</div>
</div>
</div>
</div>

<!-- EDIT TASK MODAL -->
<div class="modal fade contract-tools-modal" id="editTaskModal">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">

<div class="modal-header">
    <h5 class="modal-title">
        <i class="fa fa-pen"></i> Edit Task
    </h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <input type="hidden" id="editTaskId">
    <input type="hidden" id="editTaskType">

    <label class="form-label">Task</label>
    <textarea id="editTaskText" class="form-control mb-3" rows="4"></textarea>

    <label class="form-label">Remark</label>
    <textarea id="editTaskRemark" class="form-control mb-3" rows="2" placeholder="Add a note for this checklist item"></textarea>

    <div class="row g-2">
        <div class="col-md-6">
            <label class="form-label">Task Start Date</label>
            <input type="date" id="editTaskStartDate" class="form-control">
        </div>
        <div class="col-md-6">
            <label class="form-label">Task End Date</label>
            <input type="date" id="editTaskEndDate" class="form-control">
        </div>
    </div>
    <?php if($canViewClaim): ?>
    <div id="editTaskClaimAmountWrapper" class="d-none">
        <label class="form-label mt-3">Claim Amount</label>
        <input type="number" id="editTaskClaimAmount" class="form-control" min="0" step="0.01" placeholder="0.00">
    </div>
    <?php endif; ?>
    <div id="editTaskInvoiceWrapper" class="d-none">
        <label class="form-label mt-3">Invoice</label>
        <input type="text" id="editTaskInvoice" class="form-control" placeholder="Invoice no." autocomplete="off">
    </div>
    <small class="text-muted d-block mt-2">
        Dated unfinished tasks appear in the Preventive Management dashboard bulletin until they are completed.
    </small>
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

<!-- TASK DOCUMENT MODAL -->
<div class="modal fade contract-tools-modal" id="taskDocumentModal">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">

<div class="modal-header">
    <h5 class="modal-title">
        <i class="fa fa-paperclip"></i> Checklist Documents
    </h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <input type="hidden" id="taskDocumentTaskId">
    <div id="taskDocumentContainer">
        <div class="task-loading">
            <i class="fa fa-spinner fa-spin"></i> Loading documents...
        </div>
    </div>
</div>

<div class="modal-footer contract-mobile-modal-footer">
    <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">
        Close
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
const contractCsrfToken = <?= json_encode($csrfToken) ?>;
const focusContractId = <?= json_encode($focusContractId) ?>;
const focusTaskId = <?= json_encode($focusTaskId) ?>;
const canViewClaim = <?= json_encode($canViewClaim) ?>;
const contractTaskDocumentMaxBytes = 100 * 1024 * 1024;
let currentCanUploadTaskDocument = false;
let pendingChecklistNotificationAction = "";
let currentTaskNotificationEmail = "";

document.getElementById("contractImportForm")?.addEventListener("submit", function(){
    let submitButton = document.getElementById("contractImportSubmit");
    if(submitButton){
        submitButton.disabled = true;
        submitButton.innerHTML = "<i class='fa fa-spinner fa-spin'></i> Reading Project List...";
    }
});

function withContractCsrf(data){
    data = data || {};
    data.csrf_token = contractCsrfToken;
    return data;
}

function validateContractTaskDocumentFile(file){
    if(!file){
        return true;
    }

    if(file.size > contractTaskDocumentMaxBytes){
        alert("Document is too large. Maximum checklist document size is 100MB.");
        return false;
    }

    return true;
}

function focusContractTask(taskId){
    taskId = parseInt(taskId, 10);

    if(!taskId){
        return;
    }

    let task = $('#tasksContainer .contract-task-item[data-task-id="' + taskId + '"]');

    if(!task.length){
        return;
    }

    task.addClass("contract-task-focus");

    if(task[0] && typeof task[0].scrollIntoView === "function"){
        task[0].scrollIntoView({
            behavior: "smooth",
            block: "center"
        });
    }

    setTimeout(function(){
        task.removeClass("contract-task-focus");
    }, 6500);
}

function loadContractTasks(taskToFocus){
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
        syncNewTaskEntryFields();
        focusContractTask(taskToFocus);
    }).fail(function(){
        $("#tasksContainer").html("<div class='alert alert-danger mb-0'>Failed to load checklist.</div>");
    });
}

function updateContractAttachmentCount(){
    let result = $("#filesContainer .contract-files-result");
    let count = result.length ? parseInt(result.data("file-count"), 10) : 0;

    if(isNaN(count)){
        count = 0;
    }

    $("#contractAttachmentCount").text(count);
}

function loadContractFiles(){
    let contractId = $("#m_id").val();

    if(!contractId){
        $("#filesContainer").html("<div class='alert alert-warning mb-0'>No contract selected.</div>");
        $("#contractAttachmentCount").text("0");
        return;
    }

    $("#filesContainer").html("<div class='text-primary small'><i class='fa fa-spinner fa-spin'></i> Loading attachments...</div>");

    $.post("../backend/get_contract_files.php", {id: contractId}, function(fileData){
        $("#filesContainer").html(fileData);
        updateContractAttachmentCount();
    }).fail(function(){
        $("#filesContainer").html("<div class='alert alert-danger mb-0'>Failed to load attachments.</div>");
        $("#contractAttachmentCount").text("!");
    });
}

function showContractToolModal(modalId){
    let contractModalElement = document.getElementById("contractModal");
    let toolModalElement = document.getElementById(modalId);

    if(!toolModalElement){
        return;
    }

    let showToolModal = function(){
        toolModalElement.dataset.restoreContractModal = "1";
        bootstrap.Modal.getOrCreateInstance(toolModalElement).show();
    };

    if(contractModalElement && contractModalElement.classList.contains("show")){
        contractModalElement.addEventListener("hidden.bs.modal", showToolModal, {once:true});
        bootstrap.Modal.getOrCreateInstance(contractModalElement).hide();
        return;
    }

    showToolModal();
}

function openContractAttachmentsModal(){
    if(!$("#m_id").val()){
        return;
    }

    showContractToolModal("contractAttachmentsModal");
}

function openAddTaskModal(){
    if($("#openAddTaskBtn").hasClass("d-none")){
        return;
    }

    $("#newTaskType").val("");
    resetNewTaskEntryDetails();
    syncNewTaskEntryFields();
    showContractToolModal("addTaskModal");
}

function reloadContractTableProgress(){
    if(typeof contractsTable !== "undefined" && contractsTable){
        contractsTable.ajax.reload(function(){
            refreshOpenContractFinancials();
        }, false);
    }
}

function formatContractMoney(value){
    value = parseFloat(value);

    if(isNaN(value)){
        value = 0;
    }

    return "RM " + value.toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function applyContractFinancialSummary(meta){
    meta = meta || {};

    let amount = parseFloat(meta.amount);

    if(isNaN(amount)){
        amount = 0;
    }

    if(!canViewClaim){
        $('#m_amount').removeClass("d-none").text(formatContractMoney(amount));
        return;
    }

    let claimAmount = parseFloat(meta.claimamount || 0);
    let leftoverAmount = parseFloat(meta.leftoveramount || 0);

    if(isNaN(claimAmount)){
        claimAmount = 0;
    }

    if(isNaN(leftoverAmount)){
        leftoverAmount = Math.max(0, amount - claimAmount);
    }

    if(claimAmount > 0){
        $('#m_amount').addClass("d-none").text("");
        $('#m_claimAmount').text(formatContractMoney(claimAmount));
        $('#m_originalAmount').text(formatContractMoney(amount));
        $('#m_leftoverAmount').text(formatContractMoney(leftoverAmount));
        $('#m_claimSummary').removeClass("d-none");
    } else {
        $('#m_amount').removeClass("d-none").text(formatContractMoney(amount));
        $('#m_claimSummary').addClass("d-none");
        $('#m_claimAmount').text("");
        $('#m_originalAmount').text("");
        $('#m_leftoverAmount').text("");
    }
}

function refreshOpenContractFinancials(){
    if(typeof contractsTable === "undefined" || !contractsTable){
        return;
    }

    let currentContractId = $("#m_id").val();

    if(!currentContractId){
        return;
    }

    contractsTable.rows().every(function(){
        let rowData = this.data();

        if(rowData && rowData.meta && String(rowData.meta.id) === String(currentContractId)){
            applyContractFinancialSummary(rowData.meta);
            return false;
        }

        return true;
    });
}

function syncNewTaskEntryFields(){
    let type = $("#newTaskType").val() || "";

    $(".task-entry-field").addClass("d-none");

    if(type === ""){
        return;
    }

    $(".task-common-field").removeClass("d-none");

    if(type === "preventive"){
        $(".task-maintenance-field").removeClass("d-none");
        $("#newTaskMaintenanceLabel").text("Preventive Maintenance Number");
        if(!currentCanUploadTaskDocument) $("#newTaskDocumentWrapper").addClass("d-none");
        return;
    }

    if(type === "corrective"){
        $(".task-other-field").removeClass("d-none");
        if(!currentCanUploadTaskDocument) $("#newTaskDocumentWrapper").addClass("d-none");
        return;
    }

    if(type === "claim"){
        $(".task-claim-field").removeClass("d-none");
        if(!currentCanUploadTaskDocument) $("#newTaskDocumentWrapper").addClass("d-none");
        return;
    }

    if(type === "other"){
        $(".task-other-field").removeClass("d-none");
    }

    if(!currentCanUploadTaskDocument) $("#newTaskDocumentWrapper").addClass("d-none");
}

function resetNewTaskEntryDetails(){
    $("#newTaskMaintenanceNumber").val("");
    $("#newTaskClaimRemark").val("");
    $("#newTaskInvoice").val("");
    $("#newContractTaskText").val("");
    $("#newTaskStartDate").val("");
    $("#newTaskEndDate").val("");
    $("#newTaskClaimAmount").val("");
    $("#newTaskRemark").val("");

    let taskDocumentInput = document.getElementById("newTaskDocument");

    if(taskDocumentInput){
        taskDocumentInput.value = "";
    }
}

function buildNewContractTaskText(){
    let type = $("#newTaskType").val() || "";

    if(type === ""){
        alert("Please choose a checklist type.");
        return "";
    }

    if(type === "preventive"){
        let number = $("#newTaskMaintenanceNumber").val();

        if(number === ""){
            alert("Please choose the preventive maintenance number.");
            return "";
        }

        return "Preventive Maintenance " + number;
    }

    if(type === "kickoff"){
        return "Kickoff";
    }

    if(type === "training"){
        return "Training";
    }

    if(type === "meeting"){
        return "Meeting";
    }

    if(type === "corrective"){
        let remark = $("#newContractTaskText").val().trim();

        if(remark === ""){
            alert("Please enter the corrective maintenance remark.");
            return "";
        }

        return "Corrective Maintenance - " + remark;
    }

    if(type === "claim"){
        let remark = $("#newTaskClaimRemark").val().trim();

        if(remark === ""){
            alert("Please enter the claim remark.");
            return "";
        }

        return "Claim - " + remark;
    }

    let taskText = $("#newContractTaskText").val().trim();

    if(taskText === ""){
        alert("Please enter the checklist item.");
        return "";
    }

    return taskText;
}

function validateChecklistBeforeNotification(action){
    if(action === "edit"){
        let taskText = $("#editTaskText").val().trim();
        let taskStartDate = $("#editTaskStartDate").val();
        let taskEndDate = $("#editTaskEndDate").val();
        let taskType = $("#editTaskType").val() || "other";

        if(taskText === ""){ alert("Task cannot be empty."); return false; }
        if(taskStartDate === "" && taskEndDate !== ""){ alert("Please select a task start date first."); return false; }
        if(taskStartDate !== "" && taskEndDate !== "" && taskEndDate < taskStartDate){ alert("Task end date cannot be before the start date."); return false; }
        if(taskType === "claim" && ($("#editTaskInvoice").val() || "").trim() === ""){ alert("Please enter the invoice."); return false; }
        return true;
    }

    let taskType = $("#newTaskType").val() || "";
    let taskText = buildNewContractTaskText();
    let taskStartDate = $("#newTaskStartDate").val();
    let taskEndDate = $("#newTaskEndDate").val();
    let taskDocumentInput = document.getElementById("newTaskDocument");

    if(taskType === "" || taskText === ""){ return false; }
    if(taskStartDate === "" && taskEndDate !== ""){ alert("Please select a task start date first."); return false; }
    if(taskStartDate !== "" && taskEndDate !== "" && taskEndDate < taskStartDate){ alert("Task end date cannot be before the start date."); return false; }
    if(taskType === "claim" && canViewClaim && ($("#newTaskClaimAmount").val() || "").trim() === ""){ alert("Please enter the claim amount."); return false; }
    if(taskType === "claim" && ($("#newTaskInvoice").val() || "").trim() === ""){ alert("Please enter the invoice."); return false; }
    if(taskDocumentInput && taskDocumentInput.files.length > 0 && !validateContractTaskDocumentFile(taskDocumentInput.files[0])){ return false; }
    return true;
}

function requestChecklistNotification(action){
    pendingChecklistNotificationAction = action === "edit" ? "edit" : "add";

    if(!validateChecklistBeforeNotification(pendingChecklistNotificationAction)){
        return;
    }

    let sourceModalId = pendingChecklistNotificationAction === "edit" ? "editTaskModal" : "addTaskModal";
    let sourceModalElement = document.getElementById(sourceModalId);
    let notificationModalElement = document.getElementById("checklistNotificationModal");
    let notificationEmailInput = document.getElementById("checklistNotificationEmail");

    notificationEmailInput.value = pendingChecklistNotificationAction === "edit" ? currentTaskNotificationEmail : "";

    let showNotificationModal = function(){
        bootstrap.Modal.getOrCreateInstance(notificationModalElement).show();
        notificationModalElement.addEventListener("shown.bs.modal", function(){
            notificationEmailInput.focus();
        }, {once:true});
    };

    if(sourceModalElement && sourceModalElement.classList.contains("show")){
        sourceModalElement.dataset.emailNotificationTransition = "1";
        sourceModalElement.addEventListener("hidden.bs.modal", showNotificationModal, {once:true});
        bootstrap.Modal.getOrCreateInstance(sourceModalElement).hide();
    }else{
        showNotificationModal();
    }
}

function backFromChecklistNotification(){
    let notificationModalElement = document.getElementById("checklistNotificationModal");
    let sourceModalId = pendingChecklistNotificationAction === "edit" ? "editTaskModal" : "addTaskModal";

    notificationModalElement.addEventListener("hidden.bs.modal", function(){
        bootstrap.Modal.getOrCreateInstance(document.getElementById(sourceModalId)).show();
    }, {once:true});

    bootstrap.Modal.getOrCreateInstance(notificationModalElement).hide();
}

function confirmChecklistNotification(){
    let emailInput = document.getElementById("checklistNotificationEmail");

    if(emailInput.value.trim() !== "" && !emailInput.checkValidity()){
        emailInput.reportValidity();
        return;
    }

    let action = pendingChecklistNotificationAction;
    let notificationModalElement = document.getElementById("checklistNotificationModal");

    if(action === "edit"){
        currentTaskNotificationEmail = emailInput.value.trim();
    }

    notificationModalElement.addEventListener("hidden.bs.modal", function(){
        if(action === "edit"){
            updateContractTask();
        }else{
            addContractTask();
        }
    }, {once:true});

    bootstrap.Modal.getOrCreateInstance(notificationModalElement).hide();
}

function restoreContractAfterChecklistSave(sourceModalId){
    let sourceModalElement = document.getElementById(sourceModalId);

    if(sourceModalElement){
        delete sourceModalElement.dataset.restoreContractModal;
        delete sourceModalElement.dataset.emailNotificationTransition;
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById("contractModal")).show();
}

function reopenChecklistAfterSaveError(sourceModalId){
    bootstrap.Modal.getOrCreateInstance(document.getElementById(sourceModalId)).show();
}

function addContractTask(){
    let contractId = $("#m_id").val();
    let taskType = $("#newTaskType").val() || "";
    let taskText = buildNewContractTaskText();
    let taskStartDate = $("#newTaskStartDate").val();
    let taskEndDate = $("#newTaskEndDate").val();
    let claimAmount = taskType === "claim" && canViewClaim ? (($("#newTaskClaimAmount").val() || "").trim()) : "";
    let invoice = taskType === "claim" ? (($("#newTaskInvoice").val() || "").trim()) : "";
    let remark = ($("#newTaskRemark").val() || "").trim();
    let notificationEmail = ($("#checklistNotificationEmail").val() || "").trim();
    let taskDocumentInput = document.getElementById("newTaskDocument");

    if(!contractId){
        alert("No contract selected.");
        return;
    }

    if(taskText === ""){
        return;
    }

    if(taskStartDate === "" && taskEndDate !== ""){
        alert("Please select a task start date first.");
        return;
    }

    if(taskStartDate !== "" && taskEndDate !== "" && taskEndDate < taskStartDate){
        alert("Task end date cannot be before the start date.");
        return;
    }

    if(taskType === "claim" && canViewClaim && claimAmount === ""){
        alert("Please enter the claim amount.");
        return;
    }

    if(taskType === "claim" && canViewClaim && (isNaN(parseFloat(claimAmount)) || parseFloat(claimAmount) <= 0)){
        alert("Claim amount must be more than zero.");
        return;
    }

    if(taskType === "claim" && invoice === ""){
        alert("Please enter the invoice.");
        return;
    }

    if(claimAmount !== "" && (isNaN(parseFloat(claimAmount)) || parseFloat(claimAmount) < 0)){
        alert("Claim amount must be a positive number.");
        return;
    }

    if(taskDocumentInput && taskDocumentInput.files.length > 0 && !validateContractTaskDocumentFile(taskDocumentInput.files[0])){
        return;
    }

    $("#addTaskBtn").prop("disabled", true).html("<i class='fa fa-spinner fa-spin'></i>");

    let formData = new FormData();
    formData.append("csrf_token", contractCsrfToken);
    formData.append("contract_id", contractId);
    formData.append("task_type", taskType);
    formData.append("task_text", taskText);
    formData.append("task_start_date", taskStartDate);
    formData.append("task_end_date", taskEndDate);
    formData.append("claim_amount", claimAmount);
    formData.append("invoice", invoice);
    formData.append("remark", remark);
    formData.append("notification_email", notificationEmail);

    if(taskDocumentInput && taskDocumentInput.files.length > 0){
        formData.append("task_document", taskDocumentInput.files[0]);
    }

    $.ajax({
        url: "../backend/add_contract_task.php",
        type: "POST",
        data: formData,
        processData: false,
        contentType: false
    }).done(function(data){
        if(data.trim() === "success"){
            $("#newTaskType").val("");
            resetNewTaskEntryDetails();
            syncNewTaskEntryFields();

            let addModal = bootstrap.Modal.getInstance(document.getElementById("addTaskModal"));
            if(addModal) addModal.hide();

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

    $.post("../backend/toggle_contract_task.php", withContractCsrf({
        id: id,
        is_completed: isCompleted ? 1 : 0
    }), function(data){
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

function handleTaskStatusCellClick(event, cellEl){
    event.stopPropagation();

    if($(event.target).is("input")){
        return;
    }

    let checkbox = $(cellEl).find(".contract-task-checkbox").first();

    if(checkbox.length && !checkbox.prop("disabled")){
        checkbox[0].click();
    }
}

function openEditTaskModal(id, taskText, taskStartDate, taskEndDate, claimAmount, invoice, taskType, remark, notificationEmail){
    $("#editTaskId").val(id);
    $("#editTaskType").val(taskType || "other");
    $("#editTaskText").val(taskText);
    $("#editTaskRemark").val(remark || "");
    currentTaskNotificationEmail = notificationEmail || "";
    $("#editTaskStartDate").val(taskStartDate || "");
    $("#editTaskEndDate").val(taskEndDate || "");
    $("#editTaskClaimAmount").val(claimAmount || "");
    $("#editTaskInvoice").val(invoice || "");

    if(taskType === "claim"){
        $("#editTaskClaimAmountWrapper").removeClass("d-none");
        $("#editTaskInvoiceWrapper").removeClass("d-none");
    } else {
        $("#editTaskClaimAmountWrapper").addClass("d-none");
        $("#editTaskInvoiceWrapper").addClass("d-none");
        $("#editTaskClaimAmount").val("");
        $("#editTaskInvoice").val("");
    }

    showContractToolModal("editTaskModal");
}

function updateContractTask(){
    let id = $("#editTaskId").val();
    let taskType = $("#editTaskType").val() || "other";
    let taskText = $("#editTaskText").val().trim();
    let remark = $("#editTaskRemark").val().trim();
    let notificationEmail = ($("#checklistNotificationEmail").val() || "").trim();
    let taskStartDate = $("#editTaskStartDate").val();
    let taskEndDate = $("#editTaskEndDate").val();
    let claimAmount = taskType === "claim" && canViewClaim ? (($("#editTaskClaimAmount").val() || "").trim()) : "";
    let invoice = taskType === "claim" ? (($("#editTaskInvoice").val() || "").trim()) : "";

    if(taskText === ""){
        alert("Task cannot be empty.");
        return;
    }

    if(taskStartDate === "" && taskEndDate !== ""){
        alert("Please select a task start date first.");
        return;
    }

    if(taskStartDate !== "" && taskEndDate !== "" && taskEndDate < taskStartDate){
        alert("Task end date cannot be before the start date.");
        return;
    }

    if(claimAmount !== "" && (isNaN(parseFloat(claimAmount)) || parseFloat(claimAmount) < 0)){
        alert("Claim amount must be a positive number.");
        return;
    }

    if(taskType === "claim" && invoice === ""){
        alert("Please enter the invoice.");
        return;
    }

    let postData = {
        id: id,
        task_type: taskType,
        task_text: taskText,
        remark: remark,
        notification_email: notificationEmail,
        task_start_date: taskStartDate,
        task_end_date: taskEndDate,
        invoice: invoice
    };

    if(canViewClaim){
        postData.claim_amount = claimAmount;
    }

    $.post("../backend/update_contract_task.php", withContractCsrf(postData), function(data){
        if(data.trim() === "success"){
            let editModal = bootstrap.Modal.getInstance(document.getElementById("editTaskModal"));
            if(editModal) editModal.hide();
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

    $.post("../backend/delete_contract_task.php", withContractCsrf({
        id: id
    }), function(data){
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

function openContractTaskDocuments(taskId){
    $("#taskDocumentTaskId").val(taskId);
    $("#taskDocumentContainer").html("<div class='task-loading'><i class='fa fa-spinner fa-spin'></i> Loading documents...</div>");
    showContractToolModal("taskDocumentModal");
    loadContractTaskDocuments();
}

function loadContractTaskDocuments(){
    let taskId = $("#taskDocumentTaskId").val();

    if(!taskId){
        $("#taskDocumentContainer").html("<div class='alert alert-warning mb-0'>No checklist item selected.</div>");
        return;
    }

    $.post("../backend/get_contract_task_documents.php", {
        task_id: taskId
    }, function(data){
        $("#taskDocumentContainer").html(data);
    }).fail(function(){
        $("#taskDocumentContainer").html("<div class='alert alert-danger mb-0'>Failed to load checklist documents.</div>");
    });
}

function uploadContractTaskDocument(){
    let taskId = $("#taskDocumentTaskId").val();
    let fileInput = document.getElementById("taskDocumentFile");

    if(!taskId || !fileInput || fileInput.files.length <= 0){
        alert("Please choose a document.");
        return;
    }

    if(!validateContractTaskDocumentFile(fileInput.files[0])){
        return;
    }

    let formData = new FormData();
    formData.append("csrf_token", contractCsrfToken);
    formData.append("task_id", taskId);
    formData.append("file", fileInput.files[0]);

    $("#taskDocumentUploadBtn").prop("disabled", true).html("<i class='fa fa-spinner fa-spin'></i>");

    $.ajax({
        url: "../backend/upload_contract_task_document.php",
        type: "POST",
        data: formData,
        processData: false,
        contentType: false
    }).done(function(data){
        if(data.trim() === "success"){
            loadContractTaskDocuments();
            loadContractTasks();
        }else{
            alert(data);
        }
    }).fail(function(){
        alert("Failed to upload checklist document.");
    }).always(function(){
        $("#taskDocumentUploadBtn").prop("disabled", false).html("<i class='fa fa-upload'></i> Upload");
    });
}

function deleteContractTaskDocument(documentId){
    if(!confirm("Delete this checklist document?")){
        return;
    }

    $.post("../backend/delete_contract_task_document.php", withContractCsrf({
        id: documentId
    }), function(data){
        if(data.trim() === "success"){
            loadContractTaskDocuments();
            loadContractTasks();
        }else{
            alert(data);
        }
    }).fail(function(){
        alert("Failed to delete checklist document.");
    });
}

function deleteContractFile(fileId, contractId){
    if(!confirm("Delete this document?")){
        return;
    }

    $.post("../backend/delete_contract_file.php", {
        id: fileId
    }, function(data){

        if(data.trim() === "success"){
            loadContractFiles();

        }else{
            alert(data);
        }

    }).fail(function(){
        alert("Failed to delete document.");
    });
}
$(document).ready(function(){

    let initialSearch = <?= json_encode($search) ?>;
    let typingTimer = null;
    let statusFilter = "";
    let focusHandled = false;
    let focusSearchRetried = false;
    let activeTableRequest = null;
    let lastSubmittedSearch = initialSearch;

    $("#contractAttachmentsModal, #addTaskModal, #editTaskModal, #taskDocumentModal").on("hidden.bs.modal", function(){
        if(this.dataset.emailNotificationTransition === "1"){
            delete this.dataset.emailNotificationTransition;
            return;
        }

        if(this.dataset.restoreContractModal === "1"){
            delete this.dataset.restoreContractModal;
            bootstrap.Modal.getOrCreateInstance(document.getElementById("contractModal")).show();
        }
    });

    contractsTable = $('#contractsTable').DataTable({
        processing: true,
        serverSide: true,
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        autoWidth: false,
        scrollX: false,
        searching: true,
        search: {
            search: initialSearch
        },
        order: [],
        dom:
            "<'contract-table-scroll't>" +
            "<'contract-dt-footer d-flex justify-content-between align-items-center flex-wrap gap-3 mt-3'ip>",
        language: {
            processing: "<span class='processing-text'><i class='fa fa-spinner fa-spin'></i> Loading contracts...</span>"
        },
        ajax: {
            url: "contracts.php",
            type: "GET",
            cache: false,
            beforeSend: function(xhr){
                if(activeTableRequest && activeTableRequest.readyState !== 4){
                    activeTableRequest.abort();
                }
                activeTableRequest = xhr;
            },
            complete: function(xhr){
                if(activeTableRequest === xhr){
                    activeTableRequest = null;
                }
            },
            data: function(d){
                d.ajax = 1;
                d.status_filter = statusFilter;
            }
        },
        columnDefs: [
            { targets: 0, width: "120px" },
            { targets: 1, width: "110px" },
            { targets: 2, width: "120px" },
            { targets: 3, width: "360px" },
            { targets: 17, width: "210px" }
        ],
        columns: [
            { data: "project_code", className: "contract-col-project-code" },
            { data: "year_awarded", className: "contract-col-year-awarded" },
            { data: "status", className: "contract-col-status" },
            { data: "project_name", className: "contract-col-project" },
            { data: "project_manager", className: "contract-col-project-manager" },
            { data: "account_manager", className: "contract-col-account-manager" },
            { data: "owner", className: "contract-col-owner" },
            { data: "end_user" },
            { data: "po_number", className: "contract-col-contract-no" },
            { data: "service" },
            { data: "po_date" },
            { data: "start", className: "contract-col-start" },
            { data: "end", className: "contract-col-end" },
            { data: "progress", className: "contract-col-progress" },
            { data: "amount", className: "contract-col-amount" },
            { data: "payment_term" },
            { data: "no_of_pm" },
            {
                data: "actions",
                orderable: false,
                searchable: false,
                className: "contract-col-actions"
            }
        ],
        createdRow: function(row, data){
            $(row).attr("data-id", data.meta.id);
            $(row).attr("data-projectcode", data.meta.projectcode);
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

            $("td:eq(0)", row).attr("data-label", "Project Code");
            $("td:eq(1)", row).attr("data-label", "Contract No");
            $("td:eq(2)", row).attr("data-label", "Project Name");
            $("td:eq(3)", row).attr("data-label", "Owner");
            $("td:eq(4)", row).attr("data-label", "Project Manager");
            $("td:eq(5)", row).attr("data-label", "Account Manager");
            $("td:eq(6)", row).attr("data-label", "Start");
            $("td:eq(7)", row).attr("data-label", "End");
            $("td:eq(8)", row).attr("data-label", "Status");
            $("td:eq(9)", row).attr("data-label", "Progress");
            $("td:eq(10)", row).attr("data-label", "Amount");
            $("td:eq(17)", row).attr("data-label", "Actions");
        }
    });

    $("#contractPageLength").on("change", function(){
        contractsTable.page.len(parseInt(this.value, 10) || 10).draw();
    });

    function adjustContractTable(){
        setTimeout(function(){
            contractsTable.columns.adjust();
        }, 150);
    }

    $(window).on("resize", adjustContractTable);

    function escapeHtml(value){
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function updateActiveFilterBox(){
        let filters = [];

        if(statusFilter !== ""){
            filters.push("Status: " + escapeHtml(statusFilter));
        }

        let searchText = $("#liveContractSearch").val().trim();

        if(searchText !== ""){
            filters.push("Search: " + escapeHtml(searchText));
        }

        if(filters.length > 0){
            $("#activeFilterBox")
                .html("<strong>Active Filter:</strong> " + filters.join(" | "))
                .show();
        }else{
            $("#activeFilterBox").hide().html("");
        }
    }

    function clearFocusUrlParams(){
        if(!focusContractId && !focusTaskId){
            return;
        }

        let url = new URL(window.location.href);
        url.searchParams.delete("focus_contract");
        url.searchParams.delete("focus_task");

        window.history.replaceState(null, "", url.pathname + url.search);
    }

    function runContractSearch(value){
        value = String(value || "").trim();

        if(value === lastSubmittedSearch){
            return;
        }

        lastSubmittedSearch = value;
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
        }, 450);
    });

    $(".contract-search-form .btn-warning").on("click", function(){
        clearTimeout(typingTimer);
        runContractSearch($("#liveContractSearch").val());
    });

    $("#clearContractSearch").on("click", function(){
        $("#liveContractSearch").val("");
        lastSubmittedSearch = "";

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
        let isSmallScreen = window.matchMedia && window.matchMedia("(max-width: 768px)").matches;

        if(isSmallScreen){
            let rect = this.getBoundingClientRect();
            let menuWidth = menu.outerWidth() || 180;
            let left = Math.min(
                Math.max(12, rect.left),
                Math.max(12, window.innerWidth - menuWidth - 12)
            );

            menu.css({
                position: "fixed",
                top: rect.bottom + 6,
                left: left
            }).toggle();
        } else {
            let offset = $(this).offset();

            menu.css({
                position: "absolute",
                top: offset.top + $(this).outerHeight() + 6,
                left: offset.left
            }).toggle();
        }
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

    $(document).on("change", "#newTaskType", function(){
        resetNewTaskEntryDetails();
        syncNewTaskEntryFields();
    });

    $("#contractUploadForm").on("submit", function(e){
        e.preventDefault();

        let form = this;
        let fileInput = document.getElementById("contractAttachmentFile");

        if(!fileInput || fileInput.files.length <= 0){
            $("#contractUploadFeedback")
                .removeClass("alert-success alert-danger")
                .addClass("alert-warning")
                .text("Please choose a document first.")
                .show();
            return;
        }

        let formData = new FormData(form);
        $("#contractUploadBtn").prop("disabled", true).html("<i class='fa fa-spinner fa-spin'></i> Uploading...");
        $("#contractUploadFeedback").hide();

        $.ajax({
            url: form.action,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false
        }).done(function(data){
            if(String(data).trim() === "success"){
                fileInput.value = "";
                $("#contractUploadFeedback")
                    .removeClass("alert-warning alert-danger")
                    .addClass("alert-success")
                    .text("Document uploaded successfully.")
                    .show();
                loadContractFiles();
            } else {
                $("#contractUploadFeedback")
                    .removeClass("alert-warning alert-success")
                    .addClass("alert-danger")
                    .text(String(data).replace(/<[^>]*>/g, " ").trim() || "Upload failed.")
                    .show();
            }
        }).fail(function(){
            $("#contractUploadFeedback")
                .removeClass("alert-warning alert-success")
                .addClass("alert-danger")
                .text("Failed to upload the document.")
                .show();
        }).always(function(){
            $("#contractUploadBtn").prop("disabled", false).html("<i class='fa fa-upload'></i> Upload");
        });
    });

    $(document).on("keypress", "#newContractTaskText, #newTaskClaimRemark, #newTaskInvoice, #newTaskRemark", function(e){
        if(e.which === 13){
            e.preventDefault();
            addContractTask();
        }
    });

    function openContractModal(data, options){
        options = options || {};

        let meta = data.meta;

        $('#m_id').val(meta.id);
        $('#m_createdby').text(meta.createdby || '-');
        $('#m_project').text(meta.project || '-');
        $('#m_contractno').text(meta.contractno || '-');
        $('#contractModalTitleText').text(meta.projectcode ? 'Contract ' + meta.projectcode : 'Contract Details');

        applyContractFinancialSummary(meta);

        let canUploadThisContract = meta.canupload == 1;
        $('#uploadSection').toggle(canUploadThisContract);
        $('#attachmentContractId').val(meta.id);

        let canAddTaskThisContract = meta.canaddtask == 1;
        currentCanUploadTaskDocument = meta.canuploadtaskdocument == 1;
        $('#openAddTaskBtn').toggleClass('d-none', !canAddTaskThisContract);
        $('#newTaskDocumentWrapper').toggleClass('d-none', !currentCanUploadTaskDocument);

        $('#contractAttachmentCount').html("<i class='fa fa-spinner fa-spin'></i>");
        $('#contractUploadFeedback').hide();
        loadContractFiles();

        loadContractTasks(options.focusTaskId || 0);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('contractModal')).show();
    }

    function openFocusedContractFromAlert(){
        if(!focusContractId || focusHandled){
            return;
        }

        let row = $('#contractsTable tbody tr[data-id="' + focusContractId + '"]');

        if(!row.length){
            if(!focusSearchRetried && contractsTable.rows({page: "current"}).count() > 0){
                focusSearchRetried = true;

                let searchBox = $("#liveContractSearch");
                let currentSearch = searchBox.val().trim();
                let focusTerm = String(focusContractId);
                let terms = currentSearch === ""
                    ? []
                    : currentSearch.split(",").map(function(term){ return term.trim(); }).filter(Boolean);

                if(terms.indexOf(focusTerm) === -1){
                    terms.push(focusTerm);
                    let narrowedSearch = terms.join(", ");
                    searchBox.val(narrowedSearch);
                    contractsTable.search(narrowedSearch).draw();
                    updateActiveFilterBox();
                }
            }

            return;
        }

        let rowData = contractsTable.row(row[0]).data();

        if(!rowData){
            return;
        }

        focusHandled = true;
        row.addClass("contract-focus-row");

        if(row[0] && typeof row[0].scrollIntoView === "function"){
            row[0].scrollIntoView({
                behavior: "smooth",
                block: "center"
            });
        }

        setTimeout(function(){
            row.removeClass("contract-focus-row");
        }, 6500);

        openContractModal(rowData, {
            focusTaskId: focusTaskId
        });

        clearFocusUrlParams();
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

    contractsTable.on("draw", openFocusedContractFromAlert);

    updateActiveFilterBox();
    adjustContractTable();
    openFocusedContractFromAlert();

});
</script>

</body>
</html>
