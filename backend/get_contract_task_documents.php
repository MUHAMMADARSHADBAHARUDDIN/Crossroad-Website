<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/contract_task_documents.php";

if(!isset($_SESSION['username'])){
    exit("<div class='alert alert-danger mb-0'>No session.</div>");
}

$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$task = contractTaskDocumentFetchTask($mysqli, $taskId);

if(!$task){
    exit("<div class='alert alert-warning mb-0'>Task not found.</div>");
}

$createdBy = $task['created_by'] ?? "";
$canView = hasContractTaskDocumentViewAccess($mysqli, $createdBy);
$canDownload = hasContractTaskDocumentDownloadAccess($mysqli, $createdBy);
$canUpload = hasContractTaskDocumentUploadAccess($mysqli, $createdBy);
$canDelete = hasContractTaskDocumentDeleteAccess($mysqli, $createdBy);

if(!$canView && !$canDownload && !$canUpload && !$canDelete){
    exit("<div class='alert alert-warning mb-0'>You do not have checklist document permission.</div>");
}

$stmt = $mysqli->prepare("
    SELECT id, file_name, original_file_name, uploaded_by, created_at
    FROM contract_task_documents
    WHERE task_id = ?
    ORDER BY created_at DESC, id DESC
");

if(!$stmt){
    exit("<div class='alert alert-danger mb-0'>SQL Error: " . contractTaskDocumentEscape($mysqli->error) . "</div>");
}

$stmt->bind_param("i", $taskId);
$stmt->execute();
$documents = $stmt->get_result();
?>

<div class="mb-3">
    <div class="fw-semibold"><?= contractTaskDocumentEscape($task['task_text'] ?? '') ?></div>
    <div class="text-muted small">
        <?= contractTaskDocumentEscape($task['project_name'] ?? '') ?>
        <?php if(!empty($task['contract_no'])): ?>
            | <?= contractTaskDocumentEscape($task['contract_no']) ?>
        <?php endif; ?>
    </div>
</div>

<?php if($canUpload): ?>
<div class="task-document-upload-box mb-3">
    <label class="form-label small mb-1">Attach document</label>
    <div class="input-group">
        <input type="file" id="taskDocumentFile" class="form-control">
        <button type="button" id="taskDocumentUploadBtn" class="btn btn-warning" onclick="uploadContractTaskDocument()">
            <i class="fa fa-upload"></i> Upload
        </button>
    </div>
    <small class="text-muted d-block mt-1">Maximum file size 100MB. ZIP files are allowed.</small>
</div>
<?php endif; ?>

<?php if($documents->num_rows <= 0): ?>
    <div class="alert alert-light border mb-0">
        <i class="fa fa-paperclip"></i> No document attached to this checklist item.
    </div>
<?php else: ?>
    <div class="list-group task-document-list">
        <?php while($doc = $documents->fetch_assoc()): ?>
            <?php
                $displayName = contractTaskDocumentDisplayName($doc);
                $uploadedBy = trim((string)($doc['uploaded_by'] ?? ''));
                $createdAt = trim((string)($doc['created_at'] ?? ''));
            ?>
            <div class="list-group-item task-document-row">
                <div class="task-document-info">
                    <div class="fw-semibold">
                        <i class="fa fa-file"></i>
                        <?= contractTaskDocumentEscape($displayName) ?>
                    </div>
                    <div class="text-muted small">
                        <?= $uploadedBy !== "" ? "Uploaded by " . contractTaskDocumentEscape($uploadedBy) : "" ?>
                        <?= ($uploadedBy !== "" && $createdAt !== "") ? " | " : "" ?>
                        <?= contractTaskDocumentEscape($createdAt) ?>
                    </div>
                </div>
                <div class="task-document-actions">
                    <?php if($canView): ?>
                        <a href="../backend/view_contract_task_document.php?id=<?= (int)$doc['id'] ?>"
                           class="btn btn-sm btn-outline-primary"
                           target="_blank">
                            <i class="fa fa-eye"></i> View
                        </a>
                    <?php endif; ?>
                    <?php if($canDownload): ?>
                        <a href="../backend/download_contract_task_document.php?id=<?= (int)$doc['id'] ?>"
                           class="btn btn-sm btn-outline-success">
                            <i class="fa fa-download"></i> Download
                        </a>
                    <?php endif; ?>
                    <?php if($canDelete): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteContractTaskDocument(<?= (int)$doc['id'] ?>)">
                            <i class="fa fa-trash"></i> Delete
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php endif; ?>
