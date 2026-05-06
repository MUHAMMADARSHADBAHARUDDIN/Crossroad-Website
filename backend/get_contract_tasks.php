<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("<div class='alert alert-danger mb-0'>No session.</div>");
}

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

$contractId = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;

if($contractId <= 0){
    exit("<div class='alert alert-warning mb-0'>Invalid contract.</div>");
}

if(!taskTableExists($mysqli, "contract_tasks")){
    exit("
        <div class='alert alert-warning mb-0'>
            <b>contract_tasks</b> table not found.
        </div>
    ");
}

if(!taskColumnExists($mysqli, "contract_tasks", "contract_id")){
    exit("
        <div class='alert alert-warning mb-0'>
            <b>contract_id</b> column not found in contract_tasks table.
        </div>
    ");
}

$contractStmt = $mysqli->prepare("
    SELECT no, created_by
    FROM project_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$contractStmt){
    exit("<div class='alert alert-danger mb-0'>SQL Error: " . taskEscape($mysqli->error) . "</div>");
}

$contractStmt->bind_param("i", $contractId);
$contractStmt->execute();
$contractResult = $contractStmt->get_result();

if($contractResult->num_rows <= 0){
    exit("<div class='alert alert-warning mb-0'>Contract not found.</div>");
}

$contract = $contractResult->fetch_assoc();
$createdBy = $contract['created_by'] ?? "";

/*
|--------------------------------------------------------------------------
| FIXED TASK PERMISSIONS
|--------------------------------------------------------------------------
| Add button uses contracts_task
| Edit button + checkbox uses contracts_task_edit
| Delete button uses contracts_task_delete
|--------------------------------------------------------------------------
*/
$canAddTask = hasContractTaskAddAccess($mysqli, $createdBy);
$canEditTask = hasContractTaskEditAccess($mysqli, $createdBy);
$canDeleteTask = hasContractTaskDeleteAccess($mysqli, $createdBy);

$idColumn = taskColumnExists($mysqli, "contract_tasks", "id") ? "id" : "no";

if(!taskColumnExists($mysqli, "contract_tasks", $idColumn)){
    exit("<div class='alert alert-danger mb-0'>Task ID column not found.</div>");
}

if(taskColumnExists($mysqli, "contract_tasks", "task_text")){
    $textColumn = "task_text";
}
elseif(taskColumnExists($mysqli, "contract_tasks", "task_name")){
    $textColumn = "task_name";
}
elseif(taskColumnExists($mysqli, "contract_tasks", "title")){
    $textColumn = "title";
}
elseif(taskColumnExists($mysqli, "contract_tasks", "description")){
    $textColumn = "description";
}
else{
    exit("<div class='alert alert-danger mb-0'>Task text column not found.</div>");
}

$hasIsCompleted = taskColumnExists($mysqli, "contract_tasks", "is_completed");
$hasCompleted = taskColumnExists($mysqli, "contract_tasks", "completed");
$hasStatus = taskColumnExists($mysqli, "contract_tasks", "status");

if($hasIsCompleted){
    $completeSql = "CASE WHEN is_completed = 1 THEN 1 ELSE 0 END AS is_done";
}
elseif($hasCompleted){
    $completeSql = "CASE WHEN completed = 1 THEN 1 ELSE 0 END AS is_done";
}
elseif($hasStatus){
    $completeSql = "CASE WHEN LOWER(status) IN ('completed','complete','done') THEN 1 ELSE 0 END AS is_done";
}
else{
    $completeSql = "0 AS is_done";
}

$orderColumn = $idColumn;

if(taskColumnExists($mysqli, "contract_tasks", "created_at")){
    $orderColumn = "created_at";
}

$sql = "
    SELECT
        `$idColumn` AS task_id,
        `$textColumn` AS task_text,
        $completeSql
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

    if((int)$row['is_done'] === 1){
        $done++;
    }
}

$percent = 0;

if($total > 0){
    $percent = round(($done / $total) * 100);
}
?>

<div class="task-checklist-header">
    <div>
        <b>Checklist Progress</b>
        <div class="text-muted small">
            <?= $done ?> of <?= $total ?> task completed
        </div>
    </div>

    <div class="task-summary-pill">
        <?= $percent ?>%
    </div>
</div>

<div class="progress mb-3" style="height:18px; border-radius:999px;">
    <div
        class="progress-bar <?= $percent >= 70 ? 'bg-success' : ($percent >= 40 ? 'bg-warning' : 'bg-danger') ?>"
        style="width:<?= $percent ?>%; font-size:11px; font-weight:700;"
    >
        <?= $percent ?>%
    </div>
</div>

<?php if($canAddTask): ?>
<div class="task-add-box">
    <div class="input-group">
        <input
            type="text"
            id="newContractTaskText"
            class="form-control"
            placeholder="Add new checklist item..."
            autocomplete="off"
        >
        <button
            type="button"
            class="btn btn-warning"
            id="addTaskBtn"
            onclick="addContractTask()"
        >
            <i class="fa fa-plus"></i> Add
        </button>
    </div>
</div>
<?php endif; ?>

<?php if($total <= 0): ?>

<div class="task-empty-state">
    <i class="fa fa-list-check fa-2x mb-2"></i>
    <div>No checklist item yet.</div>

    <?php if($canAddTask): ?>
        <small>Add your first checklist item above.</small>
    <?php else: ?>
        <small>You do not have permission to add checklist items.</small>
    <?php endif; ?>
</div>

<?php else: ?>

<div class="task-checklist">

<?php foreach($tasks as $task): ?>

<?php
$taskId = (int)$task['task_id'];
$taskText = $task['task_text'] ?? "";
$isDone = (int)$task['is_done'] === 1;
?>

<div class="contract-task-item <?= $isDone ? 'task-completed' : '' ?>">

    <div class="contract-task-left">
        <input
            type="checkbox"
            class="form-check-input contract-task-checkbox"
            <?= $isDone ? 'checked' : '' ?>
            <?= !$canEditTask ? 'disabled' : '' ?>
            onchange="toggleContractTask(<?= $taskId ?>, this.checked, this)"
        >

        <div>
            <div class="contract-task-text">
                <?= taskEscape($taskText) ?>
            </div>

            <div class="contract-task-meta">
                <?= $isDone ? 'Completed' : 'Pending' ?>
            </div>
        </div>
    </div>

    <?php if($canEditTask || $canDeleteTask): ?>
    <div class="contract-task-actions">

        <?php if($canEditTask): ?>
        <button
            type="button"
            class="btn btn-sm btn-primary task-icon-btn"
            title="Edit task"
            onclick='openEditTaskModal(<?= $taskId ?>, <?= json_encode($taskText) ?>)'
        >
            <i class="fa fa-pen"></i>
        </button>
        <?php endif; ?>

        <?php if($canDeleteTask): ?>
        <button
            type="button"
            class="btn btn-sm btn-danger task-icon-btn"
            title="Delete task"
            onclick="deleteContractTask(<?= $taskId ?>)"
        >
            <i class="fa fa-trash"></i>
        </button>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>