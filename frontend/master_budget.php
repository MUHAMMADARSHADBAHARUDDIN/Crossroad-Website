<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/project_dashboard_data.php";

if(!hasPermission($mysqli, "contracts_view")){
    die("Access denied");
}

$canViewClaim = hasContractClaimViewAccess($mysqli);
$filterType = projectDashboardNormalizeFilterType($_GET['filter_type'] ?? "project_code");
$filterValue = trim((string)($_GET['filter_value'] ?? ""));
$suggestions = projectDashboardFetchSuggestions($mysqli);
$contracts = $filterValue !== "" ? projectDashboardFetchContracts($mysqli, $filterType, $filterValue) : [];
$dashboardData = projectDashboardBuildData($contracts);
$summary = $dashboardData['summary'];
$filterLabel = $filterType === "owner" ? "Owner" : "Project Code";

$timelineRows = [];
$minTs = null;
$maxTs = null;

foreach($dashboardData['timeline'] as $row){
    $startTs = strtotime((string)($row['start'] ?? ""));
    $endTs = strtotime((string)($row['end'] ?? ""));

    if($startTs === false && $endTs !== false){
        $startTs = $endTs;
    }

    if($endTs === false && $startTs !== false){
        $endTs = $startTs;
    }

    if($startTs !== false && $endTs !== false && $endTs < $startTs){
        $tempTs = $startTs;
        $startTs = $endTs;
        $endTs = $tempTs;
    }

    if($startTs !== false && $endTs !== false){
        $minTs = $minTs === null ? $startTs : min($minTs, $startTs);
        $maxTs = $maxTs === null ? $endTs : max($maxTs, $endTs);
    }

    $row['start_ts'] = $startTs;
    $row['end_ts'] = $endTs;
    $timelineRows[] = $row;
}

