<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/contract_task_schema.php";

if(!isset($_SESSION['username'])){
    exit("<div class='alert alert-danger mb-0'>No session.</div>");
}

ensureContractTaskCompletionSchema($mysqli);
ensureContractTaskDocumentSchema($mysqli);

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
$canViewTaskDocument = hasContractTaskDocumentViewAccess($mysqli, $createdBy);
$canUploadTaskDocument = hasContractTaskDocumentUploadAccess($mysqli, $createdBy);
$canDownloadTaskDocument = hasContractTaskDocumentDownloadAccess($mysqli, $createdBy);
$canDeleteTaskDocument = hasContractTaskDocumentDeleteAccess($mysqli, $createdBy);
$canOpenTaskDocument = $canViewTaskDocument || $canUploadTaskDocument || $canDownloadTaskDocument || $canDeleteTaskDocument;
$canViewClaim = hasContractClaimViewAccess($mysqli);

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
$hasClaimAmount = taskColumnExists($mysqli, "contract_tasks", "claim_amount");
$hasInvoice = taskColumnExists($mysqli, "contract_tasks", "invoice");
$hasTaskType = taskColumnExists($mysqli, "contract_tasks", "task_type");
$hasRemark = taskColumnExists($mysqli, "contract_tasks", "remark");
$hasNotificationEmail = taskColumnExists($mysqli, "contract_tasks", "notification_email");

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
$claimSelect = ($hasClaimAmount && $canViewClaim) ? ", claim_amount" : ", NULL AS claim_amount";
$invoiceSelect = $hasInvoice ? ", invoice" : ", NULL AS invoice";
$taskTypeSelect = $hasTaskType ? ", task_type" : ", NULL AS task_type";
$remarkSelect = $hasRemark ? ", remark" : ", NULL AS remark";
$notificationEmailSelect = $hasNotificationEmail ? ", notification_email" : ", NULL AS notification_email";
$metaSelect = ""
    . ($hasCreatedBy ? ", created_by" : ", '' AS created_by")
    . ($hasCompletedBy ? ", completed_by" : ", '' AS completed_by")
    . ($hasCompletedAt ? ", completed_at" : ", NULL AS completed_at");
$orderColumn = taskColumnExists($mysqli, "contract_tasks", "created_at") ? "created_at" : $idColumn;

$sql = "
    SELECT `$idColumn` AS task_id, `$textColumn` AS task_text, $completeSql $dateSelect $claimSelect $invoiceSelect $taskTypeSelect $remarkSelect $notificationEmailSelect $metaSelect
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

$documentCounts = [];

