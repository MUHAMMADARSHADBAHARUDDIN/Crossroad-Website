<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";
require_once "../includes/date_helpers.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];
ensureInventoryReportSchema($mysqli);

if(!hasPermission($mysqli, "inventory_add")){
    header("Location: server_inventory.php");
    exit();
}

$error = "";
$duplicateServerSerial = false;
$duplicateComponentRowIndexes = [];

function serverAddComponentOptions(){
    return [
        "hard_disk" => "Hard Disk",
        "power_supply" => "Power Supply",
        "motherboard" => "Motherboard",
        "memory" => "Memory",
        "other" => "Other"
    ];
}

function serverAddEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function serverAddOld($fieldName){
    return serverAddEscape($_POST[$fieldName] ?? '');
}

function serverAddSelected($actualValue, $expectedValue){
    return (string)$actualValue === (string)$expectedValue ? 'selected' : '';
}

function serverAddComponentFormRows(){
    $types = $_POST['component_type'] ?? [];
    $others = $_POST['component_other'] ?? [];
    $partNumbers = $_POST['component_part_number'] ?? [];
    $serialNumbers = $_POST['component_serial_number'] ?? [];

    if(!isset($_POST['add']) || !is_array($types)){
        return [[
            "type" => "",
            "other" => "",
            "part_number" => "",
            "serial_number" => ""
        ]];
    }

    $count = max(count($types), count((array)$others), count((array)$partNumbers), count((array)$serialNumbers), 1);
    $rows = [];

    for($index = 0; $index < $count; $index++){
        $rows[] = [
            "type" => trim((string)($types[$index] ?? "")),
            "other" => trim((string)($others[$index] ?? "")),
            "part_number" => trim((string)($partNumbers[$index] ?? "")),
            "serial_number" => trim((string)($serialNumbers[$index] ?? ""))
        ];
    }

    return $rows;
}

function serverAddParseComponents(&$duplicateComponentRowIndexes){
    $types = $_POST['component_type'] ?? [];
    $others = $_POST['component_other'] ?? [];
    $partNumbers = $_POST['component_part_number'] ?? [];
    $serialNumbers = $_POST['component_serial_number'] ?? [];
    $options = serverAddComponentOptions();
    $components = [];
    $seenSerials = [];

    if(!is_array($types)){
        return [[], "Invalid component data."];
    }

    foreach($types as $index => $type){
        $type = trim((string)$type);
        $other = trim((string)($others[$index] ?? ""));
        $partNumber = trim((string)($partNumbers[$index] ?? ""));
        $serialNumber = trim((string)($serialNumbers[$index] ?? ""));

        if($type === "" && $partNumber === "" && $serialNumber === "" && $other === ""){
            continue;
        }

        if($type === "" || !isset($options[$type])){
            return [[], "Please choose a valid component type."];
        }

        $componentType = $options[$type];

        if($type === "other"){
            if($other === ""){
                return [[], "Please enter the component name for Other."];
            }

            $componentType = $other;
        }

        if($partNumber === "" || $serialNumber === ""){
            return [[], "Part Number and Serial Number are required for each selected component."];
        }

        $serialKey = strtoupper($serialNumber);

        if(isset($seenSerials[$serialKey])){
            $duplicateComponentRowIndexes[] = $seenSerials[$serialKey];
            $duplicateComponentRowIndexes[] = (int)$index;
            return [[], "Duplicate component serial number in this form: $serialNumber"];
        }

        $seenSerials[$serialKey] = (int)$index;
        $components[] = [
            "form_index" => (int)$index,
            "component_type" => $componentType,
            "part_number" => $partNumber,
            "serial_number" => $serialNumber
        ];
    }

    return [$components, ""];
}

