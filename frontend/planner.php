<?php
if(session_status() === PHP_SESSION_NONE){
    session_start();
}
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/planner_schema.php";
require_once "../includes/planner_profiles.php";
require_once "../includes/office_family_helper.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

if(!hasPermission($mysqli, "planner_view")){
    die("Access denied");
}

ensurePlannerSchema($mysqli);
ensurePlannerProfileSchema($mysqli);

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$plannerViewMode = isset($plannerViewMode) ? strtolower(trim((string)$plannerViewMode)) : "cssb";
$plannerViewMode = in_array($plannerViewMode, ["cssb", "personal", "technical"], true) ? $plannerViewMode : "cssb";
$plannerViewNames = [
    "cssb" => "CSSB Planner",
    "personal" => "Personal Planner",
    "technical" => "Technical Planner"
];
$plannerPageTitle = $plannerViewNames[$plannerViewMode];
$plannerPageUrl = basename($_SERVER['SCRIPT_NAME'] ?? "planner.php");
$plannerReadOnly = $plannerViewMode !== "cssb";
$canAdd = !$plannerReadOnly && hasPermission($mysqli, "planner_add");
$canEdit = !$plannerReadOnly && hasPermission($mysqli, "planner_edit");
$canDelete = !$plannerReadOnly && hasPermission($mysqli, "planner_delete");
$creatorAccountType = getCurrentAccountType($mysqli);
$currentPicName = plannerCurrentPicName($mysqli);
$error = "";
$personOptions = plannerPicOptions($mysqli);
$taskOptions = plannerTaskOptions($mysqli);

function plannerPicOptions($mysqli){
    $options = [];
    $seen = [];

    foreach(["user", "administrator"] as $accountTable){
        $result = $mysqli->query("SELECT username FROM `$accountTable` WHERE username IS NOT NULL AND username <> ''");

        if(!$result){
            continue;
        }

        while($row = $result->fetch_assoc()){
            $nickname = plannerAccountNickname($row['username'] ?? "");
            $key = strtolower($nickname);

            if($nickname === "" || isset($seen[$key])){
                continue;
            }

            $seen[$key] = true;
            $options[] = $nickname;
        }
    }

    natcasesort($options);
    return array_values($options);
}

function plannerDefaultTaskOptions(){
    return [
        "PM" => "#fd7e14",
        "CM" => "#dc3545",
        "Kickoff" => "#0d6efd",
        "Meeting" => "#198754",
        "Site Assestment" => "#ffc107",
        "Training" => "#e83e8c",
        "Deployment" => "#6f42c1"
    ];
}

function plannerTaskOptions($mysqli = null){
    // Keep the dropdown limited to the approved defaults. Custom "Other"
    // titles are saved on their task only and do not become permanent options.
    return plannerDefaultTaskOptions();
}

function plannerNormalizeTaskTitle($title){
    $title = trim((string)($title ?? ""));

    if(strcasecmp($title, "Trainning") === 0){
        return "Training";
    }

    return $title;
}

function plannerEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function plannerDateInput($value){
    $value = trim((string)($value ?? ""));

    if($value === ""){
        return null;
    }

    $date = DateTime::createFromFormat("!Y-m-d", $value);

    if(!$date || $date->format("Y-m-d") !== $value){
        return null;
    }

    return $value;
}

function plannerDateObject($value){
    $value = plannerDateInput($value);
    return $value !== null ? DateTime::createFromFormat("!Y-m-d", $value) : null;
}

function plannerTimeInput($value){
    $value = trim((string)($value ?? ""));

    if($value === ""){
        return null;
    }

    if(preg_match('/^\d{2}:\d{2}$/', $value)){
        $value .= ":00";
    }

    $time = DateTime::createFromFormat("!H:i:s", $value);

    if(!$time || $time->format("H:i:s") !== $value){
        return null;
    }

    return $time->format("H:i:s");
}

function plannerTimeDisplay($value){
    $value = trim((string)($value ?? ""));

    if($value === ""){
        return "";
    }

    $time = DateTime::createFromFormat("!H:i:s", $value);

    if(!$time){
        $time = DateTime::createFromFormat("!H:i", $value);
    }

    if(!$time){
        return $value;
    }

    return strtolower($time->format("g:i A"));
}

function plannerColor($value){
    $value = trim((string)($value ?? ""));

    if(preg_match('/^#[0-9a-fA-F]{6}$/', $value)){
        return $value;
    }

    return "#0d6efd";
}

function plannerColorForTitle($title, $taskOptions = null){
    $title = plannerNormalizeTaskTitle($title);
    $options = is_array($taskOptions) ? $taskOptions : plannerDefaultTaskOptions();
    return $options[$title] ?? "#0d6efd";
}

function plannerPersonValues($value){
    if(is_array($value)){
        $parts = $value;
    }
    else{
        $parts = preg_split('/\s*,\s*|\r\n|\r|\n/', (string)($value ?? ""));
    }

    $clean = [];
    $seen = [];

    foreach($parts as $part){
        $part = trim((string)$part);

        if($part === ""){
            continue;
        }

        $key = strtolower($part);

        if(isset($seen[$key])){
            continue;
        }

        $seen[$key] = true;
        $clean[] = $part;
    }

    return $clean;
}

function plannerPersonsText($value){
    return implode(", ", plannerPersonValues($value));
}

function plannerBusyPersonsForRange($mysqli, $startDate, $endDate, $taskTime = null, $excludeTaskId = 0){
    $busy = [];

    if($startDate === null || $endDate === null || $taskTime === null){
        return $busy;
    }

    $stmt = $mysqli->prepare("
        SELECT id, person_in_charge
        FROM planner_tasks
        WHERE start_date <= ?
          AND COALESCE(end_date, start_date) >= ?
          AND task_time = ?
          AND id <> ?
    ");

    if(!$stmt){
        return $busy;
    }

    $excludeTaskId = (int)$excludeTaskId;
    $stmt->bind_param("sssi", $endDate, $startDate, $taskTime, $excludeTaskId);
    $stmt->execute();
    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        foreach(plannerPersonValues($row['person_in_charge'] ?? "") as $person){
            $busy[strtolower($person)] = $person;
        }
    }

    return $busy;
}

function plannerBusySelectedPersons($selectedPersons, $busyPersons){
    $busySelected = [];

    foreach(plannerPersonValues($selectedPersons) as $person){
        $key = strtolower($person);

        if(isset($busyPersons[$key])){
            $busySelected[] = $busyPersons[$key];
        }
    }

    return array_values(array_unique($busySelected));
}

function plannerViewUrl($pageUrl, $view, $period, $start = "", $end = ""){
    $params = [
        "view" => in_array($view, ["month", "week"], true) ? $view : "month",
        "period" => in_array($period, ["this", "next", "range"], true) ? $period : "this"
    ];

    if($params['period'] === "range"){
        $start = plannerDateInput($start);
        $end = plannerDateInput($end);

        if($start !== null && $end !== null){
            $params['start'] = $start;
            $params['end'] = $end;
        }
    }

    return $pageUrl . "?" . http_build_query($params);
}

function plannerRedirectView($pageUrl = "planner.php"){
    $view = trim((string)($_POST['current_view'] ?? "month"));
    $period = trim((string)($_POST['current_period'] ?? "this"));
    $start = trim((string)($_POST['current_start'] ?? ""));
    $end = trim((string)($_POST['current_end'] ?? ""));

    header("Location: " . plannerViewUrl($pageUrl, $view, $period, $start, $end));
    exit();
}

function plannerHolidayFallback($year){
    return [
        [
            "date" => $year . "-01-01",
            "name" => "New Year's Day"
        ],
        [
            "date" => $year . "-08-31",
            "name" => "National Day"
        ],
        [
            "date" => $year . "-09-16",
            "name" => "Malaysia Day"
        ],
        [
            "date" => $year . "-12-25",
            "name" => "Christmas Day"
        ]
    ];
}

