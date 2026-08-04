<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";

header("Content-Type: text/plain");

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_stockout")){
    exit("Access denied");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$ticketNumber = isset($_POST['ticket_number']) ? trim($_POST['ticket_number']) : '';
$stockout_remark = isset($_POST['remark']) ? trim($_POST['remark']) : '';
$componentSelectionActive = ($_POST['component_selection_active'] ?? '') === '1';
$keepComponentIdsInput = $_POST['keep_component_ids'] ?? [];

if(!is_array($keepComponentIdsInput)){
    $keepComponentIdsInput = [$keepComponentIdsInput];
}

$keepComponentIds = [];

foreach($keepComponentIdsInput as $componentId){
    $componentId = (int)$componentId;

    if($componentId > 0){
        $keepComponentIds[$componentId] = true;
    }
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
ensureInventoryReportSchema($mysqli);

if($id <= 0){
    exit("Invalid ID");
}

$mysqli->begin_transaction();

$stmt = $mysqli->prepare("
SELECT *
FROM server_inventory
WHERE no = ?
LIMIT 1
FOR UPDATE
");

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if(!$row){
    $mysqli->rollback();
    exit("Record not found");
}

$components = [];
$componentStmt = $mysqli->prepare("
    SELECT id, component_type, part_number, serial_number
    FROM server_components
    WHERE server_inventory_id = ?
    ORDER BY id ASC
    FOR UPDATE
");

if(!$componentStmt){
    $mysqli->rollback();
    exit("SQL Error: " . $mysqli->error);
}

$componentStmt->bind_param("i", $id);
$componentStmt->execute();
$componentResult = $componentStmt->get_result();

while($component = $componentResult->fetch_assoc()){
    $components[] = $component;
}

$componentsToKeep = [];
$componentsLeavingWithServer = [];

foreach($components as $component){
    $componentId = (int)($component['id'] ?? 0);
    $shouldKeepComponent = !$componentSelectionActive || isset($keepComponentIds[$componentId]);

    if($shouldKeepComponent){
        $componentsToKeep[] = $component;
    }
    else{
        $componentsLeavingWithServer[] = $component;
    }
}

if(!empty($componentsToKeep)){
    $componentSerialCheckStmt = $mysqli->prepare("
        SELECT no
        FROM asset_inventory
        WHERE serial_number = ?
        LIMIT 1
    ");

    if(!$componentSerialCheckStmt){
        $mysqli->rollback();
        exit("SQL Error: " . $mysqli->error);
    }

    foreach($componentsToKeep as $component){
        $componentSerialCheckStmt->bind_param("s", $component['serial_number']);
        $componentSerialCheckStmt->execute();

        if($componentSerialCheckStmt->get_result()->num_rows > 0){
            $mysqli->rollback();
            exit("Component serial already exists in parts inventory: " . $component['serial_number']);
        }
    }
}

$componentsLeavingPayload = [];

foreach($componentsLeavingWithServer as $component){
    $componentsLeavingPayload[] = [
        "component_type" => (string)($component['component_type'] ?? ""),
        "part_number" => (string)($component['part_number'] ?? ""),
        "serial_number" => (string)($component['serial_number'] ?? "")
    ];
}

$stockoutComponentsJson = !empty($componentsLeavingPayload)
    ? json_encode($componentsLeavingPayload, JSON_UNESCAPED_UNICODE)
    : null;

if(!empty($componentsLeavingPayload) && $stockoutComponentsJson === false){
    $mysqli->rollback();
    exit("Component stock out data could not be saved.");
}

$insertStmt = $mysqli->prepare("
INSERT INTO server_stockout_history
(server_name, machine_type, serial_number, ticket_number, location, status, remark, stockout_components, tester, quantity, stock_out_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if(!$insertStmt){
    $mysqli->rollback();
    exit("SQL Error: " . $mysqli->error);
}

$quantity = 1;

$insertStmt->bind_param(
    "sssssssssis",
    $row['server_name'],
    $row['machine_type'],
    $row['serial_number'],
    $ticketNumber,
    $row['location'],
    $row['status'],
    $stockout_remark,
    $stockoutComponentsJson,
    $row['tester'],
    $quantity,
    $username
);

if(!$insertStmt->execute()){
    $mysqli->rollback();
    exit("Insert Error: " . $insertStmt->error);
}

$componentSummaryLines = [];
$componentStockOutLines = [];
$today = date("Y-m-d");

if(!empty($componentsToKeep)){
    $assetInsertStmt = $mysqli->prepare("
        INSERT INTO asset_inventory
        (part_number, serial_number, brand, description, quantity, location, remark, created_by, received_by, date_received)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if(!$assetInsertStmt){
        $mysqli->rollback();
        exit("SQL Error: " . $mysqli->error);
    }

    $assetHistoryStmt = $mysqli->prepare("
        INSERT INTO asset_stockin_history
        (asset_inventory_id, part_number, serial_number, brand, description, quantity, location, remark, stock_in_by, received_by, date_received, stock_in_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    if(!$assetHistoryStmt){
        $mysqli->rollback();
        exit("SQL Error: " . $mysqli->error);
    }

    foreach($componentsToKeep as $component){
        $assetQuantity = 1;
        $componentDescription = "Server Component - " . $component['component_type']
            . "\nSource Server: " . ($row['server_name'] ?? "")
            . "\nSource Server Serial: " . ($row['serial_number'] ?? "");
        $componentRemark = "Moved to parts inventory when server was stocked out."
            . "\nServer Stock Out By: $username"
            . "\nServer Name: " . ($row['server_name'] ?? "")
            . "\nServer Serial: " . ($row['serial_number'] ?? "")
            . "\nTicket Number: $ticketNumber"
            . "\nServer Stock Out Remark: $stockout_remark";

        $assetInsertStmt->bind_param(
            "ssssisssss",
            $component['part_number'],
            $component['serial_number'],
            $row['brand'],
            $componentDescription,
            $assetQuantity,
            $row['location'],
            $componentRemark,
            $username,
            $username,
            $today
        );

        if(!$assetInsertStmt->execute()){
            $mysqli->rollback();
            exit("Component transfer failed: " . $assetInsertStmt->error);
        }

        $assetInventoryId = $assetInsertStmt->insert_id;

        $assetHistoryStmt->bind_param(
            "issssisssss",
            $assetInventoryId,
            $component['part_number'],
            $component['serial_number'],
            $row['brand'],
            $componentDescription,
            $assetQuantity,
            $row['location'],
            $componentRemark,
            $username,
            $username,
            $today
        );

        if(!$assetHistoryStmt->execute()){
            $mysqli->rollback();
            exit("Component stock-in history failed: " . $assetHistoryStmt->error);
        }

        $componentSummaryLines[] = "- {$component['component_type']} | PN: {$component['part_number']} | SN: {$component['serial_number']}";
    }
}

foreach($componentsLeavingWithServer as $component){
    $componentStockOutLines[] = "- {$component['component_type']} | PN: {$component['part_number']} | SN: {$component['serial_number']}";
}

$ip = $_SERVER['REMOTE_ADDR'];
$time = date("Y-m-d H:i:s");
$componentSummary = !empty($componentSummaryLines) ? implode("\n", $componentSummaryLines) : "-";
$componentStockOutSummary = !empty($componentStockOutLines) ? implode("\n", $componentStockOutLines) : "-";

$description = "User [$username] performed STOCK OUT on server.
Server Name: {$row['server_name']}
Machine Type: {$row['machine_type']}
Serial Number: {$row['serial_number']}
Ticket Number: $ticketNumber
Location: {$row['location']}
Quantity: $quantity
Components moved to Parts Inventory:
$componentSummary
Components leaving with Server Stock Out:
$componentStockOutSummary
Remark: $stockout_remark
IP Address: $ip
Time: $time";

$deleteComponentStmt = $mysqli->prepare("
DELETE FROM server_components
WHERE server_inventory_id = ?
");

if(!$deleteComponentStmt){
    $mysqli->rollback();
    exit("SQL Error: " . $mysqli->error);
}

$deleteComponentStmt->bind_param("i", $id);

if(!$deleteComponentStmt->execute()){
    $mysqli->rollback();
    exit("Component cleanup failed: " . $deleteComponentStmt->error);
}

$deleteStmt = $mysqli->prepare("
DELETE FROM server_inventory
WHERE no = ?
");

$deleteStmt->bind_param("i", $id);

if(!$deleteStmt->execute()){
    $mysqli->rollback();
    exit("Delete failed: " . $deleteStmt->error);
}

$mysqli->commit();

logActivity(
    $mysqli,
    $username,
    $role,
    "STOCK OUT SERVER",
    $description
);

echo "success";
?>
