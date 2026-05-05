<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!hasPermission($mysqli, "contracts_view")){
    die("Access denied");
}

$total = $mysqli->query("
    SELECT COUNT(*) as total
    FROM project_inventory
")->fetch_assoc()['total'];

$activeContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE CURDATE() BETWEEN contract_start AND contract_end
")->fetch_assoc()['total'];

$expiringContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetch_assoc()['total'];

$expiredContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE contract_end < CURDATE()
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

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
body{
    background:linear-gradient(135deg,#eef2f7,#f8fbff);
    font-family:'Segoe UI';
}
.card-modern{
    border-radius:20px;
    padding:20px;
    background:rgba(255,255,255,0.75);
    backdrop-filter:blur(12px);
    box-shadow:0 10px 30px rgba(0,0,0,0.08);
    transition:0.3s;
}
.card-modern:hover{
    transform:translateY(-5px) scale(1.01);
}
.kpi{
    font-size:32px;
    font-weight:bold;
}
.progress{
    height:12px;
    border-radius:10px;
}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main p-4">

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

<div class="row g-4">
<div class="col-md-6">
<div class="card-modern">
<h5>Status Distribution</h5>
<canvas id="statusChart"></canvas>
</div>
</div>

<div class="col-md-6">
<div class="card-modern">
<h5>Projects by Year</h5>
<canvas id="yearChart"></canvas>
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
            plugins:{
                legend:{
                    display:true,
                    position:'bottom'
                }
            }
        }
    });

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