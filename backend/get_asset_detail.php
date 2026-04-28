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
    FROM asset_inventory
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
    exit("Asset not found");
}

echo "
<div class='row g-3'>

<div class='col-md-6'>
<label class='fw-bold'>Part Number</label>
<div>".htmlspecialchars($row['part_number'] ?? '')."</div>
</div>

<div class='col-md-6'>
<label class='fw-bold'>Serial Number</label>
<div>".htmlspecialchars($row['serial_number'] ?? '')."</div>
</div>

<div class='col-md-6'>
<label class='fw-bold'>Brand</label>
<div>".htmlspecialchars($row['brand'] ?? '')."</div>
</div>

<div class='col-md-6'>
<label class='fw-bold'>Location</label>
<div>".htmlspecialchars($row['location'] ?? '')."</div>
</div>

<div class='col-md-6'>
<label class='fw-bold'>Date Received</label>
<div>".htmlspecialchars($row['date_received'] ?? '')."</div>
</div>

<div class='col-12'>
<label class='fw-bold'>Description</label>
<div>".nl2br(htmlspecialchars($row['description'] ?? ''))."</div>
</div>

</div>
";
?>