<?php
require_once __DIR__ . "/mailer.php";
require_once __DIR__ . "/telegram.php";
require_once __DIR__ . "/planner_profiles.php";

if(!function_exists('crossroadNotificationEscape')){
    function crossroadNotificationEscape($value){
        return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
    }
}

if(!function_exists('crossroadSendContractNotification')){
    function crossroadSendContractNotification($email, $eventLabel, $contract, $task = null, $changes = []){
        $email = trim((string)$email);

        if($email === ""){
            return ["success" => true, "skipped" => true, "response" => "No email supplied."];
        }

        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            return ["success" => false, "skipped" => true, "response" => "Invalid email supplied."];
        }

        $projectName = trim((string)($contract['project_name'] ?? ""));
        $contractNo = trim((string)($contract['contract_no'] ?? ""));
        $projectCode = trim((string)($contract['project_code'] ?? ""));
        $subject = "Crossroad notification: " . $eventLabel;
        $rows = [
            "Project" => $projectName !== "" ? $projectName : "Not assigned",
            "Project code" => $projectCode !== "" ? $projectCode : "Not assigned",
            "Contract no." => $contractNo !== "" ? $contractNo : "Not assigned",
            "Contract creator" => trim((string)($contract['created_by'] ?? "")) ?: "Not assigned",
            "Project manager" => trim((string)($contract['project_manager'] ?? "")) ?: "Not assigned",
            "Start date" => trim((string)($contract['contract_start'] ?? "")) ?: "Not assigned",
            "End date" => trim((string)($contract['contract_end'] ?? "")) ?: "Not assigned"
        ];

        if(is_array($task)){
            $rows["Task"] = trim((string)($task['task_text'] ?? "")) ?: "Not assigned";
            $rows["Task start"] = trim((string)($task['task_start_date'] ?? "")) ?: "Not assigned";
            $rows["Task end"] = trim((string)($task['task_end_date'] ?? "")) ?: "Not assigned";
            $rows["Remark"] = trim((string)($task['remark'] ?? "")) ?: "Not assigned";
        }

        if(is_array($changes) && $changes){
            foreach($changes as $label => $change){
                if(!is_array($change)){
                    continue;
                }

                $oldValue = trim((string)($change['old'] ?? ""));
                $newValue = trim((string)($change['new'] ?? ""));
                $rows["Changed: " . $label] = ($oldValue !== "" ? $oldValue : "Not assigned") . " → " . ($newValue !== "" ? $newValue : "Not assigned");
            }
        }

        $tableRows = "";
        $textRows = "";
        foreach($rows as $label => $value){
            $tableRows .= "<tr><th align=\"left\" style=\"width:35%;padding:10px 12px;border:1px solid #d8dee8;background:#f5f7fa;vertical-align:top\">" . crossroadNotificationEscape($label) . "</th><td style=\"padding:10px 12px;border:1px solid #d8dee8;vertical-align:top\">" . crossroadNotificationEscape($value) . "</td></tr>";
            $textRows .= $label . ": " . $value . "\n";
        }

        $html = "<div style=\"font-family:Arial,sans-serif;color:#20252b;max-width:760px\"><p>Hello,</p><p>This is a notification from the Crossroad System: <strong>" . crossroadNotificationEscape($eventLabel) . "</strong>.</p><table role=\"presentation\" style=\"width:100%;border-collapse:collapse;border:1px solid #d8dee8\"><thead><tr><th align=\"left\" style=\"padding:10px 12px;border:1px solid #d8dee8;background:#173866;color:#fff\">Field</th><th align=\"left\" style=\"padding:10px 12px;border:1px solid #d8dee8;background:#173866;color:#fff\">Details</th></tr></thead><tbody>" . $tableRows . "</tbody></table><p>Please log in to the system for full details.</p><p style=\"color:#6b7280;font-size:12px\">This notification was sent automatically by Crossroad System.</p></div>";
        $text = "Hello,\n\nThis is a notification from the Crossroad System: " . $eventLabel . ".\n\n" . $textRows . "\nPlease log in to the system for full details.";

        return crossroadSendEmail($email, "", $subject, $html, $text);
    }
}

