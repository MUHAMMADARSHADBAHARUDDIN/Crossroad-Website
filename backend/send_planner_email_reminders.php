<?php
require_once __DIR__ . "/../includes/env.php";
require_once __DIR__ . "/../includes/db_connect.php";
require_once __DIR__ . "/../includes/planner_schema.php";
require_once __DIR__ . "/../includes/planner_profiles.php";
require_once __DIR__ . "/../includes/mailer.php";

date_default_timezone_set("Asia/Kuala_Lumpur");
ensurePlannerSchema($mysqli);
ensurePlannerProfileSchema($mysqli);

function plannerEmailReminderFail($message, $httpStatus = 500, $exitCode = 1){
    if(PHP_SAPI !== "cli"){
        http_response_code($httpStatus);
    }

    header("Content-Type: application/json");
    echo json_encode([
        "success" => false,
        "error" => (string)$message,
        "checked_at" => (new DateTimeImmutable("now", new DateTimeZone("Asia/Kuala_Lumpur")))->format(DATE_ATOM)
    ], JSON_UNESCAPED_SLASHES);

    if(PHP_SAPI === "cli"){
        echo PHP_EOL;
    }

    exit((int)$exitCode);
}

if(PHP_SAPI !== "cli"){
    $configuredKey = trim((string)(getenv("CROSSROAD_PLANNER_CRON_KEY") ?: ""));
    $providedKey = trim((string)($_GET['key'] ?? ""));

    if($configuredKey === "" || !hash_equals($configuredKey, $providedKey)){
        plannerEmailReminderFail("Access denied", 403, 3);
    }
}

$missingMailSettings = crossroadMailMissingSettings();

if($missingMailSettings){
    plannerEmailReminderFail(
        "Email is not configured. Missing: " . implode(", ", $missingMailSettings),
        503,
        2
    );
}

$dryRun = PHP_SAPI === "cli" && in_array("--dry-run", $argv ?? [], true);

function plannerReminderPersonValues($value){
    $items = is_array($value) ? $value : preg_split('/[,\r\n]+/', (string)$value);
    $people = [];

    foreach($items as $item){
        $item = trim((string)$item);

        if($item !== ""){
            $people[strtolower($item)] = $item;
        }
    }

    return array_values($people);
}

function plannerReminderEmailContent($recipientName, $task, $taskStartsAt, $leadText){
    $recipientName = trim((string)$recipientName);
    $recipientName = $recipientName !== "" ? $recipientName : "Team Member";
    $title = trim((string)($task['title'] ?? "Planner Task"));
    $description = trim((string)($task['description'] ?? ""));
    $description = $description !== "" ? $description : "Not provided";
    $dateText = $taskStartsAt->format("d/m/Y");
    $timeText = strtolower($taskStartsAt->format("g:i A"));
    $subject = "CSSB Planner reminder: " . $title;
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

    $html = '<div style="font-family:Arial,sans-serif;line-height:1.55;color:#202124;max-width:620px">'
        . '<p>Dear ' . $escape($recipientName) . ',</p>'
        . '<p>This is an automated reminder for your upcoming CSSB Planner task.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:560px">'
        . '<tr><td style="padding:8px;border:1px solid #dfe3e8;font-weight:bold;width:130px">Task</td><td style="padding:8px;border:1px solid #dfe3e8">' . $escape($title) . '</td></tr>'
        . '<tr><td style="padding:8px;border:1px solid #dfe3e8;font-weight:bold">Description</td><td style="padding:8px;border:1px solid #dfe3e8">' . nl2br($escape($description)) . '</td></tr>'
        . '<tr><td style="padding:8px;border:1px solid #dfe3e8;font-weight:bold">Date</td><td style="padding:8px;border:1px solid #dfe3e8">' . $escape($dateText) . '</td></tr>'
        . '<tr><td style="padding:8px;border:1px solid #dfe3e8;font-weight:bold">Time</td><td style="padding:8px;border:1px solid #dfe3e8">' . $escape($timeText) . '</td></tr>'
        . '<tr><td style="padding:8px;border:1px solid #dfe3e8;font-weight:bold">Reminder</td><td style="padding:8px;border:1px solid #dfe3e8">' . $escape($leadText) . ' before the task</td></tr>'
        . '</table>'
        . '<p style="color:#6b7280;font-size:13px">This email was sent automatically by Crossroad System.</p>'
        . '</div>';

    $text = "Dear {$recipientName},\n\n"
        . "This is an automated reminder for your upcoming CSSB Planner task.\n\n"
        . "Task: {$title}\n"
        . "Description: {$description}\n"
        . "Date: {$dateText}\n"
        . "Time: {$timeText}\n"
        . "Reminder: {$leadText} before the task\n\n"
        . "This email was sent automatically by Crossroad System.";

    return [
        "subject" => $subject,
        "html" => $html,
        "text" => $text
    ];
}

