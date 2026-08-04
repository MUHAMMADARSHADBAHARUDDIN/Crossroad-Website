<?php
require_once __DIR__ . "/../includes/env.php";
require_once __DIR__ . "/../includes/db_connect.php";
require_once __DIR__ . "/../includes/planner_profiles.php";
require_once __DIR__ . "/../includes/telegram.php";
require_once __DIR__ . "/../includes/telegram_bot_updates.php";

header("Content-Type: application/json");

if($_SERVER["REQUEST_METHOD"] !== "POST"){
    http_response_code(405);
    echo json_encode(["ok" => false]);
    exit();
}

$configuredSecret = trim((string)(getenv("CROSSROAD_TELEGRAM_WEBHOOK_SECRET") ?: ""));
$providedSecret = trim((string)($_SERVER["HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN"] ?? ""));
if($configuredSecret === "" || !hash_equals($configuredSecret, $providedSecret)){
    http_response_code(403);
    echo json_encode(["ok" => false]);
    exit();
}

$update = json_decode((string)file_get_contents("php://input"), true);
crossroadHandleTelegramUpdate($mysqli, $update);

echo json_encode(["ok" => true]);
?>
