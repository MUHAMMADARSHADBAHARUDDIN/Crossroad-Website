<?php
require_once '../includes/security.php';
startSecureSession();
require_once '../includes/db_connect.php';
require_once '../includes/permissions.php';
require_once '../includes/receiving_schema.php';
require_once '../includes/receiving_attachments.php';
require_once '../includes/activity_log.php';
require_once '../includes/date_helpers.php';

if(!isset($_SESSION['username'])){ header('Location: index.html'); exit; }
if(!hasPermission($mysqli, 'receiving_edit')){ http_response_code(403); die('Access denied.'); }
ensureReceivingSchema($mysqli);

function eriEscape($value){ return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$recordStmt = $mysqli->prepare('SELECT * FROM receiving_records WHERE id = ? LIMIT 1');
$recordStmt->bind_param('i', $id);
$recordStmt->execute();
$record = $recordStmt->get_result()->fetch_assoc();
if(!$record){ http_response_code(404); die('Item receive record was not found.'); }

$error = '';
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $date = appNormalizeDateInput($_POST['received_date'] ?? '');
    $by = trim((string)($_POST['received_by'] ?? ''));
    $type = trim((string)($_POST['item_type'] ?? ''));
    $item = trim((string)($_POST['item_name'] ?? ''));
    $part = trim((string)($_POST['part_number'] ?? ''));
    $serial = trim((string)($_POST['serial_number'] ?? ''));
    $brand = trim((string)($_POST['brand'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $rack = trim((string)($_POST['rack_location'] ?? ''));
    $remark = trim((string)($_POST['remark'] ?? ''));

    if(!$date || $by === '' || $type === '' || $item === '' || $rack === ''){
        $error = 'Received date, received by, item type, item name and rack/location are required.';
    }

    $storedName = (string)($record['attachment_file_name'] ?? '');
    $originalName = (string)($record['attachment_original_name'] ?? '');
    $mime = (string)($record['attachment_mime'] ?? '');
    $size = (int)($record['attachment_size'] ?? 0);
    $oldStoredName = $storedName;
    $newStoredName = '';
    $removeAttachment = !empty($_POST['remove_attachment']);

    if($error === '' && isset($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE){
        $upload = receivingStoreAttachment($_FILES['attachment']);
        if(!$upload['success']){
            $error = $upload['error'];
        } else {
            $storedName = $newStoredName = $upload['stored_name'];
            $originalName = $upload['original_name'];
            $mime = $upload['mime'];
            $size = (int)$upload['size'];
            $removeAttachment = false;
        }
    }

    if($removeAttachment){
        $storedName = $originalName = $mime = '';
        $size = 0;
    }

    if($error === ''){
        $updateStmt = $mysqli->prepare('
            UPDATE receiving_records
            SET received_date = ?, received_by = ?, item_type = ?, item_name = ?,
                part_number = ?, serial_number = ?, brand = ?, description = ?,
                quantity = ?, rack_location = ?, remark = ?, attachment_file_name = ?,
                attachment_original_name = ?, attachment_mime = ?, attachment_size = ?
            WHERE id = ?
        ');
        $updateStmt->bind_param(
            'ssssssssisssssii',
            $date, $by, $type, $item, $part, $serial, $brand, $description,
            $quantity, $rack, $remark, $storedName, $originalName, $mime, $size, $id
        );

        if($updateStmt->execute()){
            if(($newStoredName !== '' || $removeAttachment) && $oldStoredName !== '' && $oldStoredName !== $storedName){
                receivingDeleteAttachment($oldStoredName);
            }
            logActivity(
                $mysqli,
                (string)$_SESSION['username'],
                (string)($_SESSION['role'] ?? 'UNKNOWN'),
                'ITEM RECEIVE EDIT',
                'Updated received item: ' . $item . '; rack: ' . $rack
            );
            header('Location: item_receive.php?updated=1');
            exit;
        }

        if($newStoredName !== ''){ receivingDeleteAttachment($newStoredName); }
        $error = 'Unable to update item: ' . $updateStmt->error;
    }

    $record = array_merge($record, [
        'received_date' => $date ?: ($_POST['received_date'] ?? ''),
        'received_by' => $by,
        'item_type' => $type,
        'item_name' => $item,
        'part_number' => $part,
        'serial_number' => $serial,
        'brand' => $brand,
        'description' => $description,
        'quantity' => $quantity,
        'rack_location' => $rack,
        'remark' => $remark
    ]);
}
?>
<!doctype html>
<html>
<head>
    <title>Edit Received Item</title>
    <link rel="icon" type="image/png" href="../image/logo.png">
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>.receive-card{height:auto!important;display:block!important;cursor:default!important;border:1px solid #d8dee8!important;box-shadow:0 6px 20px rgba(0,0,0,.08)}.form-label{font-weight:600}.form-control,.form-select{min-height:42px}</style>
</head>
<body>
<?php include 'layout/header.php'; include 'layout/sidebar.php'; ?>
<main class="main">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h2>Edit Received Item</h2><div class="text-muted">Update the received item and its attachment.</div></div>
        <a href="item_receive.php" class="btn btn-outline-secondary"><i class="fa fa-arrow-left"></i> Back</a>
    </div>
    <?php if($error !== ''): ?><div class="alert alert-danger"><?= eriEscape($error) ?></div><?php endif; ?>
    <div class="card receive-card"><div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
            <?= csrfTokenField() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="104857600">
            <div class="row">
                <div class="col-md-4 mb-3"><label class="form-label">Received Date *</label><input type="date" name="received_date" class="form-control" value="<?= eriEscape($record['received_date']) ?>" required></div>
                <div class="col-md-4 mb-3"><label class="form-label">Received By *</label><input name="received_by" class="form-control" value="<?= eriEscape($record['received_by']) ?>" required></div>
                <div class="col-md-4 mb-3"><label class="form-label">Item Type *</label><select name="item_type" class="form-select" required><option value="">Select type</option><?php foreach(['Laptop','Part / Component','Other Item'] as $option): ?><option value="<?= eriEscape($option) ?>" <?= $record['item_type'] === $option ? 'selected' : '' ?>><?= eriEscape($option) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 mb-3"><label class="form-label">Item Name / Model *</label><input name="item_name" class="form-control" value="<?= eriEscape($record['item_name']) ?>" required></div>
                <div class="col-md-4 mb-3"><label class="form-label">Part Number</label><input name="part_number" class="form-control" value="<?= eriEscape($record['part_number']) ?>"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Serial / Asset ID</label><input name="serial_number" class="form-control" value="<?= eriEscape($record['serial_number']) ?>"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Brand</label><input name="brand" class="form-control" value="<?= eriEscape($record['brand']) ?>"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Quantity *</label><input type="number" min="1" name="quantity" value="<?= (int)$record['quantity'] ?>" class="form-control" required></div>
                <div class="col-md-4 mb-3"><label class="form-label">Rack / Location *</label><input name="rack_location" class="form-control" value="<?= eriEscape($record['rack_location']) ?>" required></div>
                <div class="col-12 mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?= eriEscape($record['description']) ?></textarea></div>
                <div class="col-12 mb-3">
                    <label class="form-label">Replace Attachment (optional, max 100 MB)</label>
                    <input type="file" name="attachment" class="form-control" accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                    <?php if(!empty($record['attachment_file_name'])): ?>
                        <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                            <a href="receive_attachment.php?id=<?= $id ?>" target="_blank"><i class="fa fa-paperclip"></i> <?= eriEscape($record['attachment_original_name'] ?: 'Current attachment') ?></a>
                            <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remove_attachment" value="1"> <span class="form-check-label text-danger">Remove current attachment</span></label>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-12 mb-3"><label class="form-label">Remark</label><textarea name="remark" class="form-control" rows="3"><?= eriEscape($record['remark']) ?></textarea></div>
            </div>
            <button class="btn btn-warning"><i class="fa fa-save"></i> Save Changes</button>
        </form>
    </div></div>
</main>
<?php include 'layout/footer.php'; ?>
</body>
</html>
