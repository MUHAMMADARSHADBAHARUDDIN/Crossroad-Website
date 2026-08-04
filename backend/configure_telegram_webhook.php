<?php
require_once __DIR__ . "/../includes/env.php";
require_once __DIR__ . "/../includes/telegram.php";

if(PHP_SAPI !== "cli"){
    http_response_code(404);
    exit();
}

$baseUrl = rtrim(trim((string)(getenv("CROSSROAD_BASE_URL") ?: "")), "/");
$secret = trim((string)(getenv("CROSSROAD_TELEGRAM_WEBHOOK_SECRET") ?: ""));
$token = crossroadTelegramBotToken();

if(!filter_var($baseUrl, FILTER_VALIDATE_URL) || stripos($baseUrl, "https://") !== 0){
    fwrite(STDERR, "CROSSROAD_BASE_URL must be the public HTTPS URL of this website.\n");
    exit(2);
}
if($token === "" || !preg_match('/^[A-Za-z0-9_-]{32,256}$/', $secret)){
    fwrite(STDERR, "Set a bot token and a random webhook secret of at least 32 characters.\n");
    exit(2);
}

$url = "https://api.telegram.org/bot{$token}/setWebhook";
$payload = http_build_query([
    "url" => $baseUrl . "/backend/telegram_webhook.php",
    "secret_token" => $secret,
    "allowed_updates" => json_encode(["message"]),
    "drop_pending_updates" => "true"
]);

$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"]
]);
$response = curl_exec($curl);
$httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);

if($response === false || $httpCode < 200 || $httpCode >= 300){
    fwrite(STDERR, ($error !== "" ? $error : (string)$response) . "\n");
    exit(3);
}

echo $response . PHP_EOL;
?>
