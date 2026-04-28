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

$part = $_POST['part'] ?? '';

$canEdit = hasPermission($mysqli, "inventory_edit");
$canStockOut = hasPermission($mysqli, "inventory_stockout");
$canDelete = hasPermission($mysqli, "inventory_delete");

$stmt = $mysqli->prepare("
SELECT no, serial_number, location, brand, description, date_received
FROM asset_inventory
WHERE part_number = ?
ORDER BY date_received DESC
");

$stmt->bind_param("s", $part);
$stmt->execute();
$result = $stmt->get_result();

echo "
<table class='table table-sm table-hover align-middle'>
<thead class='table-dark'>
<tr>
    <th>Serial</th>
    <th>Brand</th>
    <th>Location</th>
    <th>Date</th>
    <th style='width:220px;'>Action</th>
</tr>
</thead>
<tbody>
";

while($row = $result->fetch_assoc()){

    $id = (int)$row['no'];
    $serialJs = htmlspecialchars(json_encode($row['serial_number'] ?? ''), ENT_QUOTES, 'UTF-8');

    echo "<tr style='cursor:pointer;' onclick='viewDetail($id)'>";

    echo "<td>" . htmlspecialchars($row['serial_number'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['brand'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['location'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['date_received'] ?? '') . "</td>";

    echo "<td>";

    if($canEdit){
        echo "
        <a href='asset_edit.php?id=$id'
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
            onclick='event.stopPropagation(); openRemarkModal($id, $serialJs)'
            title='Stock Out'>
            <i class='fa fa-arrow-up'></i>
        </button>
        ";
    }

    if($canDelete){
        echo "
        <button class='btn btn-sm btn-danger ms-1'
            onclick='event.stopPropagation(); deleteAssetDirect($id, $serialJs)'
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