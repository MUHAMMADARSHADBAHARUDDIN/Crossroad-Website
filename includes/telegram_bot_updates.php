<?php
require_once __DIR__ . "/planner_profiles.php";
require_once __DIR__ . "/telegram.php";

if(!function_exists('crossroadHandleTelegramUpdate')){
    function crossroadHandleTelegramUpdate($mysqli, $update){
        $message = is_array($update) ? ($update["message"] ?? null) : null;
        $chatId = trim((string)($message["chat"]["id"] ?? ""));
        $chatType = trim((string)($message["chat"]["type"] ?? ""));
        $text = trim((string)($message["text"] ?? ""));

        if($chatId === "" || $chatType !== "private"){
            return;
        }

        if(preg_match('/^\/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]{32,64}))?$/', $text, $match)){
            $token = trim((string)($match[1] ?? ""));

            if($token === ""){
                crossroadSendTelegramMessage(
                    $chatId,
                    "👋 <b>Welcome to CSSB Planner</b>\n\n"
                    . "Your reminder assistant is ready.\n\n"
                    . "To activate notifications:\n"
                    . "1️⃣ Sign in to Crossroad System\n"
                    . "2️⃣ Open <b>Telegram Notifications</b>\n"
                    . "3️⃣ Tap <b>Connect Telegram</b>",
                    ["parse_mode" => "HTML"]
                );
                return;
            }

            $profile = plannerConsumeTelegramLinkToken($mysqli, $token, $chatId);
            if($profile){
                $name = trim((string)($profile["planner_name"] ?? $profile["username"] ?? "User"));
                crossroadSendTelegramMessage(
                    $chatId,
                    "✅ <b>Notifications activated</b>\n\n"
                    . "You're all set, <b>" . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "</b>!\n"
                    . "We’ll remind you here whenever you’re selected as the PIC.\n\n"
                    . "💡 Send /status anytime to check your connection.",
                    ["parse_mode" => "HTML"]
                );
            } else {
                crossroadSendTelegramMessage(
                    $chatId,
                    "⏳ <b>Link expired</b>\n\n"
                    . "For your security, connection links only work once and expire after 15 minutes.\n\n"
                    . "Return to Crossroad System and tap <b>Connect Telegram</b> again.",
                    ["parse_mode" => "HTML"]
                );
            }
            return;
        }

        if($text === "/status"){
            $stmt = $mysqli->prepare("
                SELECT planner_name
                FROM planner_user_profiles
                WHERE telegram_chat_id = ?
                LIMIT 1
            ");
            if($stmt){
                $stmt->bind_param("s", $chatId);
                $stmt->execute();
                $result = $stmt->get_result();
                $profile = $result ? $result->fetch_assoc() : null;
            } else {
                $profile = null;
            }

            if($profile){
                $name = trim((string)($profile["planner_name"] ?? "User"));
                crossroadSendTelegramMessage(
                    $chatId,
                    "🟢 <b>Connection active</b>\n\n"
                    . "Account: <b>" . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "</b>\n"
                    . "Planner reminders are enabled.",
                    ["parse_mode" => "HTML"]
                );
            } else {
                crossroadSendTelegramMessage(
                    $chatId,
                    "🔴 <b>Not connected</b>\n\n"
                    . "Open <b>Telegram Notifications</b> in Crossroad System and tap <b>Connect Telegram</b>.",
                    ["parse_mode" => "HTML"]
                );
            }
        }
    }
}
?>