function plannerFetchMalaysiaHolidayYear($mysqli, $year){
    $year = (int)$year;

    if($year < 2000 || $year > 2100){
        return [];
    }

    $cacheStmt = $mysqli->prepare("
        SELECT data_json, fetched_at
        FROM planner_holiday_cache
        WHERE holiday_year = ?
          AND country_code = 'MY'
        LIMIT 1
    ");

    if($cacheStmt){
        $cacheStmt->bind_param("i", $year);
        $cacheStmt->execute();
        $cacheRow = $cacheStmt->get_result()->fetch_assoc();

        if($cacheRow){
            $fetchedAt = strtotime((string)($cacheRow['fetched_at'] ?? ""));
            $decoded = json_decode((string)($cacheRow['data_json'] ?? ""), true);

            if(is_array($decoded) && count($decoded) >= 20 && $fetchedAt !== false && $fetchedAt > strtotime("-30 days")){
                return $decoded;
            }
        }
    }

    $apiUrl = "https://malaysia-holiday.dydxsoft.my/api/v1/holidays?year=" . $year;
    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "timeout" => 4,
            "header" => "Accept: application/json\r\n"
        ]
    ]);
    $response = @file_get_contents($apiUrl, false, $context);
    $holidays = [];

    if($response !== false){
        $decoded = json_decode($response, true);
        $items = [];

        if(is_array($decoded)){
            if(isset($decoded['data']) && is_array($decoded['data'])){
                $items = $decoded['data'];
            }
            else{
                $items = $decoded;
            }

            foreach($items as $holiday){
                if(!is_array($holiday)){
                    continue;
                }

                $date = plannerDateInput($holiday['date'] ?? "");
                $name = trim((string)($holiday['name'] ?? $holiday['localName'] ?? ""));

                if($date === null || $name === ""){
                    continue;
                }

                $holidays[] = [
                    "date" => $date,
                    "name" => $name
                ];
            }
        }
    }

    if(empty($holidays)){
        $holidays = plannerHolidayFallback($year);
    }

    $json = json_encode($holidays, JSON_UNESCAPED_UNICODE);
    $saveStmt = $mysqli->prepare("
        INSERT INTO planner_holiday_cache (holiday_year, country_code, data_json, fetched_at)
        VALUES (?, 'MY', ?, NOW())
        ON DUPLICATE KEY UPDATE
            data_json = VALUES(data_json),
            fetched_at = NOW()
    ");

    if($saveStmt){
        $saveStmt->bind_param("is", $year, $json);
        $saveStmt->execute();
    }

    return $holidays;
}

$calendarView = strtolower(trim((string)($_GET['view'] ?? "month")));
$calendarView = in_array($calendarView, ["month", "week"], true) ? $calendarView : "month";
$calendarPeriod = strtolower(trim((string)($_GET['period'] ?? "this")));
$calendarPeriod = in_array($calendarPeriod, ["this", "next", "range"], true) ? $calendarPeriod : "this";
$today = new DateTime("today");
$legacyMonth = trim((string)($_GET['month'] ?? ""));
$legacyMonthDate = DateTime::createFromFormat("!Y-m", $legacyMonth);

if($legacyMonthDate && !isset($_GET['view']) && !isset($_GET['period'])){
    $calendarView = "month";
    $calendarPeriod = "range";
    $displayStart = clone $legacyMonthDate;
    $displayEnd = (clone $legacyMonthDate)->modify("last day of this month");
}
elseif($calendarView === "week"){
    $thisWeekStart = (clone $today)->modify("-" . (int)$today->format("w") . " days");

    if($calendarPeriod === "next"){
        $displayStart = (clone $thisWeekStart)->modify("+7 days");
    }
    elseif($calendarPeriod === "range"){
        $displayStart = plannerDateObject($_GET['start'] ?? "") ?: clone $thisWeekStart;
    }
    else{
        $calendarPeriod = "this";
        $displayStart = clone $thisWeekStart;
    }

    $displayEnd = (clone $displayStart)->modify("+6 days");
}
else{
    $thisMonthStart = (clone $today)->modify("first day of this month");

    if($calendarPeriod === "next"){
        $displayStart = (clone $thisMonthStart)->modify("first day of next month");
        $displayEnd = (clone $displayStart)->modify("last day of this month");
    }
    elseif($calendarPeriod === "range"){
        $displayStart = plannerDateObject($_GET['start'] ?? "") ?: clone $thisMonthStart;
        $displayEnd = plannerDateObject($_GET['end'] ?? "") ?: (clone $displayStart)->modify("last day of this month");

        if($displayEnd < $displayStart){
            $displayEnd = clone $displayStart;
        }

        $rangeDays = (int)$displayStart->diff($displayEnd)->format("%a");

        if($rangeDays > 365){
            $displayEnd = (clone $displayStart)->modify("+365 days");
        }
    }
    else{
        $calendarPeriod = "this";
        $displayStart = clone $thisMonthStart;
        $displayEnd = (clone $displayStart)->modify("last day of this month");
    }
}

$displayIsWholeMonth = $displayStart->format("Y-m-d") === $displayStart->format("Y-m-01")
    && $displayEnd->format("Y-m-d") === (clone $displayStart)->modify("last day of this month")->format("Y-m-d");
$gridStart = clone $displayStart;
$gridEnd = clone $displayEnd;

if($calendarView === "month" && $displayIsWholeMonth){
    $gridStart->modify("-" . (int)$gridStart->format("w") . " days");
    $gridEnd->modify("+" . (6 - (int)$gridEnd->format("w")) . " days");
}

$displayStartValue = $displayStart->format("Y-m-d");
$displayEndValue = $displayEnd->format("Y-m-d");
$periodDayCount = (int)$displayStart->diff($displayEnd)->format("%a") + 1;

if($calendarView === "week"){
    $calendarLabel = $displayStart->format("j M") . " - " . $displayEnd->format("j M Y");
    $previousStart = (clone $displayStart)->modify("-7 days");
    $previousEnd = (clone $previousStart)->modify("+6 days");
    $nextStart = (clone $displayStart)->modify("+7 days");
    $nextEnd = (clone $nextStart)->modify("+6 days");
}
else{
    $calendarLabel = $displayIsWholeMonth
        ? $displayStart->format("F Y")
        : $displayStart->format("j M Y") . " - " . $displayEnd->format("j M Y");

    if($displayIsWholeMonth){
        $previousStart = (clone $displayStart)->modify("first day of previous month");
        $previousEnd = (clone $previousStart)->modify("last day of this month");
        $nextStart = (clone $displayStart)->modify("first day of next month");
        $nextEnd = (clone $nextStart)->modify("last day of this month");
    }
    else{
        $previousStart = (clone $displayStart)->modify("-" . $periodDayCount . " days");
        $previousEnd = (clone $displayEnd)->modify("-" . $periodDayCount . " days");
        $nextStart = (clone $displayStart)->modify("+" . $periodDayCount . " days");
        $nextEnd = (clone $displayEnd)->modify("+" . $periodDayCount . " days");
    }
}

$previousViewUrl = plannerViewUrl(
    $plannerPageUrl,
    $calendarView,
    "range",
    $previousStart->format("Y-m-d"),
    $previousEnd->format("Y-m-d")
);
$nextViewUrl = plannerViewUrl(
    $plannerPageUrl,
    $calendarView,
    "range",
    $nextStart->format("Y-m-d"),
    $nextEnd->format("Y-m-d")
);
$weekdayLabels = [];
$weekdayCursor = clone $gridStart;

for($weekdayIndex = 0; $weekdayIndex < 7; $weekdayIndex++){
    $weekdayLabels[] = substr($weekdayCursor->format("D"), 0, 1);
    $weekdayCursor->modify("+1 day");
}

