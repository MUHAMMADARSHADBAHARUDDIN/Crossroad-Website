<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/date_helpers.php";
require_once "../includes/project_dashboard_data.php";
require_once "../includes/bulletin_schema.php";

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

$activeStandbyBulletins = [];
$standaloneBulletinMessages = [];
if(ensureBulletinSchema($mysqli)){
    $today = date('Y-m-d');
    $standbyStmt = $mysqli->prepare('SELECT standby_name, start_date, end_date FROM standby_bulletins WHERE start_date <= ? AND end_date >= ? ORDER BY start_date ASC, id ASC');
    if($standbyStmt){
        $standbyStmt->bind_param('ss', $today, $today);
        $standbyStmt->execute();
        $standbyResult = $standbyStmt->get_result();
        while($standbyResult && $standbyRow = $standbyResult->fetch_assoc()){ $activeStandbyBulletins[] = $standbyRow; }
    }
    $messageResult = $mysqli->query('SELECT message FROM bulletin_messages ORDER BY created_at ASC, id ASC');
    while($messageResult && $messageRow = $messageResult->fetch_assoc()){ $standaloneBulletinMessages[] = $messageRow['message']; }
}

function dashboardEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dashboardTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function dashboardColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);
    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

function dashboardDateRange($startDate, $endDate){
    if(empty($startDate)){
        return "Date not assigned";
    }

    $start = date("d M Y", strtotime($startDate));

    if(empty($endDate) || $startDate === $endDate){
        return $start;
    }

    return $start . " - " . date("d M Y", strtotime($endDate));
}

function dashboardTaskUrgency($startDate, $endDate){
    $todayTs = strtotime(date("Y-m-d"));
    $startTs = !empty($startDate) ? strtotime($startDate) : false;

    if(!$startTs){
        return [
            "label" => "Pending",
            "class" => "warning",
            "icon" => "fa-clock",
            "caption" => "Waiting for schedule"
        ];
    }

    $endTs = !empty($endDate) ? strtotime($endDate) : $startTs;

    if($endTs < $todayTs){
        return [
            "label" => "Overdue",
            "class" => "danger",
            "icon" => "fa-triangle-exclamation",
            "caption" => "Past scheduled date"
        ];
    }

    if($startTs <= $todayTs && $endTs >= $todayTs){
        return [
            "label" => "Due Now",
            "class" => "danger",
            "icon" => "fa-bell",
            "caption" => "Action needed today"
        ];
    }

    $daysToStart = (int)floor(($startTs - $todayTs) / 86400);

    if($daysToStart <= 7){
        return [
            "label" => "Upcoming",
            "class" => "warning",
            "icon" => "fa-calendar-day",
            "caption" => "Starting soon"
        ];
    }

    return [
        "label" => "Scheduled",
        "class" => "info",
        "icon" => "fa-calendar-check",
        "caption" => "Future schedule"
    ];
}

function dashboardDaysText($startDate, $endDate){
    if(empty($startDate)){
        return "No date assigned";
    }

    $todayTs = strtotime(date("Y-m-d"));
    $startTs = strtotime($startDate);
    $endTs = !empty($endDate) ? strtotime($endDate) : $startTs;

    if($endTs < $todayTs){
        $days = (int)floor(($todayTs - $endTs) / 86400);
        return $days <= 1 ? "Overdue by 1 day" : "Overdue by " . $days . " days";
    }

    if($startTs <= $todayTs && $endTs >= $todayTs){
        return "Happening now";
    }

    $days = (int)floor(($startTs - $todayTs) / 86400);

    if($days <= 0){
        return "Starts today";
    }

    return $days === 1 ? "Starts tomorrow" : "Starts in " . $days . " days";
}

$canViewContracts = hasContractViewAccess($mysqli);
$canViewInventory = hasPermission($mysqli, "inventory_view");
$isExportAllowed = hasPermission($mysqli, "inventory_export");
$isReportAllowed = hasPermission($mysqli, "inventory_report");
$isInventoryOutputAllowed = $isExportAllowed || $isReportAllowed;

$totalContracts = 0;
$activeContracts = 0;
$expiringContracts = 0;
$expiredContracts = 0;
$totalDevices = 0;
$servers = 0;
$storage = 0;
$contractReportProjects = [];
$contractReportSuggestions = [
    "project_codes" => [],
    "owners" => []
];