if($canOpenTaskDocument){
    $docCountStmt = $mysqli->prepare("
        SELECT task_id, COUNT(*) AS document_count
        FROM contract_task_documents
        WHERE contract_id = ?
        GROUP BY task_id
    ");

    if($docCountStmt){
        $docCountStmt->bind_param("i", $contractId);
        $docCountStmt->execute();
        $docCountResult = $docCountStmt->get_result();

        while($docRow = $docCountResult->fetch_assoc()){
            $documentCounts[(int)$docRow['task_id']] = (int)$docRow['document_count'];
        }
    }
}

$percent = $total > 0 ? round(($done / $total) * 100) : 0;
?>
<div class="task-checklist-header">
    <div>
        <b>Project Checklist</b>
        <div class="text-muted small"><?= $done ?> of <?= $total ?> items completed</div>
    </div>
    <div class="task-summary-pill"><?= $percent ?>%</div>
</div>

<div class="task-progress-track" aria-label="Checklist progress: <?= $percent ?> percent">
    <div class="task-progress-value <?= $percent >= 70 ? 'bg-success' : ($percent >= 40 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $percent ?>%"></div>
</div>

<?php if($total <= 0): ?>
<div class="task-empty-state">
    <i class="fa fa-list-check fa-2x mb-2"></i>
    <div class="fw-semibold">No checklist item yet.</div>
    <?php if($canAddTask): ?><small>Use the Add Checklist Item button to create the first entry.</small><?php else: ?><small>You do not have permission to add checklist items.</small><?php endif; ?>
</div>
<?php else: ?>
<div class="contract-checklist-scroll">
<table class="contract-checklist-table" aria-label="Contract checklist">
<thead>
<tr>
    <th style="width:25%">Checklist Item</th>
    <th style="width:13%">Status</th>
    <th style="width:15%">Date</th>
    <th style="width:18%">Remark</th>
    <th style="width:14%">Created By</th>
    <th style="width:9%">Documents</th>
    <th style="width:6%">Actions</th>
</tr>
</thead>
<tbody>
<?php foreach($tasks as $task): ?>
    <?php
    $taskId = (int)$task['task_id'];
    $taskText = trim((string)($task['task_text'] ?? ""));
    $displayTaskText = $taskText;
    $taskType = strtolower(trim((string)($task['task_type'] ?? "")));
    $taskRemark = trim((string)($task['remark'] ?? ""));
    $notificationEmail = trim((string)($task['notification_email'] ?? ""));

    if(!in_array($taskType, ["preventive", "kickoff", "training", "meeting", "corrective", "claim", "other"], true)){
        if(stripos($taskText, "Claim -") === 0 || strcasecmp($taskText, "Claim") === 0){
            $taskType = "claim";
        }elseif(stripos($taskText, "Preventive Maintenance") === 0){
            $taskType = "preventive";
        }elseif(strcasecmp($taskText, "Kickoff") === 0){
            $taskType = "kickoff";
        }elseif(strcasecmp($taskText, "Training") === 0){
            $taskType = "training";
        }elseif(strcasecmp($taskText, "Meeting") === 0){
            $taskType = "meeting";
        }elseif(stripos($taskText, "Corrective Maintenance") === 0){
            $taskType = "corrective";
        }else{
            $taskType = "other";
        }
    }
    $taskStartDate = $task['task_start_date'] ?? "";
    $taskEndDate = $task['task_end_date'] ?? "";
    $taskCreatedBy = trim((string)($task['created_by'] ?? ""));
    $completedBy = trim((string)($task['completed_by'] ?? ""));
    $completedAt = trim((string)($task['completed_at'] ?? ""));
    $claimAmount = $task['claim_amount'];
    $claimAmountValue = ($claimAmount === null || $claimAmount === "") ? "" : number_format((float)$claimAmount, 2, '.', '');
    $claimAmountText = $claimAmountValue !== "" && (float)$claimAmountValue > 0 ? "RM " . number_format((float)$claimAmountValue, 2) : "";
    $invoice = trim((string)($task['invoice'] ?? ""));
    $isDone = (int)$task['is_done'] === 1;
    $documentCount = $documentCounts[$taskId] ?? 0;
    $hasAssignedDate = !empty($taskStartDate) && $taskStartDate !== "0000-00-00";
    $completedAtText = taskFormatDateTime($completedAt);
    $taskDateText = (!$hasAssignedDate && $isDone && $completedAtText !== "")
        ? $completedAtText
        : taskFormatDateRange($taskStartDate, $taskEndDate);
    $remarkParts = [];
    $tickedByText = "";

    foreach(["Corrective Maintenance - ", "Claim - "] as $remarkPrefix){
        if(stripos($taskText, $remarkPrefix) === 0){
            $displayTaskText = rtrim($remarkPrefix, " -");
            $remarkValue = trim(substr($taskText, strlen($remarkPrefix)));
            if($taskRemark === "" && $remarkValue !== "") $taskRemark = $remarkValue;
            break;
        }
    }

    if($taskRemark !== "") $remarkParts[] = $taskRemark;
    if($canViewClaim && $claimAmountText !== "") $remarkParts[] = "Claim: " . $claimAmountText;
    if($invoice !== "") $remarkParts[] = "Invoice: " . $invoice;
    if($isDone && $completedBy !== "") $tickedByText = "Ticked by " . $completedBy;
    ?>
    <tr class="contract-task-item <?= $isDone ? 'task-completed' : '' ?>"
        data-task-id="<?= $taskId ?>">
        <td class="task-status-cell"
            title="Click to <?= $isDone ? 'mark as pending' : 'mark as completed' ?>"
            onclick="handleTaskStatusCellClick(event, this)">
            <div class="contract-task-text"><?= taskEscape($displayTaskText) ?></div>
        </td>
        <td>
            <div class="task-status-wrap">
                <input type="checkbox" class="form-check-input contract-task-checkbox"
                       aria-label="Mark <?= taskEscape($displayTaskText) ?> as completed"
                       <?= $isDone ? 'checked' : '' ?> <?= !$canEditTask ? 'disabled' : '' ?>
                       onchange="toggleContractTask(<?= $taskId ?>, this.checked, this)">
                <span class="task-status-badge <?= $isDone ? 'completed' : 'pending' ?>">
                    <i class="fa <?= $isDone ? 'fa-circle-check' : 'fa-clock' ?>"></i>
                    <?= $isDone ? 'Completed' : 'Pending' ?>
                </span>
            </div>
        </td>
        <td class="task-date-cell"><i class="fa fa-calendar-days text-muted me-1"></i><?= taskEscape($taskDateText) ?></td>
        <td class="task-remark-cell">
            <?php if(!empty($remarkParts)): ?>
                <div><?= taskEscape(implode(" | ", $remarkParts)) ?></div>
            <?php endif; ?>
            <?php if($tickedByText !== ""): ?>
                <div class="task-ticked-by"><?= taskEscape($tickedByText) ?></div>
            <?php endif; ?>
            <?php if(empty($remarkParts) && $tickedByText === ""): ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td class="task-created-cell"><i class="fa fa-user text-muted me-1"></i><?= taskEscape($taskCreatedBy !== "" ? $taskCreatedBy : "—") ?></td>
        <td class="task-document-cell">
            <?php if($canOpenTaskDocument): ?>
                <button type="button" class="task-document-button" onclick="openContractTaskDocuments(<?= $taskId ?>)">
                    <i class="fa fa-paperclip"></i>
                    <?= $documentCount > 0 ? $documentCount . ' file' . ($documentCount === 1 ? '' : 's') : 'Add file' ?>
                </button>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if($canEditTask || $canDeleteTask): ?>
            <div class="contract-task-actions">
                <?php if($canEditTask): ?>
                    <button type="button" class="btn btn-sm btn-primary task-icon-btn" title="Edit checklist item" aria-label="Edit checklist item"
                            onclick='openEditTaskModal(<?= $taskId ?>, <?= taskEscape(json_encode($taskText)) ?>, <?= taskEscape(json_encode($taskStartDate)) ?>, <?= taskEscape(json_encode($taskEndDate)) ?>, <?= taskEscape(json_encode($claimAmountValue)) ?>, <?= taskEscape(json_encode($invoice)) ?>, <?= taskEscape(json_encode($taskType)) ?>, <?= taskEscape(json_encode($taskRemark)) ?>, <?= taskEscape(json_encode($notificationEmail)) ?>)'>
                        <i class="fa fa-pen"></i>
                    </button>
                <?php endif; ?>
                <?php if($canDeleteTask): ?>
                    <button type="button" class="btn btn-sm btn-danger task-icon-btn" title="Delete checklist item" aria-label="Delete checklist item" onclick="deleteContractTask(<?= $taskId ?>)">
                        <i class="fa fa-trash"></i>
                    </button>
                <?php endif; ?>
            </div>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
