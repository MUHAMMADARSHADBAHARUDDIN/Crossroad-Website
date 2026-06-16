<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/contract_task_schema.php";

if(!isset($_SESSION['username'])){
    exit("<div class='alert alert-danger mb-0'>No session.</div>");
}

ensureContractTaskCompletionSchema($mysqli);

function taskEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function taskTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function taskColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);
    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

function taskFormatDateRange($startDate, $endDate){
    if(empty($startDate) || $startDate === "0000-00-00"){
        return "No date assigned";
    }

    $start = date("d/m/Y", strtotime($startDate));

    if(empty($endDate) || $endDate === "0000-00-00" || $startDate === $endDate){
        return $start;
    }

    return $start . " - " . date("d/m/Y", strtotime($endDate));
}

function taskFormatDateTime($value){
    $value = trim((string)($value ?? ""));

    if($value === "" || $value === "0000-00-00 00:00:00"){
        return "";
    }

    $timestamp = strtotime($value);

    if($timestamp === false){
        return $value;
    }

    return date("d/m/Y h:i A", $timestamp);
}

$contractId = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;
if($contractId <= 0){
    exit("<div class='alert alert-warning mb-0'>Invalid contract.</div>");
}

if(!taskTableExists($mysqli, "contract_tasks")){
    exit("<div class='alert alert-warning mb-0'><b>contract_tasks</b> table not found.</div>");
}
if(!taskColumnExists($mysqli, "contract_tasks", "contract_id")){
    exit("<div class='alert alert-warning mb-0'><b>contract_id</b> column not found in contract_tasks table.</div>");
}

$contractStmt = $mysqli->prepare("SELECT no, created_by FROM project_inventory WHERE no = ? LIMIT 1");
if(!$contractStmt){
    exit("<div class='alert alert-danger mb-0'>SQL Error: " . taskEscape($mysqli->error) . "</div>");
}
$contractStmt->bind_param("i", $contractId);
$contractStmt->execute();
$contract = $contractStmt->get_result()->fetch_assoc();
if(!$contract){
    exit("<div class='alert alert-warning mb-0'>Contract not found.</div>");
}

$createdBy = $contract['created_by'] ?? "";
$canAddTask = hasContractTaskAddAccess($mysqli, $createdBy);
$canEditTask = hasContractTaskEditAccess($mysqli, $createdBy);
$canDeleteTask = hasContractTaskDeleteAccess($mysqli, $createdBy);

$idColumn = taskColumnExists($mysqli, "contract_tasks", "id") ? "id" : "no";
if(!taskColumnExists($mysqli, "contract_tasks", $idColumn)){
    exit("<div class='alert alert-danger mb-0'>Task ID column not found.</div>");
}

if(taskColumnExists($mysqli, "contract_tasks", "task_text")){
    $textColumn = "task_text";
}elseif(taskColumnExists($mysqli, "contract_tasks", "task_name")){
    $textColumn = "task_name";
}elseif(taskColumnExists($mysqli, "contract_tasks", "title")){
    $textColumn = "title";
}elseif(taskColumnExists($mysqli, "contract_tasks", "description")){
    $textColumn = "description";
}else{
    exit("<div class='alert alert-danger mb-0'>Task text column not found.</div>");
}

$hasIsCompleted = taskColumnExists($mysqli, "contract_tasks", "is_completed");
$hasCompleted = taskColumnExists($mysqli, "contract_tasks", "completed");
$hasStatus = taskColumnExists($mysqli, "contract_tasks", "status");
$hasTaskDates = taskColumnExists($mysqli, "contract_tasks", "task_start_date")
    && taskColumnExists($mysqli, "contract_tasks", "task_end_date");
$hasCreatedBy = taskColumnExists($mysqli, "contract_tasks", "created_by");
$hasCompletedBy = taskColumnExists($mysqli, "contract_tasks", "completed_by");
$hasCompletedAt = taskColumnExists($mysqli, "contract_tasks", "completed_at");

if($hasIsCompleted){
    $completeSql = "CASE WHEN is_completed = 1 THEN 1 ELSE 0 END AS is_done";
}elseif($hasCompleted){
    $completeSql = "CASE WHEN completed = 1 THEN 1 ELSE 0 END AS is_done";
}elseif($hasStatus){
    $completeSql = "CASE WHEN LOWER(status) IN ('completed','complete','done') THEN 1 ELSE 0 END AS is_done";
}else{
    $completeSql = "0 AS is_done";
}

$dateSelect = $hasTaskDates
    ? ", task_start_date, task_end_date"
    : ", NULL AS task_start_date, NULL AS task_end_date";
$metaSelect = ""
    . ($hasCreatedBy ? ", created_by" : ", '' AS created_by")
    . ($hasCompletedBy ? ", completed_by" : ", '' AS completed_by")
    . ($hasCompletedAt ? ", completed_at" : ", NULL AS completed_at");
$orderColumn = taskColumnExists($mysqli, "contract_tasks", "created_at") ? "created_at" : $idColumn;

$sql = "
    SELECT `$idColumn` AS task_id, `$textColumn` AS task_text, $completeSql $dateSelect $metaSelect
    FROM contract_tasks
    WHERE contract_id = ?
    ORDER BY `$orderColumn` ASC