if(!function_exists('crossroadContractTelegramChatIds')){
    function crossroadContractTelegramChatIds($mysqli, $identifiers){
        ensurePlannerProfileSchema($mysqli);
        $chatIds = [];

        foreach(array_values(array_unique(array_filter(array_map('trim', (array)$identifiers)))) as $identifier){
            $stmt = $mysqli->prepare("
                SELECT telegram_chat_id
                FROM planner_user_profiles
                WHERE telegram_chat_id <> ''
                  AND (LOWER(TRIM(username)) = LOWER(TRIM(?)) OR LOWER(TRIM(planner_name)) = LOWER(TRIM(?)))
            ");

            if(!$stmt){
                continue;
            }

            $stmt->bind_param("ss", $identifier, $identifier);
            $stmt->execute();
            $result = $stmt->get_result();

            while($result && $row = $result->fetch_assoc()){
                $chatId = trim((string)($row['telegram_chat_id'] ?? ""));
                if($chatId !== ""){
                    $chatIds[$chatId] = $chatId;
                }
            }
        }

        return array_values($chatIds);
    }
}

if(!function_exists('crossroadContractTelegramText')){
    function crossroadContractTelegramText($eventLabel, $contract, $changes = []){
        $lines = [
            "<b>" . htmlspecialchars((string)$eventLabel, ENT_QUOTES, 'UTF-8') . "</b>",
            "",
            "<b>Project:</b> " . htmlspecialchars(trim((string)($contract['project_name'] ?? "")) ?: "Not assigned", ENT_QUOTES, 'UTF-8'),
            "<b>Project code:</b> " . htmlspecialchars(trim((string)($contract['project_code'] ?? "")) ?: "Not assigned", ENT_QUOTES, 'UTF-8'),
            "<b>Contract no.:</b> " . htmlspecialchars(trim((string)($contract['contract_no'] ?? "")) ?: "Not assigned", ENT_QUOTES, 'UTF-8'),
            "<b>Creator:</b> " . htmlspecialchars(trim((string)($contract['created_by'] ?? "")) ?: "Not assigned", ENT_QUOTES, 'UTF-8'),
            "<b>Project manager:</b> " . htmlspecialchars(trim((string)($contract['project_manager'] ?? "")) ?: "Not assigned", ENT_QUOTES, 'UTF-8'),
            "<b>Dates:</b> " . htmlspecialchars((trim((string)($contract['contract_start'] ?? "")) ?: "Not assigned") . " to " . (trim((string)($contract['contract_end'] ?? "")) ?: "Not assigned"), ENT_QUOTES, 'UTF-8')
        ];

        if($changes){
            $lines[] = "";
            $lines[] = "<b>Changes</b>";
            foreach($changes as $label => $change){
                if(!is_array($change)){ continue; }
                $oldValue = trim((string)($change['old'] ?? "")) ?: "Not assigned";
                $newValue = trim((string)($change['new'] ?? "")) ?: "Not assigned";
                $lines[] = "• <b>" . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . ":</b> " . htmlspecialchars($oldValue . " → " . $newValue, ENT_QUOTES, 'UTF-8');
            }
        }

        $message = implode("\n", $lines);
        return function_exists('mb_substr') ? mb_substr($message, 0, 3900, 'UTF-8') : substr($message, 0, 3900);
    }
}

if(!function_exists('crossroadContractRecipientEmails')){
    function crossroadContractRecipientEmails($mysqli, $usernames){
        $usernames = array_values(array_unique(array_filter(array_map('trim', (array)$usernames))));
        $emails = [];

        foreach($usernames as $username){
            foreach(["administrator", "user", "system_admin"] as $tableName){
                $tableNameEscaped = $mysqli->real_escape_string($tableName);
                $tableResult = $mysqli->query("SHOW TABLES LIKE '$tableNameEscaped'");

                if(!$tableResult || $tableResult->num_rows <= 0){
                    continue;
                }

                $stmt = $mysqli->prepare("SELECT email FROM `$tableName` WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1");
                if(!$stmt){
                    continue;
                }

                $stmt->bind_param("s", $username);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $email = strtolower(trim((string)($row['email'] ?? "")));

                if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                    $emails[$email] = $email;
                }
            }
        }

        return array_values($emails);
    }
}

if(!function_exists('crossroadNotifyContractRecipients')){
    function crossroadNotifyContractRecipients($mysqli, $usernames, $eventLabel, $contract, $changes = []){
        $results = ["email" => [], "telegram" => []];

        foreach(crossroadContractRecipientEmails($mysqli, $usernames) as $email){
            $results["email"][$email] = crossroadSendContractNotification($email, $eventLabel, $contract, null, $changes);
        }

        $telegramText = crossroadContractTelegramText($eventLabel, $contract, $changes);
        foreach(crossroadContractTelegramChatIds($mysqli, $usernames) as $chatId){
            $results["telegram"][$chatId] = crossroadSendTelegramMessage($chatId, $telegramText, ["parse_mode" => "HTML"]);
        }

        return $results;
    }
}
?>
