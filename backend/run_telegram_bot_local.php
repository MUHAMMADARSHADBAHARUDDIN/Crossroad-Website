<?php
require_once __DIR__ . "/../includes/env.php";
require_once __DIR__ . "/../includes/db_connect.php";
require_once __DIR__ . "/../includes/telegram.php";
require_once __DIR__ . "/../includes/telegram_bot_updates.php";

if(PHP_SAPI !== "cli"){
    http_response_code(404);
    exit();
}

$token = crossroadTelegramBotToken();
if($token === ""){
    fwrite(STDERR, "CROSSROAD_TELEGRAM_BOT_TOKEN is not configured.\n");
    exit(2);
}

function localTelegramApiRequest($method, $data = []){
    $url = "https://api.telegram.org/bot" . crossroadTelegramBotToken() . "/" . $method;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);

    if($response === false){
        return ["ok" => false, "description" => $error];
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ["ok" => false, "description" => "Invalid Telegram response."];
}

// Telegram supports either webhooks or polling, not both. Local development uses polling.
$deleteResult = localTelegramApiRequest("deleteWebhook", ["drop_pending_updates" => "false"]);
if(empty($deleteResult["ok"])){
    fwrite(STDERR, "Unable to switch the bot to local polling: " . ($deleteResult["description"] ?? "Unknown error") . "\n");
    exit(3);
}

echo "CSSB Planner Telegram bot is running locally. Press Ctrl+C to stop.\n";
$offset = 0;

while(true){
    $response = localTelegramApiRequest("getUpdates", [
        "offset" => $offset,
        "timeout" => 25,
        "allowed_updates" => json_encode(["message"])
    ]);

    if(empty($response["ok"])){
        fwrite(STDERR, "Telegram polling error: " . ($response["description"] ?? "Unknown error") . "\n");
        sleep(3);
        continue;
    }

    foreach(($response["result"] ?? []) as $update){
        $offset = max($offset, ((int)($update["update_id"] ?? 0)) + 1);
        crossroadHandleTelegramUpdate($mysqli, $update);
    }
}
?>