";
$stmt = $mysqli->prepare($sql);
if(!$stmt){
    exit("<div class='alert alert-danger mb-0'>SQL Error: " . taskEscape($mysqli->error) . "</div>");
}
$stmt->bind_param("i", $contractId);
$stmt->execute();
$result = $stmt->get_result();

$tasks = [];
$total = 0;
$done = 0;
while($row = $result->fetch_assoc()){
    $tasks[] = $row;
    $total++;
    if((int)$row['is_done'] === 1){ $done++; }
}
$percent = $total > 0 ? round(($done / $total) * 100) : 0;
?>
<div class="task-checklist-header">
    <div>
        <b>Checklist Progress</b>
        <div class="text-muted small"><?= $done ?> of <?= $total ?> task completed</div>
    </div>
    <div class="task-summary-pill"><?= $percent ?>%</div>
</div>

<div class="progress mb-3" style="height:18px; border-radius:999px;">
    <div class="progress-bar <?= $percent >= 70 ? 'bg-success' : ($percent >= 40 ? 'bg-warning' : 'bg-danger') ?>"
         style="width:<?= $percent ?>%; font-size:11px; font-weight:700;"><?= $percent ?>%</div>
</div>

<?php if($canAddTask): ?>
<div class="task-add-box">
    <div class="mb-2">
        <input type="text" id="newContractTaskText" class="form-control" placeholder="Add new checklist item..." autocomplete="off">
    </div>
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small mb-1">Task Start Date</label>
            <input type="date" id="newTaskStartDate" class="form-control">
        </div>
        <div class="col-md-5">
            <label class="form-label small mb-1">Task End Date</label>
            <input type="date" id="newTaskEndDate" class="form-control">
        </div>
        <div class="col-md-2 d-grid">
            <button type="button" class="btn btn-warning" id="addTaskBtn" onclick="addContractTask()">
                <i class="fa fa-plus"></i> Add
            </button>
        </div>
    </div>
    <small class="text-muted d-block mt-2">Assign a date range for Preventive Management items that must appear on the dashboard bulletin.</small>
</div>
<?php endif; ?>

<?php if($total <= 0): ?>
<div class="task-empty-state">
    <i class="fa fa-list-check fa-2x mb-2"></i>
    <div>No checklist item yet.</div>
    <?php if($canAddTask): ?><small>Add your first checklist item above.</small><?php else: ?><small>You do not have permission to add checklist items.</small><?php endif; ?>
</div>
<?php else: ?>
<div class="task-checklist">
<?php foreach($tasks as $task): ?>
    <?php
    $taskId = (int)$task['task_id'];
    $taskText = $task['task_text'] ?? "";
    $taskStartDate = $task['task_start_date'] ?? "";
    $taskEndDate = $task['task_end_date'] ?? "";
    $taskCreatedBy = trim((string)($task['created_by'] ?? ""));
    $completedBy = trim((string)($task['completed_by'] ?? ""));
    $completedAt = trim((string)($task['completed_at'] ?? ""));
    $isDone = (int)$task['is_done'] === 1;
    $hasAssignedDate = !empty($taskStartDate) && $taskStartDate !== "0000-00-00";
    $completedAtText = taskFormatDateTime($completedAt);
    $taskDateText = (!$hasAssignedDate && $isDone && $completedAtText !== "")
        ? "Ticked on " . $completedAtText
        : taskFormatDateRange($taskStartDate, $taskEndDate);
    $taskMetaParts = [];

    if($taskCreatedBy !== ""){
        $taskMetaParts[] = "Created by " . $taskCreatedBy;
    }

    if($isDone){
        if($completedBy !== ""){
            $taskMetaParts[] = "Ticked by " . $completedBy;
        }

    }
    ?>
    <div class="contract-task-item <?= $isDone ? 'task-completed' : '' ?>" data-task-id="<?= $taskId ?>" tabindex="-1">
        <div class="contract-task-left">
            <input type="checkbox" class="form-check-input contract-task-checkbox"
                   <?= $isDone ? 'checked' : '' ?> <?= !$canEditTask ? 'disabled' : '' ?>
                   onchange="toggleContractTask(<?= $taskId ?>, this.checked, this)">
            <div>
                <div class="contract-task-text"><?= taskEscape($taskText) ?></div>
                <div class="contract-task-meta">
                    <span class="me-2"><?= $isDone ? 'Completed' : 'Pending' ?></span>
                    <span><i class="fa fa-calendar-days"></i> <?= taskEscape($taskDateText) ?></span>
                </div>
                <?php if(!empty($taskMetaParts)): ?>
                    <div class="contract-task-meta">
                        <span><i class="fa fa-user-check"></i> <?= taskEscape(implode(" | ", $taskMetaParts)) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if($canEditTask || $canDeleteTask): ?>
        <div class="contract-task-actions">
            <?php if($canEditTask): ?>
                <button type="button" class="btn btn-sm btn-primary task-icon-btn" title="Edit task"
                        onclick='openEditTaskModal(<?= $taskId ?>, <?= taskEscape(json_encode($taskText)) ?>, <?= taskEscape(json_encode($taskStartDate)) ?>, <?= taskEscape(json_encode($taskEndDate)) ?>)'>
                    <i class="fa fa-pen"></i>
                </button>
            <?php endif; ?>
            <?php if($canDeleteTask): ?>
                <button type="button" class="btn btn-sm btn-danger task-icon-btn" title="Delete task" onclick="deleteContractTask(<?= $taskId ?>)">
                    <i class="fa fa-trash"></i>
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
