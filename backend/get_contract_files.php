<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

function fileEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function displayContractFileName($fileName){
    $fileName = basename((string)$fileName);

    /*
      Removes timestamp / unique prefix only from display.
      Example:
      1712345678_contract.pdf becomes contract.pdf
      1712345678123_contract.pdf becomes contract.pdf
    */
    return preg_replace('/^\d{10,}_/', '', $fileName);
}

$contract_id = 0;

if(isset($_POST['id'])){
    $contract_id = intval($_POST['id']);
}
elseif(isset($_POST['contract_id'])){
    $contract_id = intval($_POST['contract_id']);
}

if($contract_id <= 0){
    exit("<div class='text-muted'>Invalid contract.</div>");
}

/* Get contract owner for permission */
$contractStmt = $mysqli->prepare("
    SELECT created_by
    FROM project_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$contractStmt){
    exit("<div class='text-danger'>SQL Error: " . fileEscape($mysqli->error) . "</div>");
}

$contractStmt->bind_param("i", $contract_id);
$contractStmt->execute();
$contractResult = $contractStmt->get_result();

if($contractResult->num_rows <= 0){
    exit("<div class='text-muted'>Contract not found.</div>");
}

$contractData = $contractResult->fetch_assoc();
$created_by = $contractData['created_by'] ?? "";

$canDownload = hasContractDownloadAccess($mysqli, $created_by);
$canDelete = hasContractUploadAccess($mysqli, $created_by);

$stmt = $mysqli->prepare("
    SELECT *
    FROM contract_files
    WHERE contract_id = ?
    ORDER BY id DESC
");

if(!$stmt){
    exit("<div class='text-danger'>SQL Error: " . fileEscape($mysqli->error) . "</div>");
}

$stmt->bind_param("i", $contract_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows <= 0){
    exit("<div class='text-muted'>No files uploaded.</div>");
}
?>

<div class="list-group">

<?php while($file = $result->fetch_assoc()): ?>

<?php
$fileId = $file['id'] ?? 0;
$storedFileName = $file['file_name'] ?? "";
$displayFileName = displayContractFileName($storedFileName);
$filePath = "../uploads/" . $storedFileName;

$uploadedBy = $file['uploaded_by'] ?? "-";
$uploadedAt = $file['uploaded_at'] ?? ($file['upload_date'] ?? "");
?>

<div class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap">

    <div style="min-width:0;">
        <div class="fw-semibold" style="word-break:break-word;">
            <i class="fa fa-file"></i>
            <?= fileEscape($displayFileName) ?>
        </div>

        <small class="text-muted">
            Uploaded by <?= fileEscape($uploadedBy) ?>
            <?php if(!empty($uploadedAt)): ?>
                • <?= fileEscape($uploadedAt) ?>
            <?php endif; ?>
        </small>
    </div>

    <div class="d-flex gap-2 flex-wrap">

        <?php if($canDownload): ?>
            <a
                href="<?= fileEscape($filePath) ?>"
                target="_blank"
                class="btn btn-sm btn-primary"
                download="<?= fileEscape($displayFileName) ?>"
            >
                <i class="fa fa-download"></i> Download
            </a>
        <?php else: ?>
            <span class="badge bg-secondary">No Download Access</span>
        <?php endif; ?>

        <?php if($canDelete): ?>
            <a
                href="../backend/delete_contract_file.php?id=<?= urlencode($fileId) ?>"
                class="btn btn-sm btn-danger"
                onclick="return confirm('Delete this file?')"
            >
                <i class="fa fa-trash"></i> Delete
            </a>
        <?php endif; ?>

    </div>

</div>

<?php endwhile; ?>

</div>