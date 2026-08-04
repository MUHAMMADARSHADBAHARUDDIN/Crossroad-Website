<?php
require_once __DIR__ . "/env.php";

if(!function_exists('crossroadRealtimeChannelForAction')){
    function crossroadRealtimeChannelForAction($action){
        $action = strtoupper(trim((string)$action));
        if($action === "" || strpos($action, "LOGIN") !== false || strpos($action, "LOGOUT") !== false || strpos($action, "REPORT") !== false || strpos($action, "EXPORT") !== false || strpos($action, "PRINT") !== false){
            return "";
        }
        if(strpos($action, "PLANNER") !== false){ return "planner"; }
        if(strpos($action, "OFFICE INVENTORY") !== false || strpos($action, "OFFICE LICENSE") !== false){ return "office_inventory"; }
        if(strpos($action, "CONTRACT") !== false || strpos($action, "PROJECT") !== false){ return "contracts"; }
        if(strpos($action, "SERVER") !== false){ return "server_inventory"; }
        if(strpos($action, "ASSET") !== false || strpos($action, "STOCK OUT") !== false){ return "asset_inventory"; }
        if(strpos($action, "USER") !== false || strpos($action, "PASSWORD") !== false){ return "users"; }
        if(strpos($action, "VISITOR") !== false){ return "visitors"; }
        if(strpos($action, "BULLETIN") !== false || strpos($action, "STANDBY") !== false){ return "bulletin"; }
        if(strpos($action, "ACTIVITY") !== false || strpos($action, "LOG") !== false){ return "tracking"; }
        return "";
    }
}

if(!function_exists('crossroadRealtimePublish')){
    function crossroadRealtimePublish($channel, $action = "updated"){
        $url = trim((string)(getenv("CROSSROAD_REALTIME_PUBLISH_URL") ?: ""));
        $secret = trim((string)(getenv("CROSSROAD_REALTIME_SECRET") ?: ""));
        $channel = trim((string)$channel);
        if($url === "" || $secret === "" || $channel === ""){ return false; }

        $payload = json_encode(["channel" => $channel, "action" => (string)$action]);
        if(function_exists('curl_init')){
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 150,
                CURLOPT_TIMEOUT_MS => 400,
                CURLOPT_HTTPHEADER => ["Content-Type: application/json", "X-Realtime-Secret: " . $secret]
            ]);
            curl_exec($curl);
            $success = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200;
            curl_close($curl);
            return $success;
        }

        $context = stream_context_create(["http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\nX-Realtime-Secret: " . $secret . "\r\n",
            "content" => $payload,
            "timeout" => 0.4,
            "ignore_errors" => true
        ]]);
        return @file_get_contents($url, false, $context) !== false;
    }
}
?>
