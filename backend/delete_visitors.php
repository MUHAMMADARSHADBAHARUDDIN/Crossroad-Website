<?php
require_once '../includes/security.php';
startSecureSession();
require_once '../includes/db_connect.php';
require_once '../includes/permissions.php';
require_once '../includes/visitor_schema.php';
require_once '../includes/realtime.php';
if(!isset($_SESSION['username'])){ http_response_code(401); die('No session.'); }
if(!hasPermission($mysqli, 'visitor_delete')){ http_response_code(403); die('Access denied.'); }
if($_SERVER['REQUEST_METHOD'] !== 'POST'){ http_response_code(405); die('Method not allowed.'); }
ensureVisitorSchema($mysqli);
$ids = isset($_POST['visitor_ids']) && is_array($_POST['visitor_ids']) ? $_POST['visitor_ids'] : [];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($id){ return $id > 0; })));
if(empty($ids)){ header('Location: ../frontend/visitors.php'); exit; }
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $mysqli->prepare("DELETE FROM visitors WHERE id IN ($placeholders)");
if(!$stmt){ http_response_code(500); die('Unable to delete records.'); }
$types = str_repeat('i', count($ids));
$params = [$types];
foreach($ids as $key => $id){ $params[] = &$ids[$key]; }
call_user_func_array([$stmt, 'bind_param'], $params);
$stmt->execute();
crossroadRealtimePublish('visitors', 'DELETE VISITORS');
header('Location: ../frontend/visitors.php');
exit;
