<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/date_helpers.php";
require_once "../includes/project_dashboard_data.php";

if(!hasPermission($mysqli, "contracts_view")){
    die("Access denied");
}

$faviconVersion = file_exists("../image/logo.png") ? filemtime("../image/logo.png") : time();
$projectTrackerSuggestions = projectDashboardFetchSuggestions($mysqli);

$total = $mysqli->query("
    SELECT COUNT(*) as total
    FROM project_inventory
")->fetch_assoc()['total'];

$contractStartSql = appSqlDateValue("contract_start");
$contractEndSql = appSqlDateValue("contract_end");

$activeContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE $contractStartSql IS NOT NULL
  AND $contractEndSql IS NOT NULL
  AND CURDATE() BETWEEN $contractStartSql AND $contractEndSql
")->fetch_assoc()['total'];

$expiringContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE $contractEndSql BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetch_assoc()['total'];

$expiredContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE $contractEndSql < CURDATE()
")->fetch_assoc()['total'];

$totalAmount = 0;
$result = $mysqli->query("SELECT amount FROM project_inventory");

while($row = $result->fetch_assoc()){
    $amount = is_numeric($row['amount']) ? floatval($row['amount']) : 0;
    $totalAmount += $amount;
}

$yearAmountData = [];

$yearAmountResult = $mysqli->query("
    SELECT year_awarded, SUM(CAST(amount AS DECIMAL(15,2))) as total
    FROM project_inventory
    GROUP BY year_awarded
    ORDER BY year_awarded ASC
");

while($row = $yearAmountResult->fetch_assoc()){
    $year = !empty($row['year_awarded']) ? $row['year_awarded'] : "Unknown";
    $yearAmountData[$year] = floatval($row['total']);
}

$bestYear = null;
$bestValue = 0;

$years = array_keys($yearAmountData);
$values = array_values($yearAmountData);

foreach($yearAmountData as $year => $amount){
    if($amount > $bestValue){
        $bestValue = $amount;
        $bestYear = $year;
    }
}

$growthData = [];
for($i = 1; $i < count($years); $i++){
    $prev = $values[$i-1];
    $curr = $values[$i];

    if($prev > 0){
        $growth = (($curr - $prev) / $prev) * 100;
        $growthData[] = [
            "from" => $years[$i-1],
            "to" => $years[$i],
            "percent" => round($growth,1)
        ];
    }
}

$yearData = [];
$yearResult = $mysqli->query("SELECT year_awarded FROM project_inventory");

while($row = $yearResult->fetch_assoc()){
    $year = !empty($row['year_awarded']) ? $row['year_awarded'] : "Unknown";

    if(!isset($yearData[$year])){
        $yearData[$year] = 0;
    }
    $yearData[$year]++;
}

ksort($yearData);

function percent($value, $total){
    if($total <= 0) return 0;
    return round(($value / $total) * 100);
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Project Tracker</title>

<link rel="icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
<link rel="shortcut icon" type="image/png" href="../image/logo.png?v=<?= $faviconVersion ?>">
<link rel="apple-touch-icon" href="../image/logo.png?v=<?= $faviconVersion ?>">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
body{
    background:#f4f6f9;
    font-family:'Segoe UI';
}
.card-modern{
    border-radius:12px;
    padding:20px;
    background:#fff;
    border:1px solid #e5e7eb;
    box-shadow:0 4px 14px rgba(0,0,0,0.06);
}
.kpi{
    font-size:32px;
    font-weight:bold;
}
.progress{
    height:12px;
    border-radius:10px;
}
.tracker-action-card{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
}
.tracker-chart canvas{
    max-height:300px;
}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<h2 class="mb-4"><i class="fa fa-chart-line"></i> Project Tracker Dashboard</h2>

<div class="row g-4 mb-4">

<div class="col-md-3"><div class="card-modern"><div>Total Projects</div><div class="kpi"><?= $total ?></div></div></div>
<div class="col-md-3"><div class="card-modern"><div>Active</div><div class="kpi text-success"><?= $activeContracts ?></div></div></div>
<div class="col-md-3"><div class="card-modern"><div>Expiring</div><div class="kpi text-warning"><?= $expiringContracts ?></div></div></div>
<div class="col-md-3"><div class="card-modern"><div>Expired</div><div class="kpi text-danger"><?= $expiredContracts ?></div></div></div>

</div>

<div class="card-modern mb-4" style="cursor:pointer;" data-bs-toggle="modal" data-bs-target="#yearValueModal">
    <h5>Total Contract Value</h5>
    <h2 class="text-primary">RM <?= number_format($totalAmount,2) ?></h2>
    <small class="text-muted">Click to view yearly breakdown</small>
</div>

<div class="card-modern tracker-action-card mb-4">
    <div>
        <h5 class="mb-1">Project / Owner Dashboard</h5>
        <small class="text-muted">Open a focused dashboard by project code or owner.</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#trackerFilterModal">
            <i class="fa fa-filter"></i> View Project / Owner
        </button>
        <a class="btn btn-dark" href="../backend/generate_contract_report.php?report_type=all&amp;period=all" target="_blank" rel="noopener">
            <i class="fa fa-file-pdf"></i> Project Report
        </a>
    </div>
</div>

<div class="row g-4">
<div class="col-md-6">
<div class="card-modern tracker-chart">
<h5>Status Distribution</h5>
<canvas id="statusChart"></canvas>
</div>
</div>

<div class="col-md-6">
<div class="card-modern tracker-chart">
<h5>Projects by Year</h5>
<canvas id="yearChart"></canvas>
</div>
</div>
</div>

</div>

<div class="modal fade" id="trackerFilterModal">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">

<div class="modal-header bg-dark text-white">
<h5 class="modal-title"><i class="fa fa-chart-pie"></i> Choose Dashboard View</h5>
<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <div class="row g-2">
        <div class="col-md-4">
            <label class="form-label" for="trackerFilterType">View By</label>
            <select id="trackerFilterType" class="form-select">
                <option value="project_code">Project Code</option>
                <option value="owner">Owner</option>
            </select>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="trackerFilterValue">Value</label>
            <input type="text" id="trackerFilterValue" class="form-control" list="trackerProjectCodeOptions" autocomplete="off">
            <datalist id="trackerProjectCodeOptions">
                <?php foreach($projectTrackerSuggestions['project_codes'] as $code): ?>
                    <option value="<?= projectDashboardEscape($code) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <datalist id="trackerOwnerOptions">
                <?php foreach($projectTrackerSuggestions['owners'] as $owner): ?>
                    <option value="<?= projectDashboardEscape($owner) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
    </div>
    <button type="button" class="btn btn-warning w-100 mt-3 fw-semibold" onclick="openTrackerInsightDashboard()">
        Open Dashboard
    </button>
</div>

</div>
</div>
</div>

<div class="modal fade" id="yearValueModal">
<div class="modal-dialog modal-xl modal-dialog-centered">
<div class="modal-content">

<div class="modal-header bg-primary text-white">
<h5 class="modal-title"><i class="fa fa-coins"></i> Contract Value by Year</h5>
<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<div class="alert alert-success text-center">
🏆 Best Year: <strong><?= htmlspecialchars($bestYear) ?></strong>
(RM <?= number_format($bestValue,2) ?>)
</div>

<canvas id="yearValueChart" style="max-height:300px;"></canvas>

<hr>

<h6>Yearly Growth</h6>
<ul>
<?php foreach($growthData as $g): ?>
<li>
<?= htmlspecialchars($g['from']) ?> → <?= htmlspecialchars($g['to']) ?>
<?php if($g['percent'] >= 0): ?>
<span class="text-success">↑ +<?= htmlspecialchars($g['percent']) ?>%</span>
<?php else: ?>
<span class="text-danger">↓ <?= htmlspecialchars($g['percent']) ?>%</span>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>

<hr>

<div class="table-responsive">
<table class="table table-bordered text-center">
<thead><tr><th>Year</th><th>Total Value (RM)</th></tr></thead>
<tbody>
<?php foreach($yearAmountData as $year => $amount): ?>
<tr>
<td><?= htmlspecialchars($year) ?></td>
<td class="fw-bold text-primary">RM <?= number_format($amount,2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div>

</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
new Chart(document.getElementById('statusChart'), {
    type:'doughnut',
    data:{
        labels:["Active","Expiring Soon","Closed"],
        datasets:[{
            data:[<?= $activeContracts ?>,<?= $expiringContracts ?>,<?= $expiredContracts ?>],
            backgroundColor:[
                '#198754',
                '#ffc107',
                '#dc3545'
            ],
            borderColor:[
                '#ffffff',
                '#ffffff',
                '#ffffff'
            ],
            borderWidth:2,
            hoverOffset:8
        }]
    },
    options:{
        animation:false,
        plugins:{
            legend:{
                position:'bottom'
            }
        }
    }
});

new Chart(document.getElementById('yearChart'), {
    type:'bar',
    data:{
        labels:<?= json_encode(array_keys($yearData)) ?>,
        datasets:[{
            label:'Total Project',
            data:<?= json_encode(array_values($yearData)) ?>
        }]
    },
    options:{
        animation:false,
        plugins:{
            legend:{
                display:true,
                position:'bottom'
            }
        }
    }
});

let yearValueChart;
document.getElementById('yearValueModal').addEventListener('shown.bs.modal', function () {

    if(yearValueChart){ yearValueChart.destroy(); }

    yearValueChart = new Chart(document.getElementById('yearValueChart'), {
        type:'bar',
        data:{
            labels:<?= json_encode(array_keys($yearAmountData)) ?>,
            datasets:[{
                label:'Total Earning',
                data:<?= json_encode(array_values($yearAmountData)) ?>
            }]
        },
        options:{
            animation:false,
            plugins:{
                legend:{
                    display:true,
                    position:'bottom'
                }
            }
        }
    });

});

const trackerFilterType = document.getElementById("trackerFilterType");
const trackerFilterValue = document.getElementById("trackerFilterValue");

function syncTrackerFilterDatalist(){
    if(!trackerFilterType || !trackerFilterValue){
        return;
    }

    trackerFilterValue.setAttribute("list", trackerFilterType.value === "owner" ? "trackerOwnerOptions" : "trackerProjectCodeOptions");
}

function openTrackerInsightDashboard(){
    if(!trackerFilterType || !trackerFilterValue){
        return;
    }

    const value = trackerFilterValue.value.trim();

    if(value === ""){
        alert("Please enter a project code or owner.");
        return;
    }

    const params = new URLSearchParams();
    params.set("filter_type", trackerFilterType.value);
    params.set("filter_value", value);
    window.location.href = "project_insights.php?" + params.toString();
}

if(trackerFilterType){
    trackerFilterType.addEventListener("change", function(){
        trackerFilterValue.value = "";
        syncTrackerFilterDatalist();
    });
    syncTrackerFilterDatalist();
}
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
