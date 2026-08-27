<?php
require_once '../includes/security.php';
startSecureSession();
require_once '../includes/db_connect.php';
require_once '../includes/permissions.php';
require_once '../includes/receiving_schema.php';
require_once '../includes/receiving_attachments.php';
require_once '../includes/activity_log.php';

if(!isset($_SESSION['username']) || !hasPermission($mysqli, 'receiving_delete')){
    http_response_code(403);
    exit('Access denied.');
}
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    http_response_code(405);
    exit('Method not allowed.');
}

ensureReceivingSchema($mysqli);
$id = (int)($_POST['id'] ?? 0);
$stmt = $mysqli->prepare('SELECT item_name, attachment_file_name FROM receiving_records WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();

if(!$record){
    header('Location: item_receive.php?missing=1');
    exit;
}

$deleteStmt = $mysqli->prepare('DELETE FROM receiving_records WHERE id = ?');
$deleteStmt->bind_param('i', $id);
if(!$deleteStmt->execute()){
    http_response_code(500);
    exit('Unable to delete the item receive record.');
}

receivingDeleteAttachment($record['attachment_file_name'] ?? '');
logActivity(
    $mysqli,
    (string)$_SESSION['username'],
    (string)($_SESSION['role'] ?? 'UNKNOWN'),
    'ITEM RECEIVE DELETE',
    'Deleted received item: ' . (string)$record['item_name']
);
header('Location: item_receive.php?deleted=1');
exit;