if($canViewContracts){
    $contractStartSql = appSqlDateValue("contract_start");
    $contractEndSql = appSqlDateValue("contract_end");

    $totalContracts = (int)$mysqli->query("SELECT COUNT(*) AS total FROM project_inventory")->fetch_assoc()['total'];
    $activeContracts = (int)$mysqli->query("SELECT COUNT(*) AS total FROM project_inventory WHERE $contractStartSql IS NOT NULL AND $contractEndSql IS NOT NULL AND CURDATE() BETWEEN $contractStartSql AND $contractEndSql")->fetch_assoc()['total'];
    $expiringContracts = (int)$mysqli->query("SELECT COUNT(*) AS total FROM project_inventory WHERE $contractEndSql BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['total'];
    $expiredContracts = (int)$mysqli->query("SELECT COUNT(*) AS total FROM project_inventory WHERE $contractEndSql < CURDATE()")->fetch_assoc()['total'];

    $projectResult = $mysqli->query("
        SELECT no, project_name, contract_no
        FROM project_inventory
        ORDER BY project_name ASC, no DESC
    ");

    if($projectResult){
        while($projectRow = $projectResult->fetch_assoc()){
            $contractReportProjects[] = $projectRow;
        }
    }

    $contractReportSuggestions = projectDashboardFetchSuggestions($mysqli);
}

if($canViewInventory){
    $totalDevices = (int)$mysqli->query("SELECT COUNT(*) AS total FROM asset_inventory")->fetch_assoc()['total'];
    $servers = (int)$mysqli->query("SELECT COUNT(*) AS total FROM server_inventory")->fetch_assoc()['total'];
    $storage = (int)$mysqli->query("SELECT COUNT(*) AS total FROM asset_inventory WHERE description LIKE '%storage%'")->fetch_assoc()['total'];
}

$pmTasks = [];
$pmFeatureReady = dashboardTableExists($mysqli, "contract_tasks")
    && dashboardColumnExists($mysqli, "contract_tasks", "task_start_date")
    && dashboardColumnExists($mysqli, "contract_tasks", "task_end_date")
    && dashboardColumnExists($mysqli, "contract_tasks", "is_completed");

if($pmFeatureReady){
    $taskStartSql = appSqlDateValue("ct.task_start_date");
    $taskEndSql = appSqlDateValue("ct.task_end_date");

    $pmStmt = $mysqli->prepare("
        SELECT
            ct.id,
            ct.task_text,
            ct.task_start_date,
            ct.task_end_date,
            pi.no AS contract_id,
            pi.project_name,
            pi.contract_no,
            pi.project_owner
        FROM contract_tasks ct
        INNER JOIN project_inventory pi ON pi.no = ct.contract_id
        WHERE ct.is_completed = 0
          AND $taskStartSql IS NOT NULL
        ORDER BY $taskStartSql ASC, ct.id ASC
    ");

    if($pmStmt){
        $pmStmt->execute();
        $pmResult = $pmStmt->get_result();

        while($row = $pmResult->fetch_assoc()){
            $pmTasks[] = $row;
        }
    }
}

$pmTaskCount = count($pmTasks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dashboard</title>

<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="style.css">

<style>
:root{
    --pm-black:#171717;
    --pm-yellow:#ffc107;
    --pm-orange:#ff8a00;
    --pm-red:#dc3545;
}

.pm-alert-board{
    position:relative;
    margin-bottom:32px;
    border-radius:28px;
    overflow:hidden;
    background:#fff;
    border:1px solid rgba(255,193,7,.38);
    box-shadow:0 20px 55px rgba(20,20,20,.12);
}

.pm-alert-board::before{
    content:"";
    position:absolute;
    top:0;
    left:0;
    right:0;
    height:6px;
    background:linear-gradient(90deg,#dc3545,#ff8a00,#ffc107,#ff8a00,#dc3545);
    background-size:250% 100%;
    animation:pmWarningFlow 5s linear infinite;
    z-index:3;
}

@keyframes pmWarningFlow{
    0%{ background-position:0% 50%; }
    100%{ background-position:250% 50%; }
}

.pm-alert-header{
    position:relative;
    padding:24px 26px;
    color:#fff;
    background:
        radial-gradient(circle at top right, rgba(255,193,7,.36), transparent 36%),
        linear-gradient(135deg,#141414 0%,#2a1c00 52%,#111 100%);
}

.pm-alert-header-content{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    flex-wrap:wrap;
}

.pm-alert-title-wrap{
    display:flex;
    align-items:center;
    gap:16px;
}

.pm-siren{
    position:relative;
    width:58px;
    height:58px;
    min-width:58px;
    border-radius:18px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#dc3545,#ff8a00);
    box-shadow:0 0 0 7px rgba(255,193,7,.15), 0 12px 30px rgba(220,53,69,.38);
}

.pm-siren::after{
    content:"";
    position:absolute;
    inset:-8px;
    border-radius:24px;
    border:2px solid rgba(255,193,7,.35);
    animation:pmPulse 1.8s ease-out infinite;
}

@keyframes pmPulse{
    0%{ transform:scale(.92); opacity:1; }
    100%{ transform:scale(1.18); opacity:0; }
}

.pm-siren i{
    font-size:25px;
    color:#fff;
    animation:pmShake 1.45s ease-in-out infinite;
}

@keyframes pmShake{
    0%,100%{ transform:rotate(0deg); }
    20%{ transform:rotate(-10deg); }
    40%{ transform:rotate(10deg); }
    60%{ transform:rotate(-6deg); }
    80%{ transform:rotate(6deg); }
}

.pm-alert-eyebrow{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:6px 12px;
    border-radius:999px;
    margin-bottom:8px;
    color:#1a1a1a;
    background:#ffc107;
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.pm-alert-title{
    margin:0;
    font-size:26px;
    font-weight:900;
    letter-spacing:-.03em;
}

.pm-alert-subtitle{
    margin:5px 0 0;
    color:rgba(255,255,255,.78);
    font-size:14px;
}

.pm-alert-summary{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.pm-month-pill,
.pm-count-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    border-radius:999px;
    padding:9px 14px;
    font-weight:800;
    font-size:13px;
    white-space:nowrap;
}

.pm-month-pill{
    background:rgba(255,255,255,.12);
    color:#fff;
    border:1px solid rgba(255,255,255,.18);
}

.pm-count-pill{
    background:#fff;
    color:#dc3545;
    box-shadow:0 10px 28px rgba(0,0,0,.22);
}

.pm-alert-body{
    position:relative;
    background:
        linear-gradient(135deg, rgba(255,248,225,.96), rgba(255,255,255,.96)),
        repeating-linear-gradient(-45deg, rgba(255,193,7,.16) 0, rgba(255,193,7,.16) 12px, transparent 12px, transparent 24px);
}

#pmBulletinCarousel{
    position:relative;
}

.pm-alert-slide{
    padding:26px;
}

.pm-alert-card{
    position:relative;
    overflow:hidden;
    border-radius:24px;
    background:rgba(255,255,255,.94);
    border:1px solid rgba(255,193,7,.45);
    box-shadow:0 16px 42px rgba(34,34,34,.1);
}

.pm-alert-card::before{
    content:"";
    position:absolute;
    top:0;
    bottom:0;
    left:0;
    width:8px;
    background:linear-gradient(180deg,#dc3545,#ff8a00,#ffc107);
}

.pm-clickable-card{
    cursor:pointer;
    transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}

.pm-clickable-card:hover{
    transform:translateY(-3px);
    border-color:rgba(220,53,69,.55);
    box-shadow:0 22px 54px rgba(34,34,34,.16);
}

.pm-clickable-card:focus{
    outline:3px solid rgba(255,193,7,.55);
    outline-offset:3px;
}

.pm-card-inner{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:0;
    min-height:235px;
}

.pm-card-status{
    padding:26px 22px;
    background:linear-gradient(180deg,#1a1a1a,#2b2b2b);
    color:#fff;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}

.pm-status-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    align-self:flex-start;
    padding:9px 13px;
    border-radius:999px;
    font-size:13px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.pm-status-pill.danger{
    background:rgba(220,53,69,.18);
    color:#ffb3bd;
    border:1px solid rgba(220,53,69,.45);
}

.pm-status-pill.warning{
    background:rgba(255,193,7,.18);
    color:#ffe08a;
    border:1px solid rgba(255,193,7,.45);
}

.pm-status-pill.info{
    background:rgba(13,202,240,.15);
    color:#9eeaf9;
    border:1px solid rgba(13,202,240,.38);
}

.pm-status-big{
    margin-top:20px;
}

.pm-status-big strong{
    display:block;
    font-size:34px;
    line-height:1;
    letter-spacing:-.04em;
}

.pm-status-big span{
    display:block;
    margin-top:7px;
    color:rgba(255,255,255,.68);
    font-size:13px;
}

.pm-alert-number{
    color:rgba(255,255,255,.66);
    font-size:13px;
}

.pm-card-content{
    padding:27px 28px 24px;
}

.pm-task-label{
    display:inline-flex;
    align-items:center;
    gap:8px;
    color:#856404;
    background:#fff3cd;
    border:1px solid #ffe69c;
    border-radius:999px;
    padding:7px 12px;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.06em;
}

.pm-task-title{
    margin:14px 0 18px;
    color:#171717;
    font-size:25px;
    line-height:1.25;
    font-weight:900;
    letter-spacing:-.03em;
}

.pm-info-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
}

.pm-info-box{
    min-height:92px;
    padding:14px;
    border-radius:17px;
    background:#f8f9fa;
    border:1px solid #ececec;
}

.pm-info-box i{
    color:#d39e00;
    margin-right:8px;
}

.pm-info-box span{
    display:block;
    color:#6c757d;
    font-size:12px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.05em;
    margin-bottom:8px;
}

.pm-info-box strong{
    display:block;
    color:#222;
    font-size:14px;
    line-height:1.35;
    word-break:break-word;
}

.pm-action-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
    margin-top:20px;
    padding-top:18px;
    border-top:1px solid #eeeeee;
}

.pm-clear-note{
    display:flex;
    align-items:flex-start;
    gap:10px;
    color:#555;
    font-size:13px;
    max-width:620px;
}

.pm-clear-note i{
    color:#198754;
    margin-top:2px;
}

.pm-click-hint{
    display:flex;
    align-items:center;
    gap:8px;
    color:#dc3545;
    font-size:13px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.pm-click-hint i{
    color:#dc3545;
}

/* Modern centered left / right slider buttons */
#pmBulletinCarousel{
    position:relative;
}

.pm-modern-control{
    position:absolute !important;
    top:50% !important;
    bottom:auto !important;
    transform:translateY(-50%) !important;
    width:58px !important;
    height:58px !important;
    opacity:1 !important;
    z-index:50 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    margin:0 !important;
}

.pm-modern-control.carousel-control-prev{
    left:18px !important;
    right:auto !important;
}

.pm-modern-control.carousel-control-next{
    right:18px !important;
    left:auto !important;
}

.standby-bulletin{
    position:fixed;
    left:230px;
    right:0;
    bottom:0;
    z-index:1040;
    min-height:44px;
    padding:10px 18px;
    background:#d60000;
    color:#fff;
    border-top:3px solid #ffda00;
    box-shadow:0 -5px 18px rgba(0,0,0,.2);
    display:flex;
    align-items:center;
    justify-content:flex-start;
    gap:10px;
    font-size:15px;
    font-weight:700;
    line-height:1.35;
    transition:left .3s ease;
    overflow:hidden;
}
.standby-bulletin i{color:#ffda00;flex:0 0 auto;z-index:1}
.standby-bulletin-track{flex:1;min-width:0;overflow:hidden}
.standby-bulletin-runner{
    display:flex;
    width:max-content;
    animation:standbyTicker 22s linear infinite;
    will-change:transform;
}
.standby-bulletin-message{
    display:block;
    flex:0 0 var(--standby-ticker-width, 100vw);
    width:var(--standby-ticker-width, 100vw);
    white-space:nowrap;
}
.standby-bulletin:hover .standby-bulletin-runner{animation-play-state:paused}
@keyframes standbyTicker{
    from{transform:translateX(0)}
    to{transform:translateX(calc(-1 * var(--standby-ticker-width, 100vw)))}
}
.sidebar.collapsed ~ .standby-bulletin{left:70px}
body.dashboard-has-standby{padding-bottom:58px}
@media(max-width:768px){
    .standby-bulletin,.sidebar.collapsed ~ .standby-bulletin{left:0;padding:9px 12px;font-size:13px}
    .standby-bulletin-message{overflow:hidden;text-overflow:ellipsis}
}
@media(prefers-reduced-motion:reduce){
    .standby-bulletin-track{overflow-x:auto;text-align:center}
    .standby-bulletin-runner{display:block;width:100%;animation:none}
    .standby-bulletin-message{white-space:normal;padding-right:0}
    .standby-bulletin-message[aria-hidden="true"]{display:none}
}

.pm-nav-bubble i{
    font-size:18px;
    filter:drop-shadow(0 2px 4px rgba(0,0,0,.35));
}

.pm-modern-control:hover .pm-nav-bubble{
    transform:translateY(-2px) scale(1.05);
    color:#1a1a1a;
    background:linear-gradient(135deg,#ffc107,#ff8a00);
    box-shadow:
        0 18px 42px rgba(255,138,0,.35),
        0 8px 20px rgba(0,0,0,.2);
}

.pm-modern-control:active .pm-nav-bubble{
    transform:scale(.96);
}

.pm-empty-state{
    display:flex;
    align-items:center;
    gap:18px;
    padding:30px 28px;
    background:linear-gradient(135deg,#f2fff6,#ffffff);
}

.pm-empty-icon{
    width:62px;
    height:62px;
    min-width:62px;
    border-radius:20px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#d1e7dd;
    color:#146c43;
    font-size:27px;
    box-shadow:0 12px 30px rgba(25,135,84,.14);
}

.pm-empty-state strong{
    display:block;
    color:#1f5132;
    font-size:18px;
}

.pm-empty-state span{
    display:block;
    color:#667085;
    margin-top:4px;
    font-size:14px;
}

.clickable{
    cursor:pointer;
}

.disabled-card{
    cursor:not-allowed;
}

@media(max-width:992px){
    .pm-card-inner{
        grid-template-columns:1fr;
    }

    .pm-card-status{
        gap:18px;
    }

    .pm-info-grid{
        grid-template-columns:1fr;
    }

    .pm-modern-control{
        width:54px;
        height:54px;
        top:50%;
    }

    .pm-nav-bubble{
        width:46px;
        height:46px;
        border-radius:16px;
    }
}

@media(max-width:576px){
    .pm-alert-header,
    .pm-alert-slide{
        padding:20px;
    }

    .pm-alert-title{
        font-size:22px;
    }

    .pm-task-title{
        font-size:21px;
    }

    .pm-alert-summary{
        justify-content:flex-start;
    }

.pm-modern-control{
    width:46px !important;
    height:46px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
}

.pm-modern-control.carousel-control-prev{
    left:8px !important;
}

.pm-modern-control.carousel-control-next{
    right:8px !important;
}

    .pm-nav-bubble{
        width:38px;
        height:38px;
        border-radius:14px;
    }

    .pm-nav-bubble i{
        font-size:14px;
    }
}
</style>
</head>

<body>
<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">
    <div class="banner mb-4">
        <h2><strong>Crossroad Solutions Operation Management</strong></h2>
        <p>Manage contracts, assets, and tenders in one centralized system.</p>
    </div>

    <section class="pm-alert-board" aria-label="Preventive Management Bulletin">
        <div class="pm-alert-header">
            <div class="pm-alert-header-content">
                <div class="pm-alert-title-wrap">
                    <div class="pm-siren">
                        <i class="fa fa-bell"></i>
                    </div>

                    <div>
                        <div class="pm-alert-eyebrow">
                            <i class="fa fa-triangle-exclamation"></i> Action Alert
                        </div>

                        <h3 class="pm-alert-title">Task Bulletin</h3>

                        <p class="pm-alert-subtitle">
                            Pending dated PM tasks stay here until the checklist is completed.
                        </p>
                    </div>
                </div>

                <div class="pm-alert-summary">
                    <span class="pm-month-pill">
                        <i class="fa fa-calendar-days"></i> All Dated Tasks
                    </span>

                    <span class="pm-count-pill">
                        <i class="fa fa-list-check"></i> <?= $pmTaskCount ?> Pending
                    </span>
                </div>
            </div>
        </div>

        <div class="pm-alert-body">
            <?php if(!empty($pmTasks)): ?>
                <div id="pmBulletinCarousel"
                     class="carousel slide"
                     data-bs-ride="carousel"
                     data-bs-interval="6500"
                     data-bs-touch="true">

                    <div class="carousel-inner">
                        <?php foreach($pmTasks as $index => $task): ?>
                            <?php
                                $urgency = dashboardTaskUrgency($task['task_start_date'], $task['task_end_date']);
                                $daysText = dashboardDaysText($task['task_start_date'], $task['task_end_date']);

                                $searchTarget = trim((string)($task['contract_no'] ?? ''));

                                if($searchTarget === ''){
                                    $searchTarget = trim((string)($task['project_name'] ?? ''));
                                }

                                $taskUrl = 'contracts.php?search=' . urlencode($searchTarget)
                                    . '&focus_contract=' . (int)$task['contract_id']
                                    . '&focus_task=' . (int)$task['id'];
                            ?>

                            <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                <div class="pm-alert-slide">
                                    <div class="pm-alert-card <?= $canViewContracts ? 'pm-clickable-card' : '' ?>"
                                        <?php if($canViewContracts): ?>
                                            role="link"
                                            tabindex="0"
                                            onclick='openPmTask(<?= json_encode($taskUrl) ?>)'
                                            onkeydown='handlePmTaskKey(event, <?= json_encode($taskUrl) ?>)'
                                        <?php endif; ?>
                                    >
                                        <div class="pm-card-inner">
                                            <div class="pm-card-status">
                                                <span class="pm-status-pill <?= dashboardEscape($urgency['class']) ?>">
                                                    <i class="fa <?= dashboardEscape($urgency['icon']) ?>"></i>
                                                    <?= dashboardEscape($urgency['label']) ?>
                                                </span>

                                                <div class="pm-status-big">
                                                    <strong><?= dashboardEscape($daysText) ?></strong>
                                                    <span><?= dashboardEscape($urgency['caption']) ?></span>
                                                </div>

                                                <div class="pm-alert-number">
                                                    PM Alert <?= $index + 1 ?> of <?= $pmTaskCount ?>
                                                </div>
                                            </div>

                                            <div class="pm-card-content">
                                                <span class="pm-task-label">
                                                    <i class="fa fa-screwdriver-wrench"></i>
                                                    Preventive Management Task
                                                </span>

                                                <h4 class="pm-task-title">
                                                    <?= dashboardEscape($task['task_text']) ?>
                                                </h4>

                                                <div class="pm-info-grid">
                                                    <div class="pm-info-box">
                                                        <span>
                                                            <i class="fa fa-building"></i> Project
                                                        </span>

                                                        <strong>
                                                            <?= dashboardEscape($task['project_name'] ?: '-') ?>
                                                        </strong>
                                                    </div>

                                                    <div class="pm-info-box">
                                                        <span>
                                                            <i class="fa fa-file-contract"></i> Contract No
                                                        </span>

                                                        <strong>
                                                            <?= dashboardEscape($task['contract_no'] ?: '-') ?>
                                                        </strong>
                                                    </div>

                                                    <div class="pm-info-box">
                                                        <span>
                                                            <i class="fa fa-clock"></i> PM Schedule
                                                        </span>

                                                        <strong>
                                                            <?= dashboardEscape(dashboardDateRange($task['task_start_date'], $task['task_end_date'])) ?>
                                                        </strong>
                                                    </div>
                                                </div>

                                                <div class="pm-action-row">
                                                    <div class="pm-clear-note">
                                                        <i class="fa fa-circle-check"></i>

                                                        <div>
                                                            After the user ticks/completes this task in the contract checklist,
                                                            this alert will automatically disappear from the dashboard.
                                                        </div>
                                                    </div>

                                                    <?php if($canViewContracts): ?>
                                                        <div class="pm-click-hint">
                                                            <i class="fa fa-hand-pointer"></i>
                                                            Click banner to open this task
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if($pmTaskCount > 1): ?>
                        <button class="carousel-control-prev pm-modern-control"
                                type="button"
                                data-bs-target="#pmBulletinCarousel"
                                data-bs-slide="prev"
                                onclick="event.stopPropagation();">
                            <span class="pm-nav-bubble" aria-hidden="true">
                                <i class="fa fa-chevron-left"></i>
                            </span>
                            <span class="visually-hidden">Previous</span>
                        </button>

                        <button class="carousel-control-next pm-modern-control"
                                type="button"
                                data-bs-target="#pmBulletinCarousel"
                                data-bs-slide="next"
                                onclick="event.stopPropagation();">
                            <span class="pm-nav-bubble" aria-hidden="true">
                                <i class="fa fa-chevron-right"></i>
                            </span>
                            <span class="visually-hidden">Next</span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="pm-empty-state">
                    <div class="pm-empty-icon">
                        <i class="fa fa-circle-check"></i>
                    </div>

                    <div>
                        <strong>No pending dated Preventive Management task.</strong>
                        <span>Dated checklist tasks will appear here automatically until they are completed.</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if($canViewContracts): ?>
        <h4>Contracts Overview</h4>

        <div class="row text-center mb-4">
            <div class="col-lg-3 col-md-6 col-12 mb-3">
                <div class="stat-card clickable" onclick="openContractReportModal()">
                    <h6>Total Contracts</h6>
                    <h2><?= $totalContracts ?></h2>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 col-12 mb-3">
                <div class="stat-card">
                    <h6>Active</h6>
                    <h2 class="text-success"><?= $activeContracts ?></h2>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 col-12 mb-3">
                <div class="stat-card">
                    <h6>Upcoming Expiry</h6>
                    <h2 class="text-info"><?= $expiringContracts ?></h2>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 col-12 mb-3">
                <div class="stat-card">
                    <h6>Expired</h6>
                    <h2 class="text-danger"><?= $expiredContracts ?></h2>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="section-divider"></div>

    <?php if($canViewInventory): ?>
        <h4>Inventory Overview</h4>

        <div class="row text-center mb-4">
            <div class="col-lg-3 col-md-6 col-12 mb-3">
                <div class="stat-card <?= $isInventoryOutputAllowed ? 'clickable' : 'disabled-card' ?>"
                    <?php if($isInventoryOutputAllowed): ?>onclick="openExportModal('asset')"<?php endif; ?>>
                    <h6>Total Parts</h6>
                    <h2><?= $totalDevices ?></h2>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 col-12 mb-3">
                <div class="stat-card <?= $isInventoryOutputAllowed ? 'clickable' : 'disabled-card' ?>"
                    <?php if($isInventoryOutputAllowed): ?>onclick="openExportModal('server')"<?php endif; ?>>
                    <h6>Total Assets</h6>
                    <h2><?= $servers ?></h2>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 col-12 mb-3">
                <div class="stat-card">
                    <h6>Storage Devices</h6>
                    <h2><?= $storage ?></h2>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="exportModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Export Data</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body text-center">
                <p id="exportText" class="mb-3"></p>

                <?php if($isExportAllowed): ?>
                    <button class="btn btn-success w-100 mb-2" onclick="chooseOutputFormat('excel')">
                        <i class="fa fa-file-excel"></i> Excel
                    </button>

                    <button class="btn btn-danger w-100 mb-2" onclick="chooseOutputFormat('pdf')">
                        <i class="fa fa-file-pdf"></i> PDF
                    </button>

                    <button class="btn btn-primary w-100 mb-2" onclick="chooseOutputFormat('print')">
                        <i class="fa fa-print"></i> Print
                    </button>
                <?php endif; ?>

                <?php if($isReportAllowed): ?>
                    <button class="btn btn-dark w-100" onclick="chooseOutputFormat('report')">
                        <i class="fa fa-file-lines"></i> Report
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="stockMovementModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Choose Stock Movement</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body text-center">
                <p id="stockMovementText" class="mb-3"></p>

                <button class="btn btn-outline-success w-100 mb-2" onclick="chooseStockMovement('stock_in')">
                    <i class="fa fa-arrow-down"></i> Stock In
                </button>

                <button class="btn btn-outline-danger w-100 mb-2" onclick="chooseStockMovement('stock_out')">
                    <i class="fa fa-arrow-up"></i> Stock Out
                </button>

                <button class="btn btn-outline-dark w-100" onclick="chooseStockMovement('all')">
                    <i class="fa fa-arrows-up-down"></i> Both Stock In & Stock Out
                </button>
            </div>
        </div>
    </div>
</div>

<?php if($isReportAllowed): ?>
<div class="modal fade" id="reportRangeModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Report Range</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <p id="reportRangeText" class="text-center mb-3"></p>

                <div class="row g-2">
                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateReportByPeriod('today')">Today</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateReportByPeriod('7day')">7 Day</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateReportByPeriod('30day')">30 Day</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateReportByPeriod('monthly')">Monthly</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateReportByPeriod('yearly')">Yearly</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="toggleCustomReportRange()">Custom</button>
                    </div>
                </div>

                <div id="customReportRange" class="d-none mt-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <label for="reportStartDate" class="form-label small mb-1">From</label>
                            <input type="date" id="reportStartDate" class="form-control">
                        </div>

                        <div class="col-6">
                            <label for="reportEndDate" class="form-label small mb-1">To</label>
                            <input type="date" id="reportEndDate" class="form-control">
                        </div>
                    </div>

                    <button class="btn btn-dark w-100 mt-3" onclick="generateCustomReport()">
                        Generate Custom Report
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($canViewContracts): ?>
<div class="modal fade" id="contractReportTypeModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Contract Report</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <button class="btn btn-outline-dark w-100 mb-2" onclick="openContractReportPeriod('all')">
                    <i class="fa fa-file-contract"></i> Report for All Total Contract
                </button>

                <button class="btn btn-outline-success w-100 mb-2" onclick="openContractReportPeriod('active')">
                    <i class="fa fa-circle-check"></i> Report for Active Contract
                </button>

                <button class="btn btn-outline-warning w-100 mb-2" onclick="openContractReportPeriod('pm')">
                    <i class="fa fa-screwdriver-wrench"></i> Report for PM Only Based on Contract
                </button>

                <button class="btn btn-outline-primary w-100 mb-2" onclick="openContractProjectPicker()">
                    <i class="fa fa-diagram-project"></i> Report for Specific Project
                </button>

                <button class="btn btn-outline-secondary w-100" onclick="openContractReportCustomRange('custom_range')">
                    <i class="fa fa-calendar-days"></i> Report Custom by Range Date
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="contractReportProjectModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Choose Project Code or Owner</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label for="contractReportProjectFilterType" class="form-label">View By</label>
                        <select id="contractReportProjectFilterType" class="form-select">
                            <option value="project_code">Project Code</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label for="contractReportProjectFilterValue" class="form-label">Value</label>
                        <input type="text" id="contractReportProjectFilterValue" class="form-control" list="contractReportProjectCodeOptions" autocomplete="off">
                        <datalist id="contractReportProjectCodeOptions">
                            <?php foreach($contractReportSuggestions['project_codes'] as $code): ?>
                                <option value="<?= dashboardEscape($code) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <datalist id="contractReportOwnerOptions">
                            <?php foreach($contractReportSuggestions['owners'] as $owner): ?>
                                <option value="<?= dashboardEscape($owner) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <button class="btn btn-dark w-100 mt-3" onclick="continueContractProjectReport()">
                    Continue
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="contractReportPeriodModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Report Range</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <p id="contractReportPeriodText" class="text-center mb-3"></p>

                <div class="row g-2">
                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateContractReportByPeriod('today')">Today</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateContractReportByPeriod('7day')">7 Day</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateContractReportByPeriod('30day')">30 Day</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateContractReportByPeriod('monthly')">Monthly</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="generateContractReportByPeriod('yearly')">Yearly</button>
                    </div>

                    <div class="col-6">
                        <button class="btn btn-outline-dark w-100" onclick="openContractReportCustomRange(contractReportType)">Custom</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="contractReportCustomRangeModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Custom Date Range</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <p id="contractReportCustomText" class="text-center mb-3"></p>

                <div class="row g-2">
                    <div class="col-6">
                        <label for="contractReportStartDate" class="form-label small mb-1">From</label>
                        <input type="date" id="contractReportStartDate" class="form-control">
                    </div>

                    <div class="col-6">
                        <label for="contractReportEndDate" class="form-label small mb-1">To</label>
                        <input type="date" id="contractReportEndDate" class="form-control">
                    </div>
                </div>

                <button class="btn btn-dark w-100 mt-3" onclick="generateContractCustomReport()">
                    Generate Custom Report
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if(!empty($activeStandbyBulletins) || !empty($standaloneBulletinMessages)): ?>
<div class="standby-bulletin" role="status" aria-label="Current standby bulletin">
    <i class="fa fa-bullhorn" aria-hidden="true"></i>
    <div class="standby-bulletin-track">
        <div class="standby-bulletin-runner">
            <?php for($tickerCopy = 0; $tickerCopy < 2; $tickerCopy++): ?>
            <span class="standby-bulletin-message"<?= $tickerCopy === 1 ? ' aria-hidden="true"' : '' ?>>
            <?php foreach($activeStandbyBulletins as $index => $standby): ?>
                <?php if($index > 0): ?> &nbsp;&bull;&nbsp; <?php endif; ?>
                <?= dashboardEscape($standby['standby_name']) ?> is standby for this week <?= dashboardEscape(date('d/m/Y', strtotime($standby['start_date']))) ?> - <?= dashboardEscape(date('d/m/Y', strtotime($standby['end_date']))) ?>
            <?php endforeach; ?>
            <?php foreach($standaloneBulletinMessages as $messageIndex => $standaloneMessage): ?>
                <?php if(!empty($activeStandbyBulletins) || $messageIndex > 0): ?> &nbsp;&bull;&nbsp; <?php endif; ?>
                <?= dashboardEscape($standaloneMessage) ?>
            <?php endforeach; ?>
            </span>
            <?php endfor; ?>
        </div>
    </div>
</div>
<script>document.body.classList.add('dashboard-has-standby');</script>
<script>
(function(){
    const track = document.querySelector('.standby-bulletin-track');
    const runner = document.querySelector('.standby-bulletin-runner');
    if(!track || !runner){ return; }

    function setTickerWidth(){
        runner.style.setProperty('--standby-ticker-width', track.clientWidth + 'px');
    }

    setTickerWidth();
    if(window.ResizeObserver){
        new ResizeObserver(setTickerWidth).observe(track);
    }else{
        window.addEventListener('resize', setTickerWidth);
    }
})();
</script>
<?php endif; ?>

<?php include "layout/footer.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar(){
    const sidebar = document.getElementById("sidebar");
    const main = document.getElementById("main");
    const btn = document.querySelector(".menu-btn");

    sidebar.classList.toggle("collapsed");
    main.classList.toggle("expanded");
    btn.classList.toggle("active");
}

function openPmTask(url){
    if(url){
        window.location.href = url;
    }
}

function handlePmTaskKey(event, url){
    if(event.key === "Enter" || event.key === " "){
        event.preventDefault();
        openPmTask(url);
    }
}

let isContractReportAllowed = <?= json_encode($canViewContracts) ?>;
let contractReportType = "";
let contractReportProjectId = "";
let contractReportProjectFilterType = "project_code";
let contractReportProjectFilterValue = "";

const contractReportLabels = {
    all: "All Total Contract",
    active: "Active Contract",
    pm: "PM Only Based on Contract",
    project: "Specific Project / Owner",
    custom_range: "Custom Range Date"
};

function showDashboardModal(id){
    let element = document.getElementById(id);

    if(element){
        bootstrap.Modal.getOrCreateInstance(element).show();
    }
}

function hideDashboardModal(id){
    let element = document.getElementById(id);

    if(!element){
        return;
    }

    let modal = bootstrap.Modal.getInstance(element);

    if(modal){
        modal.hide();
    }
}

function closeContractReportModals(){
    [
        "contractReportTypeModal",
        "contractReportProjectModal",
        "contractReportPeriodModal",
        "contractReportCustomRangeModal"
    ].forEach(hideDashboardModal);
}

function openContractReportModal(){
    if(!isContractReportAllowed){
        return;
    }

    contractReportType = "";
    contractReportProjectId = "";
    contractReportProjectFilterType = "project_code";
    contractReportProjectFilterValue = "";
    showDashboardModal("contractReportTypeModal");
}

function openContractReportPeriod(type){
    if(!isContractReportAllowed){
        return;
    }

    contractReportType = type;

    if(type !== "project"){
        contractReportProjectId = "";
        contractReportProjectFilterType = "project_code";
        contractReportProjectFilterValue = "";
    }

    let label = contractReportLabels[type] || "Contract";
    let text = document.getElementById("contractReportPeriodText");

    if(text){
        text.innerText = "Generate " + label + " report";
    }

    hideDashboardModal("contractReportTypeModal");
    hideDashboardModal("contractReportProjectModal");
    showDashboardModal("contractReportPeriodModal");
}

function openContractProjectPicker(){
    if(!isContractReportAllowed){
        return;
    }

    contractReportType = "project";
    contractReportProjectId = "";
    contractReportProjectFilterType = "project_code";
    contractReportProjectFilterValue = "";

    let typeSelect = document.getElementById("contractReportProjectFilterType");
    let valueInput = document.getElementById("contractReportProjectFilterValue");

    if(typeSelect){
        typeSelect.value = "project_code";
    }

    if(valueInput){
        valueInput.value = "";
    }

    syncContractReportProjectDatalist();
    hideDashboardModal("contractReportTypeModal");
    showDashboardModal("contractReportProjectModal");
}

function continueContractProjectReport(){
    let typeSelect = document.getElementById("contractReportProjectFilterType");
    let valueInput = document.getElementById("contractReportProjectFilterValue");
    let filterValue = valueInput ? valueInput.value.trim() : "";

    if(filterValue === ""){
        alert("Please enter a project code or owner.");
        return;
    }

    contractReportProjectId = "";
    contractReportProjectFilterType = typeSelect && typeSelect.value === "owner" ? "owner" : "project_code";
    contractReportProjectFilterValue = filterValue;
    openContractReportPeriod("project");
}

function openContractReportCustomRange(type){
    if(!isContractReportAllowed){
        return;
    }

    contractReportType = type || contractReportType || "custom_range";

    if(contractReportType !== "project"){
        contractReportProjectId = "";
        contractReportProjectFilterType = "project_code";
        contractReportProjectFilterValue = "";
    }

    let label = contractReportLabels[contractReportType] || "Contract";
    let text = document.getElementById("contractReportCustomText");
    let startDate = document.getElementById("contractReportStartDate");
    let endDate = document.getElementById("contractReportEndDate");

    if(text){
        text.innerText = "Generate " + label + " report by custom date range";
    }

    if(startDate){
        startDate.value = "";
    }

    if(endDate){
        endDate.value = "";
    }

    hideDashboardModal("contractReportTypeModal");
    hideDashboardModal("contractReportProjectModal");
    hideDashboardModal("contractReportPeriodModal");
    showDashboardModal("contractReportCustomRangeModal");

    if(startDate){
        setTimeout(function(){
            startDate.focus();
        }, 250);
    }
}

function buildContractReportUrl(period, startDate = "", endDate = ""){
    let params = new URLSearchParams();

    params.set("report_type", contractReportType || "all");
    params.set("period", period);

    if(contractReportProjectId !== ""){
        params.set("project_id", contractReportProjectId);
    }

    if(contractReportType === "project" && contractReportProjectFilterValue !== ""){
        params.set("project_filter_type", contractReportProjectFilterType);
        params.set("project_filter_value", contractReportProjectFilterValue);
    }

    if(period === "custom"){
        params.set("start_date", startDate);
        params.set("end_date", endDate);
    }

    return "../backend/generate_contract_report.php?" + params.toString();
}

function syncContractReportProjectDatalist(){
    let typeSelect = document.getElementById("contractReportProjectFilterType");
    let valueInput = document.getElementById("contractReportProjectFilterValue");

    if(!typeSelect || !valueInput){
        return;
    }

    valueInput.setAttribute("list", typeSelect.value === "owner" ? "contractReportOwnerOptions" : "contractReportProjectCodeOptions");
}

let contractReportProjectTypeSelect = document.getElementById("contractReportProjectFilterType");
if(contractReportProjectTypeSelect){
    contractReportProjectTypeSelect.addEventListener("change", function(){
        let valueInput = document.getElementById("contractReportProjectFilterValue");

        if(valueInput){
            valueInput.value = "";
        }

        syncContractReportProjectDatalist();
    });
    syncContractReportProjectDatalist();
}

function generateContractReportByPeriod(period){
    if(!isContractReportAllowed || contractReportType === ""){
        return;
    }

    if(period === "custom"){
        openContractReportCustomRange(contractReportType);
        return;
    }

    window.open(buildContractReportUrl(period), "_blank");
    closeContractReportModals();
}

function generateContractCustomReport(){
    let startDate = document.getElementById("contractReportStartDate").value;
    let endDate = document.getElementById("contractReportEndDate").value;

    if(!startDate || !endDate || endDate < startDate){
        alert("Please enter a valid date range.");
        return;
    }

    window.open(buildContractReportUrl("custom", startDate, endDate), "_blank");
    closeContractReportModals();
}

let exportType = "";
let exportFormat = "";
let exportMovement = "";
let isExportAllowed = <?= json_encode($isExportAllowed) ?>;
let isReportAllowed = <?= json_encode($isReportAllowed) ?>;

function openExportModal(type){
    if(!isExportAllowed && !isReportAllowed){
        return;
    }

    exportType = type;
    exportFormat = "";
    exportMovement = "";

    document.getElementById("exportText").innerText = type === "asset"
        ? "Choose output for asset stock movement"
        : "Choose output for server stock movement";

    new bootstrap.Modal(document.getElementById('exportModal')).show();
}

function chooseOutputFormat(format){
    if(format === "report"){
        if(!isReportAllowed){
            return;
        }
    }else if(!isExportAllowed){
        return;
    }

    exportFormat = format;
    exportMovement = "";

    document.getElementById("stockMovementText").innerText = (exportType === "asset" ? "Asset" : "Server")
        + " " + format.toUpperCase()
        + ": choose Stock In, Stock Out, or Both.";

    let exportModalElement = document.getElementById("exportModal");
    let movementModalElement = document.getElementById("stockMovementModal");
    let exportModal = bootstrap.Modal.getInstance(exportModalElement);
    let showMovementModal = function(){
        bootstrap.Modal.getOrCreateInstance(movementModalElement).show();
    };

    if(exportModal){
        exportModalElement.addEventListener("hidden.bs.modal", showMovementModal, { once: true });
        exportModal.hide();
    }else{
        showMovementModal();
    }
}

function chooseStockMovement(movement){
    exportMovement = movement;

    if(exportFormat === "report"){
        generateReport();
        return;
    }

    exportData();
}

function exportData(){
    if(exportType === "" || exportFormat === "" || exportMovement === ""){
        return;
    }

    let url = exportType === "asset"
        ? "../backend/export_assets.php?format=" + encodeURIComponent(exportFormat)
        : "../backend/export_servers.php?format=" + encodeURIComponent(exportFormat);

    url += "&movement=" + encodeURIComponent(exportMovement);

    let movementModal = bootstrap.Modal.getInstance(document.getElementById("stockMovementModal"));

    if(movementModal){
        movementModal.hide();
    }

    if(exportFormat === "pdf" || exportFormat === "print"){
        window.open(url, "_blank");
    }else{
        window.location.href = url;
    }
}

function generateReport(){
    if(!isReportAllowed || exportType === "" || exportMovement === ""){
        return;
    }

    let typeLabel = exportType === "asset" ? "ASSET" : "SERVER";
    let movementLabel = "STOCK IN & STOCK OUT";

    if(exportMovement === "stock_in"){
        movementLabel = "STOCK IN";
    }
    else if(exportMovement === "stock_out"){
        movementLabel = "STOCK OUT";
    }

    document.getElementById("reportRangeText").innerText = "Generate " + typeLabel + " " + movementLabel + " report";

    resetCustomReportRange();

    let movementModalElement = document.getElementById("stockMovementModal");
    let reportRangeModalElement = document.getElementById("reportRangeModal");
    let movementModal = bootstrap.Modal.getInstance(movementModalElement);
    let showReportRangeModal = function(){
        bootstrap.Modal.getOrCreateInstance(reportRangeModalElement).show();
    };

    if(movementModal){
        movementModalElement.addEventListener("hidden.bs.modal", showReportRangeModal, { once: true });
        movementModal.hide();
    }else{
        showReportRangeModal();
    }
}

function buildReportUrl(period, startDate = "", endDate = ""){
    let url = "../backend/generate_inventory_report.php?type="
        + encodeURIComponent(exportType)
        + "&period="
        + encodeURIComponent(period)
        + "&movement="
        + encodeURIComponent(exportMovement);

    if(period === "custom"){
        url += "&start_date=" + encodeURIComponent(startDate)
            + "&end_date=" + encodeURIComponent(endDate);
    }

    return url;
}

function closeReportRangeModal(){
    let reportModal = bootstrap.Modal.getInstance(document.getElementById("reportRangeModal"));

    if(reportModal){
        reportModal.hide();
    }
}

function generateReportByPeriod(period){
    if(!isReportAllowed || exportType === ""){
        return;
    }

    window.open(buildReportUrl(period), "_blank");
    closeReportRangeModal();
}

function resetCustomReportRange(){
    let customRange = document.getElementById("customReportRange");
    let startDate = document.getElementById("reportStartDate");
    let endDate = document.getElementById("reportEndDate");

    if(customRange){
        customRange.classList.add("d-none");
    }

    if(startDate){
        startDate.value = "";
    }

    if(endDate){
        endDate.value = "";
    }
}

function toggleCustomReportRange(){
    let customRange = document.getElementById("customReportRange");

    if(!customRange){
        return;
    }

    customRange.classList.toggle("d-none");

    if(!customRange.classList.contains("d-none")){
        document.getElementById("reportStartDate").focus();
    }
}

function generateCustomReport(){
    let startDate = document.getElementById("reportStartDate").value;
    let endDate = document.getElementById("reportEndDate").value;

    if(!startDate || !endDate || endDate < startDate){
        alert("Please enter a valid date range.");
        return;
    }

    window.open(buildReportUrl("custom", startDate, endDate), "_blank");
    closeReportRangeModal();
}
</script>
</body>
</html>
