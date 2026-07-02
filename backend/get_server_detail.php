<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_view")){
    exit("Access denied");
}

ensureInventoryReportSchema($mysqli);

function getServerDetailFormatDate($value){
    $value = trim((string)($value ?? ''));

    if($value === "" || $value === "0000-00-00"){
        return "";
    }

    $timestamp = strtotime($value);

    if($timestamp === false){
        return $value;
    }

    return date("d/m/y", $timestamp);
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

$dateTesting = getServerDetailFormatDate($row['date_testing'] ?? '');
$components = [];
$componentStmt = $mysqli->prepare("
    SELECT component_type, part_number, serial_number
    FROM server_components
    WHERE server_inventory_id = ?
    ORDER BY component_type ASC, part_number ASC, serial_number ASC
");

if($componentStmt){
    $componentStmt->bind_param("i", $id);
    $componentStmt->execute();
    $componentResult = $componentStmt->get_result();

    while($component = $componentResult->fetch_assoc()){
        $components[] = $component;
    }
}

$componentCount = count($components);
$componentCell = "-";

if($componentCount > 0){
    $serialJs = htmlspecialchars(json_encode($row['serial_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $componentsJs = htmlspecialchars(json_encode($components), ENT_QUOTES, 'UTF-8');
    $componentLabel = $componentCount . " Component" . ($componentCount > 1 ? "s" : "");
    $componentCell = "
        <button type='button' class='btn btn-sm btn-outline-info'
            onclick='openServerComponentModal($serialJs, $componentsJs)'>
            " . htmlspecialchars($componentLabel) . "
        </button>
    ";
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
<tr><th>Date Testing</th><td>".htmlspecialchars($dateTesting)."</td></tr>
<tr><th>Tester</th><td>".htmlspecialchars($row['tester'] ?? '')."</td></tr>
<tr>
    <th>Component</th>
    <td>$componentCell</td>
</tr>
<tr><th>Received By</th><td>".htmlspecialchars($row['received_by'] ?? '')."</td></tr>
</table>
";
?>
