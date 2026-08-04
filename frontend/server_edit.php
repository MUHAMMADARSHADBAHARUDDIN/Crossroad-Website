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

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];
ensureInventoryReportSchema($mysqli);

if(!isset($_GET['id'])){
    die("Invalid request");
}

$id = intval($_GET['id']);

$stmt = $mysqli->prepare("
    SELECT *
    FROM server_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$stmt){
    die("Prepare failed: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_assoc();

if(!$row){
    die("No data found");
}

$canEdit = hasPermission($mysqli, "inventory_edit");
$error = "";
$duplicateServerSerial = false;
$duplicateComponentRowIndexes = [];

function serverEditComponentOptions(){
    return [
        "hard_disk" => "Hard Disk",
        "power_supply" => "Power Supply",
        "motherboard" => "Motherboard",
        "memory" => "Memory",
        "other" => "Other"
    ];
}

function serverEditEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function serverEditSelected($actual, $expected){
    return (string)$actual === (string)$expected ? 'selected' : '';
}

function serverEditParseComponents(&$duplicateIndexes){
    $types = (array)($_POST['component_type'] ?? []);
    $others = (array)($_POST['component_other'] ?? []);
    $parts = (array)($_POST['component_part_number'] ?? []);
    $serials = (array)($_POST['component_serial_number'] ?? []);
    $options = serverEditComponentOptions();
    $components = [];
    $seen = [];

    foreach($types as $index => $type){
        $type = trim((string)$type);
        $other = trim((string)($others[$index] ?? ''));
        $part = trim((string)($parts[$index] ?? ''));
        $serial = trim((string)($serials[$index] ?? ''));
        if($type === '' && $other === '' && $part === '' && $serial === ''){ continue; }
        if(!isset($options[$type])){ return [[], 'Please choose a valid component type.']; }
        $componentType = $type === 'other' ? $other : $options[$type];
        if($componentType === ''){ return [[], 'Please enter the component name for Other.']; }
        if($part === '' || $serial === ''){ return [[], 'Part Number and Serial Number are required for each component.']; }
        $key = strtoupper($serial);
        if(isset($seen[$key])){
            $duplicateIndexes[] = $seen[$key];
            $duplicateIndexes[] = (int)$index;
            return [[], 'Duplicate component serial number in this form: ' . $serial];
        }
        $seen[$key] = (int)$index;
        $components[] = ['form_index'=>(int)$index, 'component_type'=>$componentType, 'part_number'=>$part, 'serial_number'=>$serial];
    }
    return [$components, ''];
}

$existingComponents = [];
$componentResult = $mysqli->prepare("SELECT component_type, part_number, serial_number FROM server_components WHERE server_inventory_id = ? ORDER BY id ASC");
if($componentResult){
    $componentResult->bind_param("i", $id);
    $componentResult->execute();
    $loadedComponents = $componentResult->get_result();
    while($loadedComponents && $component = $loadedComponents->fetch_assoc()){ $existingComponents[] = $component; }
}

if(isset($_POST['update']) && $canEdit){

    $server   = trim($_POST['server_name']);
    $serial   = trim($_POST['serial_number']);
    $brand    = trim($_POST['brand']);
    $machine  = trim($_POST['machine_type']);
    $location = trim($_POST['location']);
    $status   = trim($_POST['status']);
    $remark   = trim($_POST['remark']);
    $date     = appNormalizeDateInput($_POST['date_testing'] ?? "");
    $tester   = trim($_POST['tester']);
    $receivedBy = trim($_POST['received_by'] ?? "");
    [$components, $componentError] = serverEditParseComponents($duplicateComponentRowIndexes);

    if($componentError !== ""){
        $error = $componentError;
    }
    elseif($server === "" || $serial === ""){
        $error = "Server Name and Serial Number are required.";
    }

    if($error === ""){
        $serialCheck = $mysqli->prepare("SELECT no FROM server_inventory WHERE serial_number = ? AND no <> ? LIMIT 1");
        $serialCheck->bind_param("si", $serial, $id);
        $serialCheck->execute();
        if($serialCheck->get_result()->num_rows > 0){
            $duplicateServerSerial = true;
            $error = "Server serial number already exists.";
        }
    }

    if($error === "" && !empty($components)){
        $componentCheck = $mysqli->prepare("
            SELECT serial_number FROM server_components WHERE serial_number = ? AND server_inventory_id <> ?
            UNION ALL
            SELECT serial_number FROM asset_inventory WHERE serial_number = ?
            LIMIT 1
        ");
        foreach($components as $component){
            $componentCheck->bind_param("sis", $component['serial_number'], $id, $component['serial_number']);
            $componentCheck->execute();
            if($componentCheck->get_result()->num_rows > 0){
                $duplicateComponentRowIndexes[] = $component['form_index'];
                $error = "Component serial number already exists in inventory: " . $component['serial_number'];
                break;
            }
        }
    }

    if($error === ""){
    $mysqli->begin_transaction();

    $updateStmt = $mysqli->prepare("
        UPDATE server_inventory SET
            server_name = ?,
            serial_number = ?,
            brand = ?,
            machine_type = ?,
            location = ?,
            status = ?,
            remark = ?,
            date_testing = ?,
            tester = ?,
            received_by = ?
        WHERE no = ?
    ");

    if(!$updateStmt){
        die("Prepare failed: " . $mysqli->error);
    }

    $updateStmt->bind_param(
        "ssssssssssi",
        $server,
        $serial,
        $brand,
        $machine,
        $location,
        $status,
        $remark,
        $date,
        $tester,
        $receivedBy,
        $id
    );

    if(!$updateStmt->execute()){
        $error = "Update failed: " . $updateStmt->error;
    }

    if($error === ""){
      $historyUpdateStmt = $mysqli->prepare("
        UPDATE server_stockin_history SET
            server_name = ?,
            brand = ?,
            machine_type = ?,
            serial_number = ?,
            location = ?,
            status = ?,
            remark = ?,
            date_testing = ?,
            tester = ?,
            received_by = ?
        WHERE server_inventory_id = ?
    ");

    if($historyUpdateStmt){
        $historyUpdateStmt->bind_param(
            "ssssssssssi",
            $server,
            $brand,
            $machine,
            $serial,
            $location,
            $status,
            $remark,
            $date,
            $tester,
            $receivedBy,
            $id
        );
        if(!$historyUpdateStmt->execute()){ $error = "Unable to update server history."; }
    }
    }

    if($error === ""){
        $deleteComponents = $mysqli->prepare("DELETE FROM server_components WHERE server_inventory_id = ?");
        $deleteComponents->bind_param("i", $id);
        if(!$deleteComponents->execute()){ $error = "Unable to replace server components."; }
    }

    if($error === "" && !empty($components)){
        $insertComponent = $mysqli->prepare("INSERT INTO server_components (server_inventory_id, component_type, part_number, serial_number, created_by) VALUES (?, ?, ?, ?, ?)");
        foreach($components as $component){
            $insertComponent->bind_param("issss", $id, $component['component_type'], $component['part_number'], $component['serial_number'], $username);
            if(!$insertComponent->execute()){ $error = "Unable to save server component."; break; }
        }
    }

    if($error !== ""){
        $mysqli->rollback();
    } else {
        $mysqli->commit();

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] updated server.
Server ID: $id

OLD DATA:
- Server Name: {$row['server_name']}
- Serial Number: {$row['serial_number']}
- Brand: {$row['brand']}
- Machine Type: {$row['machine_type']}
- Location: {$row['location']}
- Status: {$row['status']}
- Tester: {$row['tester']}
- Received By: {$row['received_by']}
- Date Testing: {$row['date_testing']}
- Remark: {$row['remark']}

NEW DATA:
- Server Name: $server
- Serial Number: $serial
- Brand: $brand
- Machine Type: $machine
- Location: $location
- Status: $status
- Tester: $tester
- Received By: $receivedBy
- Date Testing: $date
- Remark: $remark

IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "UPDATE SERVER",
        $description
    );

    header("Location: server_inventory.php");
    exit();
    }
    }
}

$componentFormRows = [];
if(isset($_POST['update'])){
    $types = (array)($_POST['component_type'] ?? []);
    $others = (array)($_POST['component_other'] ?? []);
    $parts = (array)($_POST['component_part_number'] ?? []);
    $serials = (array)($_POST['component_serial_number'] ?? []);
    $count = max(count($types), count($others), count($parts), count($serials), 1);
    for($index = 0; $index < $count; $index++){
        $componentFormRows[] = ['type'=>$types[$index] ?? '', 'other'=>$others[$index] ?? '', 'part_number'=>$parts[$index] ?? '', 'serial_number'=>$serials[$index] ?? ''];
    }
} else {
    $labelToType = array_flip(serverEditComponentOptions());
    unset($labelToType['Other']);
    foreach($existingComponents as $component){
        $storedType = trim((string)($component['component_type'] ?? ''));
        $type = $labelToType[$storedType] ?? 'other';
        $componentFormRows[] = [
            'type'=>$type,
            'other'=>$type === 'other' ? $storedType : '',
            'part_number'=>$component['part_number'] ?? '',
            'serial_number'=>$component['serial_number'] ?? ''
        ];
    }
}
if(empty($componentFormRows)){ $componentFormRows[] = ['type'=>'', 'other'=>'', 'part_number'=>'', 'serial_number'=>'']; }
?>

<!DOCTYPE html>
<html>
<head>
<title><?= $canEdit ? 'Edit Server' : 'View Server' ?></title>

<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.server-component-row{border:1px solid #dee2e6;border-radius:8px;padding:12px;background:#fff}
.server-component-detail,.server-component-other{display:none}
.server-component-row.has-component .server-component-detail{display:block}
.server-component-row.has-other .server-component-other{display:block}
</style>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4"><?= $canEdit ? 'Edit Server' : 'View Server' ?></h2>

<?php if($error !== ""): ?>
<div class="alert alert-danger"><?= serverEditEscape($error) ?></div>
<?php endif; ?>

<form method="POST">

<div class="row">

<div class="col-md-6 mb-3">
<label>Server Name *</label>
<input type="text" name="server_name" class="form-control" value="<?= serverEditEscape($_POST['server_name'] ?? $row['server_name'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?> required>
</div>

<div class="col-md-6 mb-3">
<label>Serial Number *</label>
<input type="text" name="serial_number" class="form-control <?= $duplicateServerSerial ? 'is-invalid' : '' ?>" value="<?= serverEditEscape($_POST['serial_number'] ?? $row['serial_number'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?> required>
</div>

<div class="col-md-6 mb-3">
<label>Brand</label>
<input type="text" name="brand" class="form-control" value="<?= serverEditEscape($_POST['brand'] ?? $row['brand'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Machine Type</label>
<input type="text" name="machine_type" class="form-control" value="<?= serverEditEscape($_POST['machine_type'] ?? $row['machine_type'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Location</label>
<input type="text" name="location" class="form-control" value="<?= serverEditEscape($_POST['location'] ?? $row['location'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Status</label>
<input type="text" name="status" class="form-control" value="<?= serverEditEscape($_POST['status'] ?? $row['status'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Tester</label>
<input type="text" name="tester" class="form-control" value="<?= serverEditEscape($_POST['tester'] ?? $row['tester'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Date Testing</label>
<input type="date" name="date_testing" class="form-control" value="<?= serverEditEscape(appDateInputValue($_POST['date_testing'] ?? $row['date_testing'] ?? '')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-md-6 mb-3">
<label>Received By</label>
<input type="text" name="received_by" class="form-control" value="<?= serverEditEscape($_POST['received_by'] ?? $row['received_by'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
</div>

<div class="col-12 mb-3">
<label>Component</label>
<div id="serverComponentRows" class="d-flex flex-column gap-2">
<?php foreach($componentFormRows as $componentIndex => $componentRow): ?>
<div class="server-component-row">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Component</label><select name="component_type[]" class="form-select server-component-select" <?= $canEdit ? '' : 'disabled' ?>><option value="" <?= serverEditSelected($componentRow['type'], '') ?>>No Component</option><?php foreach(serverEditComponentOptions() as $value=>$label): ?><option value="<?= serverEditEscape($value) ?>" <?= serverEditSelected($componentRow['type'], $value) ?>><?= serverEditEscape($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 server-component-other"><label class="form-label">Other Component</label><input type="text" name="component_other[]" class="form-control server-component-other-input" value="<?= serverEditEscape($componentRow['other']) ?>" <?= $canEdit ? '' : 'readonly' ?>></div>
    <div class="col-md-3 server-component-detail"><label class="form-label">Part Number</label><input type="text" name="component_part_number[]" class="form-control server-component-part" value="<?= serverEditEscape($componentRow['part_number']) ?>" <?= $canEdit ? '' : 'readonly' ?>></div>
    <div class="col-md-3 server-component-detail"><label class="form-label">Serial Number</label><input type="text" name="component_serial_number[]" class="form-control server-component-serial <?= in_array((int)$componentIndex,$duplicateComponentRowIndexes,true)?'is-invalid':'' ?>" value="<?= serverEditEscape($componentRow['serial_number']) ?>" <?= $canEdit ? '' : 'readonly' ?>><?php if(in_array((int)$componentIndex,$duplicateComponentRowIndexes,true)): ?><div class="invalid-feedback">Duplicate component serial number.</div><?php endif; ?></div>
    <?php if($canEdit): ?><div class="col-md-2"><button type="button" class="btn btn-outline-danger w-100 server-component-remove">Remove</button></div><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php if($canEdit): ?><button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="addServerComponentBtn">Add Component</button><?php endif; ?>
</div>

<div class="col-md-12 mb-3">
<label>Remark</label>
<textarea name="remark" class="form-control" <?= $canEdit ? '' : 'readonly' ?>><?= serverEditEscape($_POST['remark'] ?? $row['remark'] ?? '') ?></textarea>
</div>

</div>

<?php if($canEdit): ?>
<button class="btn btn-warning" name="update">Update Server</button>
<?php else: ?>
<span class="badge bg-secondary">View Only</span>
<?php endif; ?>

<a href="server_inventory.php" class="btn btn-secondary">Cancel</a>

</form>

</div>

<script>
function createServerEditComponentRow(){
    return `<div class="server-component-row"><div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">Component</label><select name="component_type[]" class="form-select server-component-select"><option value="">No Component</option><option value="hard_disk">Hard Disk</option><option value="power_supply">Power Supply</option><option value="motherboard">Motherboard</option><option value="memory">Memory</option><option value="other">Other</option></select></div>
        <div class="col-md-3 server-component-other"><label class="form-label">Other Component</label><input type="text" name="component_other[]" class="form-control server-component-other-input"></div>
        <div class="col-md-3 server-component-detail"><label class="form-label">Part Number</label><input type="text" name="component_part_number[]" class="form-control server-component-part"></div>
        <div class="col-md-3 server-component-detail"><label class="form-label">Serial Number</label><input type="text" name="component_serial_number[]" class="form-control server-component-serial"></div>
        <div class="col-md-2"><button type="button" class="btn btn-outline-danger w-100 server-component-remove">Remove</button></div>
    </div></div>`;
}

function syncServerEditComponentRow(row){
    const select = row.querySelector(".server-component-select");
    const hasComponent = select && select.value !== "";
    const hasOther = select && select.value === "other";
    row.classList.toggle("has-component", !!hasComponent);
    row.classList.toggle("has-other", !!hasOther);
    row.querySelectorAll(".server-component-part,.server-component-serial").forEach(field => {
        field.required = !!hasComponent;
        if(!hasComponent){ field.value = ""; }
    });
    row.querySelectorAll(".server-component-other-input").forEach(field => {
        field.required = !!hasOther;
        if(!hasOther){ field.value = ""; }
    });
}

function refreshServerEditComponents(){
    document.querySelectorAll(".server-component-row").forEach(syncServerEditComponentRow);
}

document.addEventListener("change", event => {
    if(event.target.classList.contains("server-component-select")){ syncServerEditComponentRow(event.target.closest(".server-component-row")); }
});
document.addEventListener("input", event => {
    if(event.target.classList.contains("server-component-serial") || event.target.name === "serial_number"){
        event.target.classList.remove("is-invalid");
    }
});
document.addEventListener("click", event => {
    if(event.target.id === "addServerComponentBtn"){
        document.getElementById("serverComponentRows").insertAdjacentHTML("beforeend", createServerEditComponentRow());
        refreshServerEditComponents();
    }
    if(event.target.classList.contains("server-component-remove")){
        const rows = document.querySelectorAll(".server-component-row");
        const row = event.target.closest(".server-component-row");
        if(rows.length === 1){
            row.querySelectorAll("select,input").forEach(field => { field.value = ""; });
            syncServerEditComponentRow(row);
        } else { row.remove(); }
    }
});
refreshServerEditComponents();
</script>

</body>
</html>
