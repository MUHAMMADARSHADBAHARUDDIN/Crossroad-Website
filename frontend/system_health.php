<?php
require_once '../includes/security.php';
startSecureSession(false);
require_once '../includes/db_connect.php';
require_once '../includes/permissions.php';
require_once '../includes/mailer.php';
require_once '../includes/telegram.php';
require_once '../includes/system_health.php';

if(!isset($_SESSION['username'])){
    header('Location: index.html');
    exit;
}

if(!isAdministratorAccount($mysqli)){
    http_response_code(403);
    exit('Access denied. System Health is available to administrator accounts only.');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
date_default_timezone_set('Asia/Kuala_Lumpur');
$healthChecks = crossroadRunSystemHealthChecks($mysqli);
$healthCounts = ['healthy' => 0, 'warning' => 0, 'error' => 0];
foreach($healthChecks as $check){
    $healthCounts[$check['status']]++;
}
$overallStatus = $healthCounts['error'] > 0 ? 'error' : ($healthCounts['warning'] > 0 ? 'warning' : 'healthy');
$overallText = $overallStatus === 'healthy'
    ? 'All monitored services are healthy.'
    : ($overallStatus === 'warning' ? 'The system is working, but some items need attention.' : 'One or more services require attention.');

function systemHealthEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function systemHealthStatusLabel($status){
    return ['healthy' => 'Healthy', 'warning' => 'Attention', 'error' => 'Unavailable'][$status] ?? 'Unknown';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Health</title>
    <link rel="icon" type="image/png" href="../image/logo.png">
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .health-summary,.health-card{height:auto!important;display:block!important;cursor:default!important}
        .health-summary{border:1px solid #d9dee7;border-left:5px solid #6c757d}
        .health-summary.health-healthy{border-left-color:#198754}.health-summary.health-warning{border-left-color:#ffc107}.health-summary.health-error{border-left-color:#dc3545}
        .health-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .health-card{border:1px solid #d9dee7;border-radius:12px;overflow:hidden;box-shadow:0 3px 12px rgba(24,34,45,.06)}
        .health-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px 18px 12px}
        .health-title{display:flex;align-items:center;gap:12px;min-width:0}.health-icon{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px}
        .health-healthy .health-icon{background:#d1e7dd;color:#0f5132}.health-warning .health-icon{background:#fff3cd;color:#664d03}.health-error .health-icon{background:#f8d7da;color:#842029}
        .health-title h3{font-size:1.05rem;margin:0 0 3px}.health-title p{margin:0;color:#6c757d;font-size:.84rem}
        .health-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 9px;font-size:.72rem;font-weight:700;white-space:nowrap}
        .health-badge-healthy{background:#d1e7dd;color:#0f5132}.health-badge-warning{background:#fff3cd;color:#664d03}.health-badge-error{background:#f8d7da;color:#842029}
        .health-details{margin:0;padding:0 18px 16px;list-style:none}.health-details li{display:grid;grid-template-columns:minmax(110px,38%) minmax(0,1fr);gap:12px;padding:8px 0;border-top:1px solid #edf0f3;font-size:.8rem}
        .health-details span{color:#6c757d}.health-details strong{font-weight:600;overflow-wrap:anywhere;text-align:right}
        .health-legend{display:flex;flex-wrap:wrap;gap:12px;font-size:.8rem;color:#6c757d}.health-legend span{display:inline-flex;align-items:center;gap:6px}.health-dot{width:9px;height:9px;border-radius:50%}
        @media(max-width:900px){.health-grid{grid-template-columns:1fr}}
        @media(max-width:520px){.health-card-head{flex-direction:column}.health-details li{grid-template-columns:1fr;gap:3px}.health-details strong{text-align:left}}
    </style>
</head>
<body>
<?php include 'layout/header.php'; include 'layout/sidebar.php'; ?>
<main class="main" id="main">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="mb-1">System Health</h2>
            <p class="text-muted mb-0">Administrator-only status checks for the services used by Crossroad System.</p>
        </div>
        <a href="system_health.php" class="btn btn-warning" data-system-health-check><i class="fa fa-rotate-right"></i> Run Checks Again</a>
    </div>

    <section class="card health-summary health-<?= systemHealthEscape($overallStatus) ?> mb-3">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h5 class="mb-1"><?= systemHealthEscape($overallText) ?></h5>
                <div class="text-muted small">Checked <?= systemHealthEscape(date('d M Y, g:i:s a')) ?> (Asia/Kuala Lumpur)</div>
            </div>
            <div class="health-legend" aria-label="Health summary">
                <span><i class="health-dot bg-success"></i><?= (int)$healthCounts['healthy'] ?> healthy</span>
                <span><i class="health-dot bg-warning"></i><?= (int)$healthCounts['warning'] ?> attention</span>
                <span><i class="health-dot bg-danger"></i><?= (int)$healthCounts['error'] ?> unavailable</span>
            </div>
        </div>
    </section>

    <div class="health-grid">
        <?php foreach($healthChecks as $check): ?>
            <article class="card health-card health-<?= systemHealthEscape($check['status']) ?>">
                <div class="health-card-head">
                    <div class="health-title">
                        <div class="health-icon"><i class="fa <?= systemHealthEscape($check['icon']) ?>" aria-hidden="true"></i></div>
                        <div><h3><?= systemHealthEscape($check['label']) ?></h3><p><?= systemHealthEscape($check['summary']) ?></p></div>
                    </div>
                    <span class="health-badge health-badge-<?= systemHealthEscape($check['status']) ?>">
                        <i class="fa <?= $check['status'] === 'healthy' ? 'fa-circle-check' : ($check['status'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-xmark') ?>" aria-hidden="true"></i>
                        <?= systemHealthEscape(systemHealthStatusLabel($check['status'])) ?>
                    </span>
                </div>
                <?php if(!empty($check['details'])): ?>
                    <ul class="health-details">
                        <?php foreach($check['details'] as $detailLabel => $detailValue): ?>
                            <li><span><?= systemHealthEscape($detailLabel) ?></span><strong><?= systemHealthEscape($detailValue) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<?php include 'layout/footer.php'; ?>
</body>
</html>