if(isset($_POST['add'])){

    $server   = trim($_POST['server_name']);
    $serial   = trim($_POST['serial_number']);
    $brand    = trim($_POST['brand']);
    $machine  = trim($_POST['machine_type']);
    $location = trim($_POST['location']);
    $status   = trim($_POST['status']);
    $remark   = trim($_POST['remark']);
    $date     = appNormalizeDateInput($_POST['date_testing'] ?? "");
    $tester   = trim($_POST['tester']);
    $receivedBy = $username;
    [$components, $componentError] = serverAddParseComponents($duplicateComponentRowIndexes);

    if($componentError !== ""){
        $error = $componentError;
    }
    elseif($server == "" || $serial == ""){
        $error = "Server Name and Serial Number are required!";
    } else {

        $checkStmt = $mysqli->prepare("
            SELECT no
            FROM server_inventory
            WHERE serial_number = ?
            LIMIT 1
        ");

        if(!$checkStmt){
            die("Prepare failed: " . $mysqli->error);
        }

        $checkStmt->bind_param("s", $serial);
        $checkStmt->execute();
        $check = $checkStmt->get_result();

        if($check->num_rows > 0){
            $duplicateServerSerial = true;
            $error = "Serial Number already exists!";
        } else {
            if(!empty($components)){
                $componentCheckStmt = $mysqli->prepare("
                    SELECT serial_number
                    FROM server_components
                    WHERE serial_number = ?
                    UNION
                    SELECT serial_number
                    FROM asset_inventory
                    WHERE serial_number = ?
                    LIMIT 1
                ");

                if(!$componentCheckStmt){
                    die("Prepare failed: " . $mysqli->error);
                }

                foreach($components as $component){
                    $componentCheckStmt->bind_param(
                        "ss",
                        $component['serial_number'],
                        $component['serial_number']
                    );
                    $componentCheckStmt->execute();

                    if($componentCheckStmt->get_result()->num_rows > 0){
                        $duplicateComponentRowIndexes[] = $component['form_index'];
                        $error = "Component serial number already exists in inventory: " . $component['serial_number'];
                        break;
                    }
                }
            }
        }

        if($error === ""){
            $mysqli->begin_transaction();

            $stmt = $mysqli->prepare("
                INSERT INTO server_inventory
                (server_name, serial_number, brand, machine_type, location, status, remark, date_testing, tester, created_by, received_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if(!$stmt){
                $mysqli->rollback();
                die("Prepare failed: " . $mysqli->error);
            }

            $stmt->bind_param(
                "sssssssssss",
                $server,
                $serial,
                $brand,
                $machine,
                $location,
                $status,
                $remark,
                $date,
                $tester,
                $username,
                $receivedBy
            );

            if($stmt->execute()){
                $serverInventoryId = $stmt->insert_id;
                $quantity = 1;
                $componentSummaryLines = [];

                if(!empty($components)){
                    $componentStmt = $mysqli->prepare("
                        INSERT INTO server_components
                        (server_inventory_id, component_type, part_number, serial_number, created_by)
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    if(!$componentStmt){
                        $mysqli->rollback();
                        die("Prepare failed: " . $mysqli->error);
                    }

                    foreach($components as $component){
                        $componentStmt->bind_param(
                            "issss",
                            $serverInventoryId,
                            $component['component_type'],
                            $component['part_number'],
                            $component['serial_number'],
                            $username
                        );

                        if(!$componentStmt->execute()){
                            $mysqli->rollback();
                            $error = "Component insert failed: " . $componentStmt->error;
                            break;
                        }

                        $componentSummaryLines[] = "- {$component['component_type']} | PN: {$component['part_number']} | SN: {$component['serial_number']}";
                    }
                }

                if($error === ""){
                    $historyStmt = $mysqli->prepare("
                        INSERT INTO server_stockin_history
                        (server_inventory_id, server_name, brand, machine_type, serial_number, location, status, remark, date_testing, tester, quantity, stock_in_by, received_by, stock_in_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");

                    if(!$historyStmt){
                        $mysqli->rollback();
                        $error = "Stock in history prepare failed: " . $mysqli->error;
                    }
                    else{
                        $historyStmt->bind_param(
                            "isssssssssiss",
                            $serverInventoryId,
                            $server,
                            $brand,
                            $machine,
                            $serial,
                            $location,
                            $status,
                            $remark,
                            $date,
                            $tester,
                            $quantity,
                            $username,
                            $receivedBy
                        );

                        if(!$historyStmt->execute()){
                            $mysqli->rollback();
                            $error = "Stock in history insert failed: " . $historyStmt->error;
                        }
                    }
                }

                if($error === ""){
                    $mysqli->commit();

                    $ip = $_SERVER['REMOTE_ADDR'];
                    $time = date("Y-m-d H:i:s");
                    $componentSummary = !empty($componentSummaryLines)
                        ? implode("\n", $componentSummaryLines)
                        : "-";

                    $description = "User [$username] added new server.
Server Name: $server
Serial Number: $serial
Brand: $brand
Machine Type: $machine
Location: $location
Status: $status
Tester: $tester
Quantity: $quantity
Received By: $receivedBy
Date Testing: $date
Components:
$componentSummary
Remark: $remark
IP Address: $ip
Time: $time";

                    logActivity(
                        $mysqli,
                        $username,
                        $role,
                        "ADD SERVER",
                        $description
                    );

                    header("Location: server_inventory.php");
                    exit();
                }

            } else {
                $mysqli->rollback();
                $error = "Insert failed: " . $mysqli->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Server</title>

<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.server-component-row{
    border:1px solid #dee2e6;
    border-radius:8px;
    padding:12px;
    background:#fff;
}

.server-component-detail,
.server-component-other{
    display:none;
}

.server-component-row.has-component .server-component-detail{
    display:block;
}

.server-component-row.has-other .server-component-other{
    display:block;
}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">Add Server</h2>

<?php if($error != ""): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST">

<div class="row">

<div class="col-md-6 mb-3">
<label>Server Name *</label>
<input type="text" name="server_name" class="form-control" value="<?= serverAddOld('server_name') ?>" required>
</div>

<div class="col-md-6 mb-3">
<label>Serial Number *</label>
<input type="text" name="serial_number" class="form-control <?= $duplicateServerSerial ? 'is-invalid' : '' ?>" value="<?= serverAddOld('serial_number') ?>" required>
<?php if($duplicateServerSerial): ?>
<div class="invalid-feedback">This server serial number already exists.</div>
<?php endif; ?>
</div>

<div class="col-md-6 mb-3">
<label>Brand</label>
<input type="text" name="brand" class="form-control" value="<?= serverAddOld('brand') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Machine Type</label>
<input type="text" name="machine_type" class="form-control" value="<?= serverAddOld('machine_type') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Location</label>
<input type="text" name="location" class="form-control" value="<?= serverAddOld('location') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Status</label>
<input type="text" name="status" class="form-control" value="<?= serverAddOld('status') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Tester</label>
<input type="text" name="tester" class="form-control" value="<?= serverAddOld('tester') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Date Testing</label>
<input type="date" name="date_testing" class="form-control" value="<?= serverAddOld('date_testing') ?>">
</div>

<div class="col-12 mb-3">
<label>Component</label>
<div id="serverComponentRows" class="d-flex flex-column gap-2">
    <?php foreach(serverAddComponentFormRows() as $componentIndex => $componentRow): ?>
    <?php
    $componentType = $componentRow['type'] ?? "";
    $isDuplicateComponent = in_array((int)$componentIndex, $duplicateComponentRowIndexes, true);
    ?>
    <div class="server-component-row">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Component</label>
                <select name="component_type[]" class="form-select server-component-select">
                    <option value="" <?= serverAddSelected($componentType, '') ?>>No Component</option>
                    <?php foreach(serverAddComponentOptions() as $optionValue => $optionLabel): ?>
                        <option value="<?= serverAddEscape($optionValue) ?>" <?= serverAddSelected($componentType, $optionValue) ?>><?= serverAddEscape($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 server-component-other">
                <label class="form-label">Other Component</label>
                <input type="text" name="component_other[]" class="form-control server-component-other-input" value="<?= serverAddEscape($componentRow['other'] ?? '') ?>">
            </div>
            <div class="col-md-3 server-component-detail">
                <label class="form-label">Part Number</label>
                <input type="text" name="component_part_number[]" class="form-control server-component-part" value="<?= serverAddEscape($componentRow['part_number'] ?? '') ?>">
            </div>
            <div class="col-md-3 server-component-detail">
                <label class="form-label">Serial Number</label>
                <input type="text" name="component_serial_number[]" class="form-control server-component-serial <?= $isDuplicateComponent ? 'is-invalid' : '' ?>" value="<?= serverAddEscape($componentRow['serial_number'] ?? '') ?>">
                <?php if($isDuplicateComponent): ?>
                <div class="invalid-feedback">Duplicate component serial number.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger w-100 server-component-remove">Remove</button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="addServerComponentBtn">Add Component</button>
</div>

<div class="col-md-12 mb-3">
<label>Remark</label>
<textarea name="remark" class="form-control"><?= serverAddOld('remark') ?></textarea>
</div>

</div>

<button class="btn btn-warning" name="add">Add Server</button>
<a href="server_inventory.php" class="btn btn-secondary">Cancel</a>

</form>

</div>

<script>
function createServerComponentRow(){
    return `
        <div class="server-component-row">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Component</label>
                    <select name="component_type[]" class="form-select server-component-select">
                        <option value="">No Component</option>
                        <option value="hard_disk">Hard Disk</option>
                        <option value="power_supply">Power Supply</option>
                        <option value="motherboard">Motherboard</option>
                        <option value="memory">Memory</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-3 server-component-other">
                    <label class="form-label">Other Component</label>
                    <input type="text" name="component_other[]" class="form-control server-component-other-input">
                </div>
                <div class="col-md-3 server-component-detail">
                    <label class="form-label">Part Number</label>
                    <input type="text" name="component_part_number[]" class="form-control server-component-part">
                </div>
                <div class="col-md-3 server-component-detail">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="component_serial_number[]" class="form-control server-component-serial">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-danger w-100 server-component-remove">Remove</button>
                </div>
            </div>
        </div>
    `;
}

function syncServerComponentRow(row){
    const select = row.querySelector(".server-component-select");
    const hasComponent = select && select.value !== "";
    const hasOther = select && select.value === "other";

    row.classList.toggle("has-component", !!hasComponent);
    row.classList.toggle("has-other", !!hasOther);

    row.querySelectorAll(".server-component-part, .server-component-serial").forEach(function(field){
        field.required = !!hasComponent;

        if(!hasComponent){
            field.value = "";
        }
    });

    row.querySelectorAll(".server-component-other-input").forEach(function(field){
        field.required = !!hasOther;

        if(!hasOther){
            field.value = "";
        }
    });
}

function refreshServerComponentRows(){
    document.querySelectorAll(".server-component-row").forEach(syncServerComponentRow);
}

document.addEventListener("change", function(event){
    if(event.target.classList.contains("server-component-select")){
        syncServerComponentRow(event.target.closest(".server-component-row"));
    }
});

document.addEventListener("input", function(event){
    if(event.target.classList.contains("server-component-serial") || event.target.name === "serial_number"){
        event.target.classList.remove("is-invalid");
    }
});

document.addEventListener("click", function(event){
    if(event.target.id === "addServerComponentBtn"){
        document.getElementById("serverComponentRows").insertAdjacentHTML("beforeend", createServerComponentRow());
        refreshServerComponentRows();
    }

    if(event.target.classList.contains("server-component-remove")){
        const rows = document.querySelectorAll(".server-component-row");
        const row = event.target.closest(".server-component-row");

        if(rows.length <= 1){
            row.querySelectorAll("select, input").forEach(function(field){
                field.value = "";
            });
            syncServerComponentRow(row);
            return;
        }

        row.remove();
    }
});

refreshServerComponentRows();
</script>

</body>
</html>