$timelineRange = ($minTs !== null && $maxTs !== null && $maxTs > $minTs) ? ($maxTs - $minTs) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Master Budget</title>
<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
.budget-shell{
    max-width:1320px;
    margin:0 auto;
}
.budget-toolbar,
.budget-panel,
.budget-kpi{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    box-shadow:0 8px 22px rgba(15,23,42,0.06);
}
.budget-toolbar{
    padding:16px;
}
.budget-panel{
    padding:18px;
}
.budget-kpi{
    min-height:132px;
    padding:18px;
}
.budget-kpi .label{
    color:#64748b;
    font-size:13px;
    font-weight:800;
    text-transform:uppercase;
}
.budget-kpi .value{
    color:#111827;
    font-size:25px;
    font-weight:850;
    margin-top:8px;
    overflow-wrap:anywhere;
}
.budget-progress{
    height:12px;
    border-radius:999px;
}
.budget-panel canvas{
    max-height:320px;
}
.timeline-list{
    display:flex;
    flex-direction:column;
    gap:12px;
}
.timeline-item{
    padding:16px;
    border:1px solid #e5e7eb;
    border-radius:12px;
    background:#fff;
}
.timeline-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:12px;
}
.timeline-code{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:#111827;
    color:#fff;
    border-radius:999px;
    padding:7px 12px;
    font-size:13px;
    font-weight:800;
    overflow-wrap:anywhere;
}
.timeline-meta{
    color:#64748b;
    font-size:12px;
}
.timeline-stats{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end;
}
.timeline-stat{
    border:1px solid #e5e7eb;
    border-radius:8px;
    padding:7px 10px;
    background:#f8fafc;
    font-size:12px;
    font-weight:800;
}
.timeline-track{
    position:relative;
    height:34px;
    background:linear-gradient(90deg,#f1f5f9,#e5e7eb);
    border-radius:999px;
    overflow:hidden;
    border:1px solid #dbe3ef;
}
.timeline-bar{
    position:absolute;
    top:6px;
    height:20px;
    border-radius:999px;
    background:#111827;
    min-width:18px;
    box-shadow:0 6px 14px rgba(0,0,0,0.12);
}
.timeline-bar.active{
    background:#198754;
}
.timeline-bar.expiring{
    background:#ffc107;
}
.timeline-bar.closed{
    background:#dc3545;
}
@media(max-width:900px){
    .timeline-stats{
        justify-content:flex-start;
    }
}
</style>
</head>
<body>
<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">
<div class="budget-shell">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h2 class="mb-1"><i class="fa fa-wallet"></i> Master Budget</h2>
            <div class="text-muted"><?= projectDashboardEscape($filterLabel) ?>: <?= projectDashboardEscape($filterValue !== "" ? $filterValue : "Select a filter") ?></div>
        </div>
        <a href="project_tracker.php" class="btn btn-outline-dark">
            <i class="fa fa-chart-line"></i> Project Tracker
        </a>
    </div>

    <form class="budget-toolbar mb-4" method="GET">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="budgetFilterType">View By</label>
                <select id="budgetFilterType" name="filter_type" class="form-select">
                    <option value="project_code" <?= $filterType === "project_code" ? "selected" : "" ?>>Project Code</option>
                    <option value="owner" <?= $filterType === "owner" ? "selected" : "" ?>>Owner</option>
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label small mb-1" for="budgetFilterValue">Value</label>
                <input type="text" id="budgetFilterValue" name="filter_value" class="form-control" list="budgetProjectCodeOptions" value="<?= projectDashboardEscape($filterValue) ?>" autocomplete="off" required>
                <datalist id="budgetProjectCodeOptions">
                    <?php foreach($suggestions['project_codes'] as $code): ?>
                        <option value="<?= projectDashboardEscape($code) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="budgetOwnerOptions">
                    <?php foreach($suggestions['owners'] as $owner): ?>
                        <option value="<?= projectDashboardEscape($owner) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-warning fw-semibold">
                    <i class="fa fa-gauge-high"></i> View
                </button>
            </div>
        </div>
    </form>

    <?php if($filterValue === ""): ?>
        <div class="alert alert-info">Enter a project code or owner to view budget data and timeline.</div>
    <?php elseif(empty($contracts)): ?>
        <div class="alert alert-warning">No budget data found for this <?= projectDashboardEscape(strtolower($filterLabel)) ?>.</div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="<?= $canViewClaim ? 'col-md-3' : 'col-md-12' ?>"><div class="budget-kpi"><div class="label">Contract Amount</div><div class="value text-danger"><?= projectDashboardEscape(projectDashboardMoney($summary['amount'])) ?></div></div></div>
            <?php if($canViewClaim): ?>
            <div class="col-md-3"><div class="budget-kpi"><div class="label">Claim Amount</div><div class="value text-warning"><?= projectDashboardEscape(projectDashboardMoney($summary['claimed'])) ?></div></div></div>
            <div class="col-md-3"><div class="budget-kpi"><div class="label">Leftover</div><div class="value text-success"><?= projectDashboardEscape(projectDashboardMoney($summary['leftover'])) ?></div></div></div>
            <div class="col-md-3">
                <div class="budget-kpi">
                    <div class="label">Claim Progress</div>
                    <div class="value"><?= (int)$summary['progress'] ?>%</div>
                    <div class="progress budget-progress">
                        <div class="progress-bar bg-success" style="width:<?= (int)$summary['progress'] ?>%"></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="row g-4 mb-4">
            <?php if($canViewClaim): ?>
            <div class="col-lg-6">
                <div class="budget-panel">
                    <h5>Budget Split</h5>
                    <canvas id="budgetSplitChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            <div class="<?= $canViewClaim ? 'col-lg-6' : 'col-lg-12' ?>">
                <div class="budget-panel">
                    <h5>Contract Status</h5>
                    <canvas id="budgetStatusChart"></canvas>
                </div>
            </div>
        </div>

        <div class="budget-panel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0">Budget Timeline</h5>
                <?php if($minTs !== null && $maxTs !== null): ?>
                    <span class="text-muted small"><?= projectDashboardEscape(date("d M Y", $minTs)) ?> - <?= projectDashboardEscape(date("d M Y", $maxTs)) ?></span>
                <?php endif; ?>
            </div>

            <div class="timeline-list">
                <?php foreach($timelineRows as $row): ?>
                    <?php
                    $startTs = $row['start_ts'];
                    $endTs = $row['end_ts'];
                    $offset = 0;
                    $width = 0;

                    if($startTs !== false && $endTs !== false && $minTs !== null){
                        $offset = max(0, min(96, round((($startTs - $minTs) / $timelineRange) * 100, 2)));
                        $width = max(4, min(100 - $offset, round(((max(86400, $endTs - $startTs)) / $timelineRange) * 100, 2)));
                    }

                    $statusClass = "active";
                    if(($row['status'] ?? "") === "Closed"){
                        $statusClass = "closed";
                    } elseif(($row['status'] ?? "") === "Expiring Soon"){
                        $statusClass = "expiring";
                    }
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-head">
                            <div>
                                <div class="timeline-code">
                                    <i class="fa fa-diagram-project"></i>
                                    <?= projectDashboardEscape($row['project_code'] ?? "") ?>
                                </div>
                                <div class="timeline-meta mt-2">
                                    <?= projectDashboardEscape($row['contract_no'] ?? "") ?> | <?= projectDashboardEscape($row['status'] ?? "") ?>
                                </div>
                            </div>
                            <div class="timeline-stats">
                                <div class="timeline-stat text-danger">Amount <?= projectDashboardEscape(projectDashboardMoney($row['amount'] ?? 0)) ?></div>
                                <?php if($canViewClaim): ?>
                                <div class="timeline-stat text-warning">Claim <?= projectDashboardEscape(projectDashboardMoney($row['claimed'] ?? 0)) ?></div>
                                <div class="timeline-stat text-success">Left <?= projectDashboardEscape(projectDashboardMoney($row['leftover'] ?? 0)) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if($startTs !== false && $endTs !== false): ?>
                            <div>
                                <div class="timeline-track">
                                    <div class="timeline-bar <?= projectDashboardEscape($statusClass) ?>" style="left:<?= $offset ?>%; width:<?= $width ?>%;"></div>
                                </div>
                                <div class="timeline-meta mt-2"><?= projectDashboardEscape(projectDashboardDate($row['start'] ?? "")) ?> - <?= projectDashboardEscape(projectDashboardDate($row['end'] ?? "")) ?></div>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small">No timeline date assigned</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</div>

<?php include "layout/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const budgetFilterType = document.getElementById("budgetFilterType");
const budgetFilterValue = document.getElementById("budgetFilterValue");

function syncBudgetDatalist(){
    if(!budgetFilterType || !budgetFilterValue){
        return;
    }

    budgetFilterValue.setAttribute("list", budgetFilterType.value === "owner" ? "budgetOwnerOptions" : "budgetProjectCodeOptions");
}

if(budgetFilterType){
    budgetFilterType.addEventListener("change", function(){
        budgetFilterValue.value = "";
        syncBudgetDatalist();
    });
    syncBudgetDatalist();
}

<?php if(!empty($contracts)): ?>
<?php if($canViewClaim): ?>
new Chart(document.getElementById("budgetSplitChart"), {
    type:"doughnut",
    data:{
        labels:["Claim Amount","Leftover"],
        datasets:[{
            data:[<?= json_encode((float)$summary['claimed']) ?>, <?= json_encode((float)$summary['leftover']) ?>],
            backgroundColor:["#ffc107","#198754"],
            borderColor:"#fff",
            borderWidth:2
        }]
    },
    options:{ animation:false, plugins:{ legend:{ position:"bottom" } } }
});
<?php endif; ?>

new Chart(document.getElementById("budgetStatusChart"), {
    type:"doughnut",
    data:{
        labels:<?= json_encode(array_keys($dashboardData['status_counts'])) ?>,
        datasets:[{
            data:<?= json_encode(array_values($dashboardData['status_counts'])) ?>,
            backgroundColor:["#198754","#ffc107","#dc3545"],
            borderColor:"#fff",
            borderWidth:2
        }]
    },
    options:{ animation:false, plugins:{ legend:{ position:"bottom" } } }
});
<?php endif; ?>
</script>
</body>
</html>
