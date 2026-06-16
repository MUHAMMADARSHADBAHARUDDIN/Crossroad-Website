<?php
require_once "../includes/db_connect.php";
require_once "../includes/visit_tracking.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$recorded = recordLoginPageVisit($mysqli);
$totalVisits = getLoginPageVisitCount($mysqli);

echo json_encode([
    "success" => $recorded,
    "total" => $totalVisits
]);
?>