$now = new DateTimeImmutable("now", new DateTimeZone("Asia/Kuala_Lumpur"));
$windowEnd = $now->modify("+1 day");
$windowStartSql = $now->format("Y-m-d H:i:s");
$windowEndSql = $windowEnd->format("Y-m-d H:i:s");
$taskStmt = $mysqli->prepare("
    SELECT id, title, description, person_in_charge, start_date, task_time
    FROM planner_tasks
    WHERE task_time IS NOT NULL
      AND TIMESTAMP(start_date, task_time) > ?
      AND TIMESTAMP(start_date, task_time) <= ?
    ORDER BY start_date ASC, task_time ASC, id ASC
");

if(!$taskStmt){
    plannerEmailReminderFail("Unable to prepare planner task query: " . $mysqli->error, 500, 2);
}

$taskStmt->bind_param("ss", $windowStartSql, $windowEndSql);

if(!$taskStmt->execute()){
    plannerEmailReminderFail("Unable to load planner tasks: " . $taskStmt->error, 500, 2);
}

$taskResult = $taskStmt->get_result();
$eligible = 0;
$sent = 0;
$failed = 0;
$skipped = 0;
$missingEmail = 0;

while($task = $taskResult->fetch_assoc()){
    $taskStartsAt = DateTimeImmutable::createFromFormat(
        "!Y-m-d H:i:s",
        $task['start_date'] . " " . $task['task_time'],
        new DateTimeZone("Asia/Kuala_Lumpur")
    );

    if(!$taskStartsAt){
        $skipped++;
        continue;
    }

    $secondsUntilTask = $taskStartsAt->getTimestamp() - $now->getTimestamp();
    $reminderType = $secondsUntilTask <= (3 * 3600) ? "three_hours" : "one_day";
    $leadText = $reminderType === "three_hours" ? "3 hours" : "1 day";
    $scheduledFor = $taskStartsAt->modify($reminderType === "three_hours" ? "-3 hours" : "-1 day");
    $scheduledForSql = $scheduledFor->format("Y-m-d H:i:s");
    $taskId = (int)$task['id'];

    foreach(plannerReminderPersonValues($task['person_in_charge'] ?? "") as $plannerName){
        $recipients = plannerGetEmailRecipientsByPlannerName($mysqli, $plannerName);

        if(!$recipients){
            $missingEmail++;
            continue;
        }

        foreach($recipients as $recipient){
            $recipientEmail = strtolower(trim((string)($recipient['email'] ?? "")));
            $recipientName = trim((string)($recipient['planner_name'] ?? $plannerName));
            $logStmt = $mysqli->prepare("
                SELECT id, status, attempts
                FROM planner_email_reminders
                WHERE task_id = ? AND recipient_email = ? AND reminder_type = ?
                LIMIT 1
            ");
            $logStmt?->bind_param("iss", $taskId, $recipientEmail, $reminderType);
            $logStmt?->execute();
            $log = $logStmt?->get_result()->fetch_assoc();

            if($log && ($log['status'] === "sent" || (int)$log['attempts'] >= 5)){
                $skipped++;
                continue;
            }

            $eligible++;

            if($dryRun){
                continue;
            }

            if(!$log){
                $insertStmt = $mysqli->prepare("
                    INSERT IGNORE INTO planner_email_reminders
                        (task_id, planner_name, recipient_email, reminder_type, scheduled_for)
                    VALUES (?, ?, ?, ?, ?)
                ");

                if(!$insertStmt){
                    $failed++;
                    continue;
                }

                $insertStmt->bind_param("issss", $taskId, $plannerName, $recipientEmail, $reminderType, $scheduledForSql);
                $insertStmt->execute();

                $logStmt = $mysqli->prepare("
                    SELECT id, status, attempts
                    FROM planner_email_reminders
                    WHERE task_id = ? AND recipient_email = ? AND reminder_type = ?
                    LIMIT 1
                ");
                $logStmt?->bind_param("iss", $taskId, $recipientEmail, $reminderType);
                $logStmt?->execute();
                $log = $logStmt?->get_result()->fetch_assoc();
            }

            if(!$log){
                $failed++;
                continue;
            }

            $content = plannerReminderEmailContent($recipientName, $task, $taskStartsAt, $leadText);
            $sendResult = crossroadSendEmail(
                $recipientEmail,
                $recipientName,
                $content['subject'],
                $content['html'],
                $content['text']
            );
            $status = $sendResult['success'] ? "sent" : "failed";
            $providerResponse = substr((string)$sendResult['response'], 0, 60000);
            $reminderId = (int)$log['id'];
            $updateStmt = $mysqli->prepare("
                UPDATE planner_email_reminders
                SET status = ?,
                    attempts = attempts + 1,
                    provider_response = ?,
                    sent_at = IF(? = 'sent', NOW(), sent_at),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt?->bind_param("sssi", $status, $providerResponse, $status, $reminderId);
            $updateStmt?->execute();

            if($sendResult['success']){
                $sent++;
            }
            else{
                $failed++;
            }
        }
    }
}

header("Content-Type: application/json");
echo json_encode([
    "success" => $failed === 0,
    "dry_run" => $dryRun,
    "eligible" => $eligible,
    "sent" => $sent,
    "failed" => $failed,
    "skipped" => $skipped,
    "missing_email" => $missingEmail,
    "checked_at" => $now->format(DATE_ATOM)
], JSON_UNESCAPED_SLASHES);

if(PHP_SAPI === "cli"){
    echo PHP_EOL;

    if($failed > 0){
        exit(4);
    }
}
?>
