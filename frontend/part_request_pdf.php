<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/receiving_schema.php";
require_once "../includes/part_request_pdf.php";
if(!isset($_SESSION['username']) || !hasPermission($mysqli, 'part_request_view')){ die('Access denied'); }
ensurePartRequestSchema($mysqli);
$id = (int)($_GET['id'] ?? 0);
$stmt = $mysqli->prepare('SELECT * FROM part_requests WHERE id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
if(!$request){ die('Request not found'); }
$items = partRequestItems($mysqli, $request);
$pdf = buildPartRequestPdf($request, $items);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $request['request_id'] . '.pdf"');
echo $pdf;
exit;