if($_SERVER["REQUEST_METHOD"] === "POST"){
    if($plannerReadOnly){
        die("This planner view is read only.");
    }

    $action = trim((string)($_POST['planner_action'] ?? ""));
    $id = (int)($_POST['task_id'] ?? 0);
    $selectedTitle = plannerNormalizeTaskTitle($_POST['title'] ?? "");
    $customTitle = plannerNormalizeTaskTitle($_POST['custom_title'] ?? "");
    $customColor = "#ffc107";
    $isOtherTitle = strcasecmp($selectedTitle, "Other") === 0;
    $title = $isOtherTitle ? $customTitle : $selectedTitle;
    $personInCharge = plannerPersonsText($_POST['person_in_charge'] ?? []);
    $description = trim((string)($_POST['description'] ?? ""));
    $startDate = plannerDateInput($_POST['start_date'] ?? "");
    $endDate = plannerDateInput($_POST['end_date'] ?? "");
    $taskTimeRaw = trim((string)($_POST['task_time'] ?? ""));
    $taskTime = plannerTimeInput($taskTimeRaw);
    $color = $isOtherTitle ? $customColor : plannerColorForTitle($title, $taskOptions);

    if($endDate === null){
        $endDate = $startDate;
    }

    if($action === "add" || $action === "edit"){
        if($action === "add" && !$canAdd){
            die("Access denied");
        }

        if($action === "edit" && !$canEdit){
            die("Access denied");
        }

        if($selectedTitle === "" || $title === "" || $startDate === null){
            $error = "Task title and start date are required.";
        }
        elseif($isOtherTitle && strcasecmp($title, "Other") === 0){
            $error = "Please enter a valid custom task title.";
        }
        elseif(!$isOtherTitle && !isset($taskOptions[$title])){
            $error = "Please choose a valid task title.";
        }
        elseif($taskTimeRaw !== "" && $taskTime === null){
            $error = "Please choose a valid task time.";
        }
        elseif($endDate !== null && strtotime($endDate) < strtotime($startDate)){
            $error = "End date cannot be earlier than start date.";
        }
        else{
            $busyPersons = plannerBusyPersonsForRange($mysqli, $startDate, $endDate, $taskTime, $action === "edit" ? $id : 0);
            $busySelected = plannerBusySelectedPersons($personInCharge, $busyPersons);

            if(!empty($busySelected)){
                $error = "PIC already occupied for the selected time: " . implode(", ", $busySelected) . ".";
            }
        }

        if($error === "" && $action === "add"){
            $stmt = $mysqli->prepare("
                INSERT INTO planner_tasks
                    (title, description, person_in_charge, start_date, end_date, task_time, color, created_by, created_account_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if(!$stmt){
                die("SQL Error: " . $mysqli->error);
            }

            $stmt->bind_param("sssssssss", $title, $description, $personInCharge, $startDate, $endDate, $taskTime, $color, $username, $creatorAccountType);

            if($stmt->execute()){
                $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
                $time = date("Y-m-d H:i:s");
                $descriptionLog = "User [$username] added planner task.
Title: $title
Person In Charge: $personInCharge
Start Date: $startDate
End Date: $endDate
Task Time: " . plannerTimeDisplay($taskTime) . "
IP Address: $ip
Time: $time";

                logActivity($mysqli, $username, $role, "ADD PLANNER TASK", $descriptionLog);
                plannerRedirectView($plannerPageUrl);
            }

            $error = "Add failed: " . $stmt->error;
        }
        elseif($error === "" && $id > 0){
            $fetchStmt = $mysqli->prepare("SELECT * FROM planner_tasks WHERE id = ? LIMIT 1");

            if(!$fetchStmt){
                die("SQL Error: " . $mysqli->error);
            }

            $fetchStmt->bind_param("i", $id);
            $fetchStmt->execute();
            $oldTask = $fetchStmt->get_result()->fetch_assoc();

            if(!$oldTask){
                $error = "Planner task not found.";
            }
            else{
                $stmt = $mysqli->prepare("
                    UPDATE planner_tasks SET
                        title = ?,
                        description = ?,
                        person_in_charge = ?,
                        start_date = ?,
                        end_date = ?,
                        task_time = ?,
                        color = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                if(!$stmt){
                    die("SQL Error: " . $mysqli->error);
                }

                $stmt->bind_param("ssssssssi", $title, $description, $personInCharge, $startDate, $endDate, $taskTime, $color, $username, $id);

                if($stmt->execute()){
                    $clearReminderStmt = $mysqli->prepare("DELETE FROM planner_email_reminders WHERE task_id = ?");

                    if($clearReminderStmt){
                        $clearReminderStmt->bind_param("i", $id);
                        $clearReminderStmt->execute();
                    }
                    $clearTelegramStmt = $mysqli->prepare("DELETE FROM planner_telegram_reminders WHERE task_id = ?");
                    if($clearTelegramStmt){
                        $clearTelegramStmt->bind_param("i", $id);
                        $clearTelegramStmt->execute();
                    }

                    $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
                    $time = date("Y-m-d H:i:s");
                    $descriptionLog = "User [$username] updated planner task.
Task ID: $id
Old Title: {$oldTask['title']}
New Title: $title
Old Person In Charge: {$oldTask['person_in_charge']}
New Person In Charge: $personInCharge
Old Start Date: {$oldTask['start_date']}
New Start Date: $startDate
Old End Date: {$oldTask['end_date']}
New End Date: $endDate
Old Task Time: " . plannerTimeDisplay($oldTask['task_time'] ?? "") . "
New Task Time: " . plannerTimeDisplay($taskTime) . "
IP Address: $ip
Time: $time";

                    logActivity($mysqli, $username, $role, "UPDATE PLANNER TASK", $descriptionLog);
                    plannerRedirectView($plannerPageUrl);
                }

                $error = "Update failed: " . $stmt->error;
            }
        }
        elseif($error === ""){
            $error = "Invalid task selected.";
        }
    }
    elseif($action === "delete"){
        if(!$canDelete){
            die("Access denied");
        }

        if($id <= 0){
            $error = "Invalid task selected.";
        }
        else{
            $fetchStmt = $mysqli->prepare("SELECT * FROM planner_tasks WHERE id = ? LIMIT 1");

            if(!$fetchStmt){
                die("SQL Error: " . $mysqli->error);
            }

            $fetchStmt->bind_param("i", $id);
            $fetchStmt->execute();
            $oldTask = $fetchStmt->get_result()->fetch_assoc();

            if(!$oldTask){
                $error = "Planner task not found.";
            }
            else{
                $stmt = $mysqli->prepare("DELETE FROM planner_tasks WHERE id = ? LIMIT 1");

                if(!$stmt){
                    die("SQL Error: " . $mysqli->error);
                }

                $stmt->bind_param("i", $id);

                if($stmt->execute()){
                    $clearReminderStmt = $mysqli->prepare("DELETE FROM planner_email_reminders WHERE task_id = ?");

                    if($clearReminderStmt){
                        $clearReminderStmt->bind_param("i", $id);
                        $clearReminderStmt->execute();
                    }
                    $clearTelegramStmt = $mysqli->prepare("DELETE FROM planner_telegram_reminders WHERE task_id = ?");
                    if($clearTelegramStmt){
                        $clearTelegramStmt->bind_param("i", $id);
                        $clearTelegramStmt->execute();
                    }

                    $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
                    $time = date("Y-m-d H:i:s");
                    $descriptionLog = "User [$username] deleted planner task.
Task ID: $id
Title: {$oldTask['title']}
Start Date: {$oldTask['start_date']}
End Date: {$oldTask['end_date']}
Task Time: " . plannerTimeDisplay($oldTask['task_time'] ?? "") . "
IP Address: $ip
Time: $time";

                    logActivity($mysqli, $username, $role, "DELETE PLANNER TASK", $descriptionLog);
                    plannerRedirectView($plannerPageUrl);
                }

                $error = "Delete failed: " . $stmt->error;
            }
        }
    }
}

$tasks = [];
$taskStmt = $mysqli->prepare("
    SELECT id, title, description, person_in_charge, start_date, COALESCE(end_date, start_date) AS end_date, task_time, color, created_by, created_account_type, created_at, updated_by, updated_at
    FROM planner_tasks
    WHERE start_date <= ?
      AND COALESCE(end_date, start_date) >= ?
    ORDER BY start_date ASC, COALESCE(task_time, '23:59:59') ASC, id ASC
");

if(!$taskStmt){
    die("SQL Error: " . $mysqli->error);
}

$taskStmt->bind_param("ss", $displayEndValue, $displayStartValue);
$taskStmt->execute();
$taskResult = $taskStmt->get_result();

while($row = $taskResult->fetch_assoc()){
    $normalizedTitle = plannerNormalizeTaskTitle($row['title']);
    $taskPics = plannerPersonValues($row['person_in_charge'] ?? "");

    if($plannerViewMode === "personal"){
        $matchesCurrentUser = false;

        foreach($taskPics as $taskPic){
            if(strcasecmp($taskPic, $currentPicName) === 0){
                $matchesCurrentUser = true;
                break;
            }
        }

        if(!$matchesCurrentUser){
            continue;
        }
    }

    if($plannerViewMode === "technical"){
        $creatorPlannerRole = plannerCreatorOperationalRole(
            $mysqli,
            $row['created_by'] ?? "",
            $row['created_account_type'] ?? ""
        );

        if($creatorPlannerRole !== "technical"){
            continue;
        }
    }

    $tasks[] = [
        "id" => (int)$row['id'],
        "title" => $normalizedTitle,
        "description" => (string)($row['description'] ?? ""),
        "person_in_charge" => $taskPics,
        "start_date" => (string)$row['start_date'],
        "end_date" => (string)($row['end_date'] ?? $row['start_date']),
        "task_time" => $row['task_time'] !== null ? substr((string)$row['task_time'], 0, 5) : "",
        "task_time_display" => plannerTimeDisplay($row['task_time'] ?? ""),
        "color" => plannerColor($row['color'] ?? plannerColorForTitle($normalizedTitle, $taskOptions)),
        "created_by" => (string)($row['created_by'] ?? ""),
        "created_account_type" => (string)($row['created_account_type'] ?? ""),
        "updated_by" => (string)($row['updated_by'] ?? "")
    ];
}

$holidayTasks = [];
$holidayYears = range((int)$displayStart->format("Y"), (int)$displayEnd->format("Y"));

foreach($holidayYears as $holidayYear){
    foreach(plannerFetchMalaysiaHolidayYear($mysqli, $holidayYear) as $holiday){
        $holidayDate = plannerDateInput($holiday['date'] ?? "");

        if($holidayDate === null || $holidayDate < $displayStartValue || $holidayDate > $displayEndValue){
            continue;
        }

        $holidayName = trim((string)($holiday['name'] ?? ""));

        if($holidayName === ""){
            continue;
        }

        $holidayTasks[] = [
            "id" => "holiday-" . $holidayDate . "-" . md5($holidayName),
            "title" => $holidayName,
            "description" => "Malaysia public holiday",
            "person_in_charge" => [],
            "start_date" => $holidayDate,
            "end_date" => $holidayDate,
            "task_time" => "",
            "task_time_display" => "",
            "color" => "#6c757d",
            "created_by" => "Malaysia Holiday Calendar",
            "updated_by" => "",
            "is_holiday" => true
        ];
    }
}

$calendarDays = [];
$cursor = clone $gridStart;

while($cursor <= $gridEnd){
    $calendarDays[] = [
        "date" => $cursor->format("Y-m-d"),
        "day" => $cursor->format("j"),
        "month" => $cursor->format("Y-m"),
        "is_in_range" => $cursor >= $displayStart && $cursor <= $displayEnd,
        "is_today" => $cursor->format("Y-m-d") === date("Y-m-d")
    ];

    $cursor->modify("+1 day");
}

?>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<style>
html,
body{
    overflow-x:hidden;
}

.planner-shell{
    max-width:100%;
}

.planner-page-title{
    margin:0 0 14px;
    font-size:28px;
    font-weight:700;
    color:#17212b;
}

.planner-toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.planner-month-nav{
    display:flex;
    align-items:center;
    gap:8px;
}

.planner-month-title{
    margin:0;
    font-size:28px;
    font-weight:700;
    color:#212529;
    border:0;
    background:transparent;
    padding:4px 8px;
    border-radius:8px;
}

.planner-month-title:hover{
    background:#fff3cd;
}

.planner-view-controls{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:8px;
    margin-left:auto;
}

.planner-view-toggle .btn{
    min-width:86px;
}

.planner-period-select{
    width:auto;
    min-width:160px;
}

.planner-calendar{
    background:#fff;
    border:1px solid #dee2e6;
    border-radius:8px;
    overflow:hidden;
}

.planner-weekdays,
.planner-grid{
    display:grid;
    grid-template-columns:repeat(7, minmax(0, 1fr));
}

.planner-weekday{
    min-height:40px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#212529;
    color:#fff;
    font-weight:700;
    font-size:13px;
    border-right:1px solid rgba(255,255,255,.16);
}

.planner-weekday:last-child{
    border-right:0;
}

.planner-day{
    min-height:126px;
    padding:8px;
    border-right:1px solid #e9ecef;
    border-top:1px solid #e9ecef;
    background:#fff;
    cursor:pointer;
    overflow:hidden;
}

.planner-day:nth-child(7n){
    border-right:0;
}

.planner-day.is-muted{
    background:#f8f9fa;
    color:#adb5bd;
}

.planner-day.is-today{
    box-shadow:inset 0 0 0 2px #ffc107;
}

.planner-calendar.is-week .planner-day{
    min-height:260px;
}

.planner-day-number{
    display:flex;
    align-items:center;
    justify-content:space-between;
    font-size:13px;
    font-weight:700;
    margin-bottom:6px;
}

.planner-task-list{
    display:flex;
    flex-direction:column;
    gap:4px;
}

.planner-task{
    display:block;
    width:100%;
    border:0;
    border-radius:5px;
    color:#fff;
    font-size:12px;
    font-weight:700;
    line-height:1.25;
    padding:4px 6px;
    text-align:left;
    white-space:normal;
    overflow:hidden;
    cursor:pointer;
}

.planner-task-main,
.planner-task-time{
    display:block;
    max-width:100%;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.planner-task-time{
    font-size:11px;
    font-weight:700;
    opacity:.92;
    margin-top:1px;
}

.planner-task:focus{
    outline:2px solid #111;
    outline-offset:1px;
}

.planner-task.is-holiday{
    background:#6c757d !important;
    color:#fff;
}

.planner-task-hover-card{
    display:none;
    position:fixed;
    z-index:1200;
    left:0;
    top:0;
    max-width:min(420px, calc(100vw - 32px));
    background:#fff;
    border:1px solid #dee2e6;
    border-radius:8px;
    box-shadow:0 10px 28px rgba(0,0,0,.16);
    padding:10px 12px;
    pointer-events:none;
}

.planner-task-hover-card.is-visible{
    display:block;
}

.planner-hover-title{
    color:#212529;
    font-weight:800;
    margin-bottom:6px;
    overflow-wrap:anywhere;
}

.planner-hover-pic-list{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.planner-hover-pic{
    display:inline-flex;
    align-items:center;
    border:1px solid #dee2e6;
    border-radius:999px;
    background:#f8f9fa;
    color:#212529;
    font-size:12px;
    font-weight:700;
    line-height:1;
    padding:5px 8px;
}

.planner-pic-selected{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin-top:8px;
}

.planner-pic-chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:1px solid #dee2e6;
    border-radius:999px;
    background:#f8f9fa;
    color:#212529;
    font-weight:700;
    font-size:13px;
    padding:5px 8px 5px 10px;
}

.planner-pic-chip button{
    border:0;
    background:transparent;
    color:#dc3545;
    font-weight:900;
    line-height:1;
    padding:0;
}

.planner-empty-hint{
    color:#adb5bd;
    font-size:12px;
    opacity:0;
}

.planner-day:hover .planner-empty-hint{
    opacity:1;
}

.planner-floating-add{
    position:fixed;
    right:28px;
    bottom:28px;
    width:58px;
    height:58px;
    border-radius:18px;
    box-shadow:0 10px 28px rgba(0,0,0,.22);
    z-index:1030;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
}

.planner-detail-row{
    display:grid;
    grid-template-columns:130px minmax(0, 1fr);
    gap:10px;
    padding:8px 0;
    border-bottom:1px solid #f1f3f5;
}

.planner-detail-row:last-child{
    border-bottom:0;
}

.planner-detail-label{
    color:#6c757d;
    font-weight:700;
    font-size:12px;
    text-transform:uppercase;
}

.planner-detail-value{
    color:#212529;
    overflow-wrap:anywhere;
}

@media(max-width:900px){
    .planner-calendar{
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }

    .planner-weekdays,
    .planner-grid{
        min-width:760px;
    }
}

@media(max-width:768px){
    .planner-toolbar{
        align-items:stretch;
    }

    .planner-month-nav,
    .planner-view-controls,
    .planner-toolbar > .btn{
        width:100%;
    }

    .planner-month-nav .btn{
        flex:0 0 auto;
    }

    .planner-month-title{
        flex:1 1 auto;
        font-size:22px;
        text-align:center;
    }

    .planner-view-controls{
        justify-content:space-between;
    }

    .planner-view-toggle{
        flex:1 1 auto;
    }

    .planner-view-toggle .btn{
        flex:1 1 50%;
        min-width:0;
    }

    .planner-period-select{
        flex:0 1 180px;
        min-width:0;
    }

    .planner-day{
        min-height:108px;
        padding:6px;
    }

    .planner-calendar.is-week .planner-day{
        min-height:150px;
    }

    .planner-detail-row{
        grid-template-columns:1fr;
        gap:2px;
    }
}
</style>

<div class="main planner-shell">
    <h1 class="planner-page-title"><?= plannerEscape($plannerPageTitle) ?></h1>

    <div class="planner-toolbar">
        <div class="planner-month-nav">
            <a href="<?= plannerEscape($previousViewUrl) ?>" class="btn btn-outline-secondary" title="Previous <?= plannerEscape($calendarView) ?>">
                <i class="fa fa-chevron-left"></i>
            </a>

            <button type="button" class="planner-month-title" data-bs-toggle="modal" data-bs-target="#plannerRangeModal">
                <?= plannerEscape($calendarLabel) ?>
            </button>

            <a href="<?= plannerEscape($nextViewUrl) ?>" class="btn btn-outline-secondary" title="Next <?= plannerEscape($calendarView) ?>">
                <i class="fa fa-chevron-right"></i>
            </a>
        </div>

        <div class="planner-view-controls">
            <div class="btn-group planner-view-toggle" role="group" aria-label="Planner view">
                <a href="<?= plannerEscape(plannerViewUrl($plannerPageUrl, 'week', 'this')) ?>"
                   class="btn <?= $calendarView === 'week' ? 'btn-warning' : 'btn-outline-secondary' ?>">
                    <i class="fa fa-calendar-week"></i> Week
                </a>
                <a href="<?= plannerEscape(plannerViewUrl($plannerPageUrl, 'month', 'this')) ?>"
                   class="btn <?= $calendarView === 'month' ? 'btn-warning' : 'btn-outline-secondary' ?>">
                    <i class="fa fa-calendar-days"></i> Month
                </a>
            </div>

            <select id="plannerPeriodSelect" class="form-select planner-period-select" aria-label="Planner period">
                <option value="this" <?= $calendarPeriod === 'this' ? 'selected' : '' ?>>This <?= plannerEscape(ucfirst($calendarView)) ?></option>
                <option value="next" <?= $calendarPeriod === 'next' ? 'selected' : '' ?>>Next <?= plannerEscape(ucfirst($calendarView)) ?></option>
                <option value="range" <?= $calendarPeriod === 'range' ? 'selected' : '' ?>>Custom Range</option>
            </select>
        </div>
    </div>

    <?php if($error !== ""): ?>
        <div class="alert alert-danger"><?= plannerEscape($error) ?></div>
    <?php endif; ?>

    <div class="planner-calendar is-<?= plannerEscape($calendarView) ?>">
        <div class="planner-weekdays">
            <?php foreach($weekdayLabels as $weekdayLabel): ?>
                <div class="planner-weekday"><?= plannerEscape($weekdayLabel) ?></div>
            <?php endforeach; ?>
        </div>

        <div class="planner-grid" id="plannerGrid"></div>
    </div>
</div>

<?php if($canAdd): ?>
    <button type="button" class="btn btn-warning planner-floating-add" onclick="openPlannerAddModal()" title="Add task">
        <i class="fa fa-plus"></i>
    </button>
<?php endif; ?>

<div class="modal fade" id="plannerRangeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <form method="GET" action="<?= plannerEscape($plannerPageUrl) ?>" class="modal-content">
      <input type="hidden" name="view" value="<?= plannerEscape($calendarView) ?>">
      <input type="hidden" name="period" value="range">

      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">
          <i class="fa fa-calendar text-warning"></i>
          Choose <?= $calendarView === 'week' ? 'Week' : 'Date' ?> Range
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row">
            <div class="col-md-6 mb-3 mb-md-0">
                <label class="form-label">Start Date</label>
                <input type="date" name="start" id="plannerRangeStart" class="form-control" value="<?= plannerEscape($displayStartValue) ?>" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">End Date</label>
                <input type="date" name="end" id="plannerRangeEnd" class="form-control" value="<?= plannerEscape($displayEndValue) ?>" <?= $calendarView === 'week' ? 'readonly' : '' ?> required>
            </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning">Go</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="plannerTaskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="planner_action" id="plannerAction" value="add">
      <input type="hidden" name="task_id" id="plannerTaskId" value="">
      <input type="hidden" name="current_view" value="<?= plannerEscape($calendarView) ?>">
      <input type="hidden" name="current_period" value="<?= plannerEscape($calendarPeriod) ?>">
      <input type="hidden" name="current_start" value="<?= plannerEscape($displayStartValue) ?>">
      <input type="hidden" name="current_end" value="<?= plannerEscape($displayEndValue) ?>">
      <input type="hidden" name="color" id="plannerColor" value="#0d6efd">

      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="plannerTaskModalTitle">
          <i class="fa fa-calendar-plus text-warning"></i>
          Add Planner Task
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Task Title *</label>
                <select name="title" id="plannerTitle" class="form-select" required>
                    <option value="">Select Task</option>
                    <?php foreach($taskOptions as $taskTitle => $taskColor): ?>
                        <option value="<?= plannerEscape($taskTitle) ?>" data-color="<?= plannerEscape($taskColor) ?>">
                            <?= plannerEscape($taskTitle) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="Other" data-color="#ffc107">Other</option>
                </select>
            </div>

            <div class="col-md-6 mb-3 d-none" id="plannerCustomTitleWrap">
                <label class="form-label">Other Task Title *</label>
                <input type="text" name="custom_title" id="plannerCustomTitle" class="form-control">
            </div>

            <input type="hidden" name="custom_color" id="plannerCustomColor" value="#ffc107">

            <div class="col-md-6 mb-3">
                <label class="form-label">Person In Charge</label>
                <select id="plannerPicSelect" class="form-select">
                    <option value="">Select PIC</option>
                </select>
                <div class="planner-pic-selected" id="plannerPicSelected"></div>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Start Date *</label>
                <input type="date" name="start_date" id="plannerStartDate" class="form-control" required>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" id="plannerEndDate" class="form-control">
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Time</label>
                <input type="time" name="task_time" id="plannerTaskTime" class="form-control">
            </div>

            <div class="col-12 mb-2">
                <label class="form-label">Description</label>
                <textarea name="description" id="plannerDescription" class="form-control" rows="4"></textarea>
            </div>
        </div>
      </div>

      <div class="modal-footer">
        <?php if($canDelete): ?>
            <button type="submit" class="btn btn-danger me-auto d-none" id="plannerDeleteButton" onclick="return submitPlannerDelete();">
                <i class="fa fa-trash"></i> Delete
            </button>
        <?php endif; ?>

        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>

        <?php if($canAdd || $canEdit): ?>
            <button type="submit" class="btn btn-warning" id="plannerSaveButton">Save</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="plannerViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">
          <i class="fa fa-circle-info text-warning"></i>
          Planner Task
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" id="plannerViewContent"></div>

      <div class="modal-footer">
        <?php if($canDelete): ?>
            <button type="button" class="btn btn-danger me-auto" id="plannerViewDeleteButton">
                <i class="fa fa-trash"></i> Delete
            </button>
        <?php endif; ?>

        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <?php if($canEdit): ?>
            <button type="button" class="btn btn-primary" id="plannerViewEditButton">
                <i class="fa fa-pen"></i> Edit
            </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if($canDelete): ?>
<form method="POST" class="d-none" id="plannerDeleteForm">
    <input type="hidden" name="planner_action" value="delete">
    <input type="hidden" name="task_id" id="plannerDeleteTaskId" value="">
    <input type="hidden" name="current_view" value="<?= plannerEscape($calendarView) ?>">
    <input type="hidden" name="current_period" value="<?= plannerEscape($calendarPeriod) ?>">
    <input type="hidden" name="current_start" value="<?= plannerEscape($displayStartValue) ?>">
    <input type="hidden" name="current_end" value="<?= plannerEscape($displayEndValue) ?>">
</form>
<?php endif; ?>

<div class="planner-task-hover-card" id="plannerTaskHoverCard"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const plannerDays = <?= json_encode($calendarDays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const plannerTasks = <?= json_encode($tasks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const plannerHolidayTasks = <?= json_encode($holidayTasks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const plannerAllItems = plannerTasks.concat(plannerHolidayTasks);
const plannerPersonOptions = <?= json_encode($personOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const plannerTaskColors = <?= json_encode($taskOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const plannerCanAdd = <?= json_encode($canAdd) ?>;
const plannerCanEdit = <?= json_encode($canEdit) ?>;
const plannerCanDelete = <?= json_encode($canDelete) ?>;
const plannerCalendarView = <?= json_encode($calendarView) ?>;
const plannerCalendarPeriod = <?= json_encode($calendarPeriod) ?>;
const plannerPageUrl = <?= json_encode($plannerPageUrl) ?>;
let plannerSelectedTask = null;
let plannerSelectedPics = [];

function plannerEscapeHtml(value){
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function plannerFormatDate(value){
    value = String(value || "");

    if(value === ""){
        return "-";
    }

    const parts = value.split("-");

    if(parts.length !== 3){
        return value;
    }

    return parts[2] + "/" + parts[1] + "/" + parts[0];
}

function plannerDateInRange(dateValue, startValue, endValue){
    const current = new Date(dateValue + "T00:00:00");
    const start = new Date(startValue + "T00:00:00");
    const end = new Date((endValue || startValue) + "T00:00:00");

    return current >= start && current <= end;
}

function plannerTaskSortValue(task){
    const time = String((task && task.task_time) || "").trim();

    return time === "" ? "99:99" : time;
}

function plannerTasksForDate(dateValue){
    return plannerAllItems.filter(function(task){
        return plannerDateInRange(dateValue, task.start_date, task.end_date || task.start_date);
    }).sort(function(a, b){
        const timeCompare = plannerTaskSortValue(a).localeCompare(plannerTaskSortValue(b));

        if(timeCompare !== 0){
            return timeCompare;
        }

        return String(a.title || "").localeCompare(String(b.title || ""));
    });
}

function plannerTextColor(backgroundColor){
    backgroundColor = String(backgroundColor || "#0d6efd").replace("#", "");

    if(backgroundColor.length !== 6){
        return "#fff";
    }

    const red = parseInt(backgroundColor.slice(0, 2), 16);
    const green = parseInt(backgroundColor.slice(2, 4), 16);
    const blue = parseInt(backgroundColor.slice(4, 6), 16);
    const brightness = ((red * 299) + (green * 587) + (blue * 114)) / 1000;

    return brightness > 150 ? "#111" : "#fff";
}

function plannerDisplayDescription(value){
    value = String(value || "").trim();

    if(value === ""){
        return "";
    }

    return value.replace(/\S+/g, function(word){
        return word.charAt(0).toUpperCase() + word.slice(1);
    });
}

function plannerTaskDisplayTitle(task){
    if(!task){
        return "-";
    }

    if(task.is_holiday){
        return task.title || "-";
    }

    const description = plannerDisplayDescription(task.description || "");

    if(description === ""){
        return task.title || "-";
    }

    return (task.title || "-") + " - " + description;
}

function plannerTaskDisplayTime(task){
    const time = String((task && (task.task_time_display || task.task_time)) || "").trim();

    return time === "" ? "" : "-" + time;
}

function plannerSyncCustomTaskFields(){
    const titleSelect = document.getElementById("plannerTitle");
    const customTitleWrap = document.getElementById("plannerCustomTitleWrap");
    const customColorWrap = document.getElementById("plannerCustomColorWrap");
    const customTitle = document.getElementById("plannerCustomTitle");
    const customColor = document.getElementById("plannerCustomColor");
    const isOther = titleSelect && titleSelect.value === "Other";

    customTitleWrap?.classList.toggle("d-none", !isOther);
    customColorWrap?.classList.toggle("d-none", !isOther);

    if(customTitle){
        customTitle.required = !!isOther;
    }

    if(customColor && isOther){
        document.getElementById("plannerColor").value = "#ffc107";
    }
}

function setPlannerTitleValue(value){
    const titleSelect = document.getElementById("plannerTitle");

    if(!titleSelect){
        return;
    }

    value = String(value || "");

    if(value !== "" && plannerTaskColors[value] === undefined){
        titleSelect.value = "Other";
        document.getElementById("plannerCustomTitle").value = value;
        document.getElementById("plannerCustomColor").value = "#ffc107";
    }
    else{
        titleSelect.value = value;
        document.getElementById("plannerCustomTitle").value = "";
        document.getElementById("plannerCustomColor").value = plannerTaskColors[value] || "#0d6efd";
    }

    document.getElementById("plannerColor").value = plannerTaskColors[titleSelect.value] || "#0d6efd";
    plannerSyncCustomTaskFields();
}

function normalizePlannerPicList(values){
    const list = Array.isArray(values) ? values : [];
    const clean = [];
    const seen = {};

    list.forEach(function(value){
        value = String(value || "").trim();

        if(value === ""){
            return;
        }

        const key = value.toLowerCase();

        if(seen[key]){
            return;
        }

        seen[key] = true;
        clean.push(value);
    });

    return clean;
}

function plannerCurrentTaskId(){
    const input = document.getElementById("plannerTaskId");

    return input ? String(input.value || "") : "";
}

function plannerSelectedDateRange(){
    const startInput = document.getElementById("plannerStartDate");
    const endInput = document.getElementById("plannerEndDate");
    const start = startInput ? String(startInput.value || "") : "";
    const end = endInput && endInput.value ? String(endInput.value) : start;

    if(start === ""){
        return null;
    }

    return {
        start:start,
        end:end || start
    };
}

function plannerSelectedTaskTime(){
    const input = document.getElementById("plannerTaskTime");

    return input ? String(input.value || "") : "";
}

function plannerRangesOverlap(startA, endA, startB, endB){
    return String(startA || "") <= String(endB || startB || "") && String(endA || startA || "") >= String(startB || "");
}

function plannerBusyPicKeysForSelectedDates(){
    const range = plannerSelectedDateRange();
    const currentTaskId = plannerCurrentTaskId();
    const selectedTime = plannerSelectedTaskTime();
    const busy = {};

    if(!range || selectedTime === ""){
        return busy;
    }

    plannerTasks.forEach(function(task){
        if(!task || task.is_holiday){
            return;
        }

        if(currentTaskId !== "" && String(task.id) === currentTaskId){
            return;
        }

        if(!plannerRangesOverlap(task.start_date, task.end_date || task.start_date, range.start, range.end)){
            return;
        }

        if(String(task.task_time || "") !== selectedTime){
            return;
        }

        (Array.isArray(task.person_in_charge) ? task.person_in_charge : []).forEach(function(name){
            name = String(name || "").trim();

            if(name !== ""){
                busy[name.toLowerCase()] = true;
            }
        });
    });

    return busy;
}

function renderPlannerPicSelect(){
    const select = document.getElementById("plannerPicSelect");
    const selectedWrap = document.getElementById("plannerPicSelected");

    if(!select || !selectedWrap){
        return;
    }

    const selectedKeys = {};
    const busyKeys = plannerBusyPicKeysForSelectedDates();
    plannerSelectedPics = plannerSelectedPics.filter(function(name){
        const key = String(name || "").toLowerCase();
        return !busyKeys[key];
    });

    plannerSelectedPics.forEach(function(name){
        selectedKeys[name.toLowerCase()] = true;
    });

    select.innerHTML = '<option value="">Select PIC</option>';

    plannerPersonOptions.forEach(function(name){
        const key = String(name).toLowerCase();

        if(selectedKeys[key] || busyKeys[key]){
            return;
        }

        select.add(new Option(name, name));
    });

    selectedWrap.innerHTML = plannerSelectedPics.map(function(name, index){
        return '<span class="planner-pic-chip">' +
            plannerEscapeHtml(name) +
            '<input type="hidden" name="person_in_charge[]" value="' + plannerEscapeHtml(name) + '">' +
            '<button type="button" data-pic-index="' + index + '" title="Remove">&times;</button>' +
        '</span>';
    }).join("");
}

function addPlannerPic(value){
    value = String(value || "").trim();

    if(value === ""){
        return;
    }

    plannerSelectedPics = normalizePlannerPicList(plannerSelectedPics.concat([value]));
    renderPlannerPicSelect();
}

function plannerDatePlusDays(value, days){
    const parts = String(value || "").split("-").map(Number);

    if(parts.length !== 3 || parts.some(function(part){ return !Number.isFinite(part); })){
        return "";
    }

    const date = new Date(parts[0], parts[1] - 1, parts[2]);
    date.setDate(date.getDate() + days);

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, "0"),
        String(date.getDate()).padStart(2, "0")
    ].join("-");
}

function syncPlannerRangeFields(){
    const start = document.getElementById("plannerRangeStart");
    const end = document.getElementById("plannerRangeEnd");

    if(!start || !end || start.value === ""){
        return;
    }

    if(plannerCalendarView === "week"){
        end.value = plannerDatePlusDays(start.value, 6);
        return;
    }

    end.min = start.value;

    if(end.value === "" || end.value < start.value){
        end.value = start.value;
    }
}

function openPlannerRangeModal(){
    const modalElement = document.getElementById("plannerRangeModal");

    if(!modalElement){
        return;
    }

    syncPlannerRangeFields();
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function navigatePlannerPeriod(period){
    period = String(period || "this");

    if(period === "range"){
        openPlannerRangeModal();
        return;
    }

    const params = new URLSearchParams({
        view:plannerCalendarView,
        period:period
    });
    window.location.href = plannerPageUrl + "?" + params.toString();
}

function hidePlannerTaskHover(){
    const card = document.getElementById("plannerTaskHoverCard");

    if(!card){
        return;
    }

    card.classList.remove("is-visible");
}

function positionPlannerTaskHover(card, event){
    const gap = 14;
    const rect = card.getBoundingClientRect();
    let left = event.clientX + gap;
    let top = event.clientY + gap;

    if(left + rect.width > window.innerWidth - gap){
        left = event.clientX - rect.width - gap;
    }

    if(top + rect.height > window.innerHeight - gap){
        top = event.clientY - rect.height - gap;
    }

    card.style.left = Math.max(gap, left) + "px";
    card.style.top = Math.max(gap, top) + "px";
}

function showPlannerTaskHover(task, event){
    const card = document.getElementById("plannerTaskHoverCard");

    if(!card || !task){
        return;
    }

    const pics = Array.isArray(task.person_in_charge) ? task.person_in_charge : [];
    const picHtml = pics.length > 0
        ? pics.map(function(name){
            return '<span class="planner-hover-pic">' + plannerEscapeHtml(name) + '</span>';
        }).join("")
        : '<span class="planner-hover-pic">No PIC</span>';

    card.innerHTML =
        '<div class="planner-hover-title">' + plannerEscapeHtml(plannerTaskDisplayTitle(task)) + '</div>' +
        '<div class="planner-hover-pic-list">' + picHtml + '</div>';
    card.classList.add("is-visible");
    positionPlannerTaskHover(card, event);
}

function openPlannerAddModal(dateValue){
    if(!plannerCanAdd){
        return;
    }

    plannerSelectedTask = null;
    document.getElementById("plannerAction").value = "add";
    document.getElementById("plannerTaskId").value = "";
    document.getElementById("plannerTaskModalTitle").innerHTML =
        '<i class="fa fa-calendar-plus text-warning"></i> Add Planner Task';
    setPlannerTitleValue("");
    plannerSelectedPics = [];
    document.getElementById("plannerDescription").value = "";
    document.getElementById("plannerStartDate").value = dateValue || "<?= plannerEscape(date("Y-m-d")) ?>";
    document.getElementById("plannerEndDate").value = dateValue || "";
    document.getElementById("plannerTaskTime").value = "";
    renderPlannerPicSelect();
    document.getElementById("plannerColor").value = "#0d6efd";
    document.getElementById("plannerSaveButton")?.classList.remove("d-none");
    document.getElementById("plannerDeleteButton")?.classList.add("d-none");

    bootstrap.Modal.getOrCreateInstance(document.getElementById("plannerTaskModal")).show();
}

function openPlannerEditModal(task){
    if(!task || !plannerCanEdit){
        return;
    }

    plannerSelectedTask = task;
    document.getElementById("plannerAction").value = "edit";
    document.getElementById("plannerTaskId").value = task.id || "";
    document.getElementById("plannerTaskModalTitle").innerHTML =
        '<i class="fa fa-pen text-warning"></i> Edit Planner Task';
    setPlannerTitleValue(task.title || "");
    plannerSelectedPics = normalizePlannerPicList(task.person_in_charge || []);
    document.getElementById("plannerDescription").value = task.description || "";
    document.getElementById("plannerStartDate").value = task.start_date || "";
    document.getElementById("plannerEndDate").value = task.end_date || task.start_date || "";
    document.getElementById("plannerTaskTime").value = task.task_time || "";
    renderPlannerPicSelect();
    document.getElementById("plannerColor").value = plannerTaskColors[task.title] || task.color || "#0d6efd";
    document.getElementById("plannerSaveButton")?.classList.remove("d-none");
    document.getElementById("plannerDeleteButton")?.classList.toggle("d-none", !plannerCanDelete);

    bootstrap.Modal.getInstance(document.getElementById("plannerViewModal"))?.hide();
    bootstrap.Modal.getOrCreateInstance(document.getElementById("plannerTaskModal")).show();
}

function openPlannerViewModal(task){
    if(!task){
        return;
    }

    plannerSelectedTask = task;
    const dateText = task.start_date === task.end_date
        ? plannerFormatDate(task.start_date)
        : plannerFormatDate(task.start_date) + " - " + plannerFormatDate(task.end_date);
    const picText = Array.isArray(task.person_in_charge) && task.person_in_charge.length > 0
        ? task.person_in_charge.join(", ")
        : "-";
    const timeText = task.task_time_display || "-";

    document.getElementById("plannerViewContent").innerHTML =
        '<div class="planner-detail-row">' +
            '<div class="planner-detail-label">Title</div>' +
            '<div class="planner-detail-value">' + plannerEscapeHtml(task.title || "-") + '</div>' +
        '</div>' +
        '<div class="planner-detail-row">' +
            '<div class="planner-detail-label">Date</div>' +
            '<div class="planner-detail-value">' + plannerEscapeHtml(dateText) + '</div>' +
        '</div>' +
        '<div class="planner-detail-row">' +
            '<div class="planner-detail-label">Time</div>' +
            '<div class="planner-detail-value">' + plannerEscapeHtml(timeText) + '</div>' +
        '</div>' +
        '<div class="planner-detail-row">' +
            '<div class="planner-detail-label">Created By</div>' +
            '<div class="planner-detail-value">' + plannerEscapeHtml(task.created_by || "-") + '</div>' +
        '</div>' +
        '<div class="planner-detail-row">' +
            '<div class="planner-detail-label">PIC</div>' +
            '<div class="planner-detail-value">' + plannerEscapeHtml(picText) + '</div>' +
        '</div>' +
        '<div class="planner-detail-row">' +
            '<div class="planner-detail-label">Description</div>' +
            '<div class="planner-detail-value">' + plannerEscapeHtml(task.description || "-") + '</div>' +
        '</div>';

    document.getElementById("plannerViewEditButton")?.classList.toggle("d-none", !plannerCanEdit || !!task.is_holiday);
    document.getElementById("plannerViewDeleteButton")?.classList.toggle("d-none", !plannerCanDelete || !!task.is_holiday);
    bootstrap.Modal.getOrCreateInstance(document.getElementById("plannerViewModal")).show();
}

function submitPlannerDelete(){
    if(!plannerCanDelete){
        return false;
    }

    if(!confirm("Delete this planner task?")){
        return false;
    }

    document.getElementById("plannerAction").value = "delete";
    return true;
}

function deletePlannerSelectedTask(){
    if(!plannerCanDelete || !plannerSelectedTask){
        return;
    }

    if(!confirm("Delete this planner task?")){
        return;
    }

    const deleteTaskId = document.getElementById("plannerDeleteTaskId");
    const deleteForm = document.getElementById("plannerDeleteForm");

    if(!deleteTaskId || !deleteForm){
        return;
    }

    deleteTaskId.value = plannerSelectedTask.id || "";
    deleteForm.submit();
}

function renderPlannerCalendar(){
    const grid = document.getElementById("plannerGrid");

    if(!grid){
        return;
    }

    grid.innerHTML = plannerDays.map(function(day){
        const classes = ["planner-day"];

        if(!day.is_in_range){
            classes.push("is-muted");
        }

        if(day.is_today){
            classes.push("is-today");
        }

        const tasks = plannerTasksForDate(day.date);
        const taskHtml = tasks.map(function(task){
            const color = task.color || "#0d6efd";
            const textColor = plannerTextColor(color);
            const holidayClass = task.is_holiday ? " is-holiday" : "";
            const displayTitle = plannerTaskDisplayTitle(task);
            const displayTime = plannerTaskDisplayTime(task);

            return '<button type="button" class="planner-task' + holidayClass + '" style="background:' + plannerEscapeHtml(color) + ';color:' + plannerEscapeHtml(textColor) + ';" data-task-id="' + plannerEscapeHtml(task.id) + '">' +
                '<span class="planner-task-main">' + plannerEscapeHtml(displayTitle) + '</span>' +
                (displayTime !== "" ? '<span class="planner-task-time">' + plannerEscapeHtml(displayTime) + '</span>' : '') +
            '</button>';
        }).join("");

        return '<div class="' + classes.join(" ") + '" data-date="' + plannerEscapeHtml(day.date) + '">' +
            '<div class="planner-day-number">' +
                '<span>' + plannerEscapeHtml(day.day) + '</span>' +
            '</div>' +
            '<div class="planner-task-list">' + taskHtml + '</div>' +
            (tasks.length === 0 && plannerCanAdd ? '<div class="planner-empty-hint">Click to add</div>' : '') +
        '</div>';
    }).join("");
}

document.addEventListener("click", function(event){
    const taskButton = event.target.closest(".planner-task");

    if(taskButton){
        event.stopPropagation();
        const taskId = String(taskButton.getAttribute("data-task-id") || "");
        const task = plannerAllItems.find(function(item){
            return String(item.id) === taskId;
        });

        openPlannerViewModal(task);
        return;
    }

    const day = event.target.closest(".planner-day");

    if(day && plannerCanAdd){
        openPlannerAddModal(day.getAttribute("data-date"));
    }
});

document.addEventListener("mousemove", function(event){
    const taskButton = event.target.closest(".planner-task");

    if(!taskButton){
        hidePlannerTaskHover();
        return;
    }

    const taskId = String(taskButton.getAttribute("data-task-id") || "");
    const task = plannerAllItems.find(function(item){
        return String(item.id) === taskId;
    });

    showPlannerTaskHover(task, event);
});

document.addEventListener("mouseleave", hidePlannerTaskHover);

document.getElementById("plannerViewEditButton")?.addEventListener("click", function(){
    openPlannerEditModal(plannerSelectedTask);
});

document.getElementById("plannerViewDeleteButton")?.addEventListener("click", deletePlannerSelectedTask);

document.getElementById("plannerTitle")?.addEventListener("change", function(){
    document.getElementById("plannerColor").value = this.value === "Other"
        ? "#ffc107"
        : (plannerTaskColors[this.value] || "#0d6efd");
    plannerSyncCustomTaskFields();
});

document.getElementById("plannerCustomColor")?.addEventListener("input", function(){
    if(document.getElementById("plannerTitle")?.value === "Other"){
        document.getElementById("plannerColor").value = "#ffc107";
    }
});

document.getElementById("plannerPicSelect")?.addEventListener("change", function(){
    addPlannerPic(this.value);
});

document.getElementById("plannerStartDate")?.addEventListener("change", renderPlannerPicSelect);
document.getElementById("plannerEndDate")?.addEventListener("change", renderPlannerPicSelect);
document.getElementById("plannerTaskTime")?.addEventListener("change", renderPlannerPicSelect);

document.getElementById("plannerPicSelected")?.addEventListener("click", function(event){
    const removeButton = event.target.closest("button[data-pic-index]");

    if(!removeButton){
        return;
    }

    const index = parseInt(removeButton.getAttribute("data-pic-index") || "-1", 10);

    if(index >= 0){
        plannerSelectedPics.splice(index, 1);
        renderPlannerPicSelect();
    }
});

document.getElementById("plannerPeriodSelect")?.addEventListener("change", function(){
    navigatePlannerPeriod(this.value);
});

document.getElementById("plannerRangeStart")?.addEventListener("change", syncPlannerRangeFields);
document.querySelector("#plannerRangeModal form")?.addEventListener("submit", syncPlannerRangeFields);
document.getElementById("plannerRangeModal")?.addEventListener("hidden.bs.modal", function(){
    const periodSelect = document.getElementById("plannerPeriodSelect");

    if(periodSelect){
        periodSelect.value = plannerCalendarPeriod;
    }
});

syncPlannerRangeFields();
renderPlannerPicSelect();
renderPlannerCalendar();
</script>

<?php include "layout/footer.php"; ?>
