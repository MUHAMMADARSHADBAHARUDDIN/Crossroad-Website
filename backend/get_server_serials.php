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

function getServerSerialFormatDate($value){
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

$name = $_POST['name'] ?? '';
$type = $_POST['type'] ?? '';

$canEdit = hasPermission($mysqli, "inventory_edit");
$canStockOut = hasPermission($mysqli, "inventory_stockout");
$canDelete = hasPermission($mysqli, "inventory_delete");

$stmt = $mysqli->prepare("
SELECT no, serial_number, location, status, remark, date_testing, tester, received_by
FROM server_inventory
WHERE server_name = ? AND machine_type = ?
ORDER BY date_testing DESC
");

$stmt->bind_param("ss", $name, $type);
$stmt->execute();
$result = $stmt->get_result();
$serverRows = [];
$serverIds = [];

while($row = $result->fetch_assoc()){
    $serverRows[] = $row;
    $serverIds[] = (int)$row['no'];
}

$componentMap = [];

if(!empty($serverIds)){
    $idList = implode(",", array_map("intval", $serverIds));
    $componentResult = $mysqli->query("
        SELECT id, server_inventory_id, component_type, part_number, serial_number
        FROM server_components
        WHERE server_inventory_id IN ($idList)
        ORDER BY component_type ASC, part_number ASC, serial_number ASC
    ");

    if($componentResult){
        while($component = $componentResult->fetch_assoc()){
            $componentMap[(int)$component['server_inventory_id']][] = $component;
        }
    }
}

echo "
<table class='table table-sm table-hover align-middle'>
<thead class='table-dark'>
<tr>
    <th>Serial</th>
    <th>Location</th>
    <th>Status</th>
    <th>Date Tested</th>
    <th>Tester</th>
    <th>Component</th>
    <th>Received By</th>
    <th style='width:220px;'>Action</th>
</tr>
</thead>
<tbody>
";

foreach($serverRows as $row){

    $id = (int)$row['no'];
    $serialJs = htmlspecialchars(json_encode($row['serial_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $components = $componentMap[$id] ?? [];
    $componentsJs = htmlspecialchars(json_encode($components), ENT_QUOTES, 'UTF-8');
    $componentCount = count($components);

    $statusColor = ($row['status'] == 'Okay') ? 'success' : 'danger';
    $dateTesting = getServerSerialFormatDate($row['date_testing'] ?? '');

    echo "<tr style='cursor:pointer;' onclick='viewServerDetail($id)'>";

    echo "<td>" . htmlspecialchars($row['serial_number'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['location'] ?? '') . "</td>";
    echo "<td><span class='badge bg-$statusColor'>" . htmlspecialchars($row['status'] ?? '') . "</span></td>";
    echo "<td>" . htmlspecialchars($dateTesting) . "</td>";
    echo "<td>" . htmlspecialchars($row['tester'] ?? '') . "</td>";

    echo "<td>" . ($componentCount > 0 ? htmlspecialchars((string)$componentCount) : "-") . "</td>";

    echo "<td>" . htmlspecialchars($row['received_by'] ?? '') . "</td>";

    echo "<td>";

    if($canEdit){
        echo "
        <a href='server_edit.php?id=$id'
           class='btn btn-sm btn-primary'
           onclick='event.stopPropagation();'
           title='Edit'>
           <i class='fa fa-pen'></i>
        </a>
        ";
    }

    if($canStockOut){
        echo "
        <button class='btn btn-sm btn-warning ms-1'
           onclick='event.stopPropagation(); openServerRemarkModal($id, $serialJs, $componentsJs)'
           title='Stock Out'>
            <i class='fa fa-arrow-up'></i>
        </button>
        ";
    }

    if($canDelete){
        echo "
        <button class='btn btn-sm btn-danger ms-1'
           onclick='event.stopPropagation(); deleteServerDirect($id, $serialJs)'
           title='Delete Without Stock Out'>
            <i class='fa fa-trash'></i>
        </button>
        ";
    }

    if(!$canEdit && !$canStockOut && !$canDelete){
        echo "<span class='badge bg-secondary'>View Only</span>";
    }

    echo "</td>";
    echo "</tr>";
}

echo "</tbody></table>";
?>
