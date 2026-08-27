<?php
require_once '../includes/security.php';
startSecureSession(false);
require_once '../includes/db_connect.php';
require_once '../includes/permissions.php';
require_once '../includes/receiving_schema.php';
require_once '../includes/receiving_attachments.php';

if(!isset($_SESSION['username']) || !hasPermission($mysqli, 'receiving_view')){
    http_response_code(403);
    exit('Access denied.');
}

ensureReceivingSchema($mysqli);
$id = (int)($_GET['id'] ?? 0);
$stmt = $mysqli->prepare('SELECT attachment_file_name, attachment_original_name, attachment_mime FROM receiving_records WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();

if(!$record){
    http_response_code(404);
    exit('Attachment record was not found.');
}

$path = receivingAttachmentPath($record['attachment_file_name'] ?? '');
if($path === '' || !is_file($path) || !is_readable($path)){
    http_response_code(404);
    exit('The attachment file is missing from the server.');
}

$mime = trim((string)($record['attachment_mime'] ?? ''));
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if($mime === ''){
    $mime = receivingAttachmentMimeMap()[$extension] ?? 'application/octet-stream';
}

$isImage = strpos($mime, 'image/') === 0;
$disposition = $isImage ? 'inline' : 'attachment';
$originalName = basename(trim((string)($record['attachment_original_name'] ?? '')));
if($originalName === ''){ $originalName = basename($path); }
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

