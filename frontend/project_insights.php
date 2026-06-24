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
$titleLabel = $filterType === "owner" ? "Owner" : "Project Code";
$displayValue = $filterValue !== "" ? $filterValue : "Select a filter";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Insights</title>
<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
.insight-shell{
    max-width:1280px;
    margin:0 auto;
}
.insight-toolbar{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:16px;
    box-shadow:0 6px 18px rgba(15,23,42,0.06);
}
.insight-kpi{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:18px;
    min-height:128px;
    box-shadow:0 6px 18px rgba(15,23,42,0.06);
}
.insight-kpi .label{
    color:#64748b;
    font-size:13px;
    font-weight:700;
    text-transform:uppercase;
}
.insight-kpi .value{
    font-size:26px;
    font-weight:800;
    color:#111827;
    margin-top:8px;
    overflow-wrap:anywhere;
}
.chart-panel,
.table-panel{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:18px;
    box-shadow:0 6px 18px rgba(15,23,42,0.06);
}
.chart-panel canvas{
    max-height:320px;
}
.contracts-table-wrap{
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
}
.contracts-table-wrap table{
    table-layout:auto;
    width:100%;
}
.contracts-table-wrap th,
.contracts-table-wrap td{
    white-space:normal;
    overflow-wrap:anywhere;
    word-break:break-word;
}
.progress-slim{
    height:10px;
    border-radius:999px;
}
@media(max-width:768px){
    .insight-kpi .value{
        font-size:22px;
    }

    .contracts-table-wrap table{
        min-width:920px;
    }

    .contracts-table-wrap th{
        white-space:nowrap;
    }

    .contracts-table-wrap td{
        white-space:normal;
        max-width:320px;
        word-break:break-word;
        overflow-wrap:anywhere;
    }

    .contracts-table-wrap td:nth-child(5),
    .contracts-table-wrap td:nth-child(6),
    .contracts-table-wrap td:nth-child(7),
    .contracts-table-wrap td:nth-child(8){
        white-space:nowrap;
        max-width:none;
        word-break:normal;
        overflow-wrap:normal;
    }
}
</style>
</head>
<body>
<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">
<div class="insight-shell">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h2 class="mb-1"><i class="fa fa-chart-pie"></i> Project Insights</h2>
            <div class="text-muted"><?= projectDashboardEscape($titleLabel) ?>: <?= projectDashboardEscape($displayValue) ?></div>
        </div>
        <a href="project_tracker.php" class="btn btn-outline-dark">
            <i class="fa fa-arrow-left"></i> Project Tracker
        </a>
    </div>

    <form class="insight-toolbar mb-4" method="GET">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="filterType">View By</label>
                <select class="form-select" id="filterType" name="filter_type">
                    <option value="project_code" <?= $filterType === "project_code" ? "selected" : "" ?>>Project Code</option>
                    <option value="owner" <?= $filterType === "owner" ? "selected" : "" ?>>Owner</option>
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label small mb-1" for="filterValue">Value</label>
                <input type="text" class="form-control" id="filterValue" name="filter_value" list="projectCodeOptions" value="<?= projectDashboardEscape($filterValue) ?>" autocomplete="off" required>
                <datalist id="projectCodeOptions">
                    <?php foreach($suggestions['project_codes'] as $code): ?>
                        <option value="<?= projectDashboardEscape($code) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="ownerOptions">
                    <?php foreach($suggestions['owners'] as $owner): ?>
                        <option value="<?= projectDashboardEscape($owner) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-warning fw-semibold">
                    <i class="fa fa-magnifying-glass"></i> View
                </button>
            </div>
        </div>
    </form>

    <?php if($filterValue === ""): ?>
        <div class="alert alert-info">Choose a project code or owner to view the dashboard.</div>
    <?php elseif(empty($contracts)): ?>
        <div class="alert alert-warning">No contracts found for this <?= projectDashboardEscape(strtolower($titleLabel)) ?>.</div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="<?= $canViewClaim ? 'col-md-3' : 'col-md-6' ?>"><div class="insight-kpi"><div class="label">Contracts</div><div class="value"><?= (int)$summary['total_contracts'] ?></div></div></div>
            <div class="<?= $canViewClaim ? 'col-md-3' : 'col-md-6' ?>"><div class="insight-kpi"><div class="label">Contract Amount</div><div class="value text-danger"><?= projectDashboardEscape(projectDashboardMoney($summary['amount'])) ?></div></div></div>
            <?php if($canViewClaim): ?>
                <div class="col-md-3"><div class="insight-kpi"><div class="label">Claim Amount</div><div class="value text-warning"><?= projectDashboardEscape(projectDashboardMoney($summary['claimed'])) ?></div></div></div>
                <div class="col-md-3"><div class="insight-kpi"><div class="label">Leftover</div><div class="value text-success"><?= projectDashboardEscape(projectDashboardMoney($summary['leftover'])) ?></div></div></div>
            <?php endif; ?>
        </div>

        <div class="row g-4 mb-4">
            <div class="<?= $canViewClaim ? 'col-lg-6' : 'col-lg-12' ?>">
                <div class="chart-panel">
                    <h5>Status Distribution</h5>
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <?php if($canViewClaim): ?>
            <div class="col-lg-6">
                <div class="chart-panel">
                    <h5>Claim Progress</h5>
                    <div class="display-5 fw-bold mb-2"><?= (int)$summary['progress'] ?>%</div>
                    <div class="progress progress-slim mb-3">
                        <div class="progress-bar bg-success" style="width:<?= (int)$summary['progress'] ?>%"></div>
                    </div>
                    <div class="small text-muted">Claimed <?= projectDashboardEscape(projectDashboardMoney($summary['claimed'])) ?> from <?= projectDashboardEscape(projectDashboardMoney($summary['amount'])) ?>.</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="table-panel">
            <h5>Contracts</h5>
            <div class="contracts-table-wrap">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Project Code</th>
                            <th>Project</th>
                            <th>Owner</th>
                            <th>Contract No</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <?php if($canViewClaim): ?>
                            <th>Claim</th>
                            <th>Leftover</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($contracts as $contract): ?>
                            <tr>
                                <td><?= projectDashboardEscape($contract['display_project_code'] ?? "") ?></td>
                                <td><?= projectDashboardEscape($contract['project_name'] ?? "") ?></td>
                                <td><?= projectDashboardEscape($contract['project_owner'] ?? "") ?></td>
                                <td><?= projectDashboardEscape($contract['contract_no'] ?? "") ?></td>
                                <td><?= projectDashboardEscape($contract['auto_status'] ?? "") ?></td>
                                <td class="text-danger fw-semibold"><?= projectDashboardEscape(projectDashboardMoney($contract['amount_numeric'] ?? 0)) ?></td>
                                <?php if($canViewClaim): ?>
                                <td class="text-warning fw-semibold"><?= projectDashboardEscape(projectDashboardMoney($contract['claim_total_numeric'] ?? 0)) ?></td>
                                <td class="text-success fw-semibold"><?= projectDashboardEscape(projectDashboardMoney($contract['leftover_numeric'] ?? 0)) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
</div>

<?php include "layout/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const filterType = document.getElementById("filterType");
const filterValue = document.getElementById("filterValue");

function syncFilterDatalist(){
    if(!filterType || !filterValue){
        return;
    }

    filterValue.setAttribute("list", filterType.value === "owner" ? "ownerOptions" : "projectCodeOptions");
}

if(filterType){
    filterType.addEventListener("change", function(){
        filterValue.value = "";
        syncFilterDatalist();
    });
    syncFilterDatalist();
}

<?php if(!empty($contracts)): ?>
new Chart(document.getElementById("statusChart"), {
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
