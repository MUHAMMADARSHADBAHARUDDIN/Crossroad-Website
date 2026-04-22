<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

/* =========================
   🔥 TOTAL PROJECTS
========================= */
$total = $mysqli->query("
    SELECT COUNT(*) as total
    FROM project_inventory
")->fetch_assoc()['total'];

/* =========================
   🔥 STATUS (DATE-BASED SYSTEM FIXED)
========================= */

// ACTIVE
$activeContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE CURDATE() BETWEEN contract_start AND contract_end
")->fetch_assoc()['total'];

// EXPIRING (next 30 days)
$expiringContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetch_assoc()['total'];

// EXPIRED
$expiredContracts = $mysqli->query("
SELECT COUNT(*) as total
FROM project_inventory
WHERE contract_end < CURDATE()
")->fetch_assoc()['total'];

/* =========================
   💰 TOTAL AMOUNT
========================= */
$totalAmount = 0;
$result = $mysqli->query("SELECT amount FROM project_inventory");

while($row = $result->fetch_assoc()){
    $amount = is_numeric($row['amount']) ? floatval($row['amount']) : 0;
    $totalAmount += $amount;
}

/* =========================
   📊 PROJECTS BY YEAR
========================= */
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

/* =========================
   📊 PERCENT FUNCTION
========================= */
function percent($value, $total){
    $value = intval($value);
    $total = intval($total);
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

.fade-in{
    animation:fadeIn 0.8s ease-in-out;
}
@keyframes fadeIn{
    from{opacity:0; transform:translateY(15px);}
    to{opacity:1; transform:translateY(0);}
}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main p-4">

<h2 class="mb-4"><i class="fa fa-chart-line"></i> Project Tracker Dashboard</h2>

<!-- KPI -->
<div class="row g-4 mb-4 fade-in">

<div class="col-md-3">
<div class="card-modern">
<div>Total Projects</div>
<div class="kpi"><?= $total ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card-modern">
<div>Active</div>
<div class="kpi text-success"><?= $activeContracts ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card-modern">
<div>Expiring</div>
<div class="kpi text-warning"><?= $expiringContracts ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card-modern">
<div>Expired</div>
<div class="kpi text-danger"><?= $expiredContracts ?></div>
</div>
</div>

</div>

<!-- TOTAL VALUE -->
<div class="card-modern mb-4 fade-in">
<h5>Total Contract Value</h5>
<h2 class="text-primary">RM <?= number_format($totalAmount,2) ?></h2>
</div>

<!-- STATUS PROGRESS -->
<div class="card-modern mb-4 fade-in">
<h5>Status Overview</h5>

<p>Active (<?= percent($activeContracts,$total) ?>%)</p>
<div class="progress mb-3">
<div class="progress-bar bg-success" style="width:<?= percent($activeContracts,$total) ?>%"></div>
</div>

<p>Expiring (<?= percent($expiringContracts,$total) ?>%)</p>
<div class="progress mb-3">
<div class="progress-bar bg-warning" style="width:<?= percent($expiringContracts,$total) ?>%"></div>
</div>

<p>Expired (<?= percent($expiredContracts,$total) ?>%)</p>
<div class="progress">
<div class="progress-bar bg-danger" style="width:<?= percent($expiredContracts,$total) ?>%"></div>
</div>

</div>

<!-- CHARTS -->
<div class="row g-4">

<div class="col-md-6">
<div class="card-modern fade-in">
<h5>Status Distribution</h5>
<canvas id="statusChart"></canvas>
</div>
</div>

<div class="col-md-6">
<div class="card-modern fade-in">
<h5>Projects by Year</h5>
<canvas id="yearChart"></canvas>
</div>
</div>

</div>

</div>

<script>

/* STATUS CHART */
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ["Active","Expiring","Expired"],
        datasets: [{
            data: [
                <?= $activeContracts ?>,
                <?= $expiringContracts ?>,
                <?= $expiredContracts ?>
            ]
        }]
    },
    options:{
        animation:{ duration:1200 }
    }
});

/* YEAR CHART */
new Chart(document.getElementById('yearChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($yearData)) ?>,
        datasets: [{
            label:'Projects',
            data: <?= json_encode(array_values($yearData)) ?>
        }]
    },
    options:{
        animation:{ duration:1200 }
    }
});
</script>

</body>
</html>