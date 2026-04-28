<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_view")){
    exit("Access denied");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if(!$id){
    exit("Invalid request");
}

$stmt = $mysqli->prepare("
    SELECT *
    FROM server_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$stmt){
    exit("Prepare failed: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_assoc();

if(!$row){
    exit("Server not found");
}

echo "
<table class='table table-bordered'>
<tr><th>Serial</th><td>".htmlspecialchars($row['serial_number'] ?? '')."</td></tr>
<tr><th>Server Name</th><td>".htmlspecialchars($row['server_name'] ?? '')."</td></tr>
<tr><th>Machine Type</th><td>".htmlspecialchars($row['machine_type'] ?? '')."</td></tr>
<tr><th>Brand</th><td>".htmlspecialchars($row['brand'] ?? '')."</td></tr>
<tr><th>Location</th><td>".htmlspecialchars($row['location'] ?? '')."</td></tr>
<tr><th>Status</th><td>".htmlspecialchars($row['status'] ?? '')."</td></tr>
<tr><th>Remark</th><td>".nl2br(htmlspecialchars($row['remark'] ?? ''))."</td></tr>
<tr><th>Date Testing</th><td>".htmlspecialchars($row['date_testing'] ?? '')."</td></tr>
<tr><th>Tester</th><td>".htmlspecialchars($row['tester'] ?? '')."</td></tr>
</table>
";
?>