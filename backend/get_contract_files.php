<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

$role = $_SESSION['role'] ?? "";
$username = $_SESSION['username'] ?? "";

$contract_id = $_POST['id'] ?? $_POST['contract_id'] ?? "";

if($contract_id == ""){
    echo "<div class='alert alert-danger'>No contract selected.</div>";
    exit();
}

function fileEscape($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cleanDisplayFileName($fileName){
    return preg_replace('/^\d+_/', '', $fileName);
}

/* =========================
   APP BASE URL
   This makes the link work whether your page is:
   /frontend/contracts.php
   or
   /contracts.php
========================= */
$appBaseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

/* =========================
   PERMISSION CONTROL
========================= */

$canDownload = true;
$canDelete = false;

if(function_exists('hasContractDownloadAccess')){
    $canDownload = hasContractDownloadAccess($mysqli);
}

if(function_exists('hasContractDeleteAccess')){
    $canDelete = hasContractDeleteAccess($mysqli);
} else {
    $canDelete = in_array($role, [
        "Administrator",
        "System Admin",
        "User (Project Coordinator)"
    ]);
}

/* =========================
   GET CONTRACT FILES
========================= */

$stmt = $mysqli->prepare("
    SELECT id, file_name, uploaded_by
    FROM contract_files
    WHERE contract_id = ?
    ORDER BY id DESC
");

if(!$stmt){
    echo "<div class='alert alert-danger'>SQL Error: " . fileEscape($mysqli->error) . "</div>";
    exit();
}

$stmt->bind_param("s", $contract_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows == 0){
    echo "
        <div class='alert alert-secondary mb-0'>
            <i class='fa fa-folder-open'></i> No document uploaded for this contract.
        </div>
    ";
    $stmt->close();
    exit();
}

echo "<div class='list-group'>";

while($row = $result->fetch_assoc()){

    $fileId = (int)$row['id'];
    $storedFileName = basename($row['file_name']);
    $displayFileName = cleanDisplayFileName($storedFileName);

    $viewUrl = $appBaseUrl . "/backend/view_contract_file.php?id=" . $fileId;
    $downloadUrl = $appBaseUrl . "/backend/download_contract_file.php?id=" . $fileId;

    echo "
        <div class='list-group-item'>
            <div class='d-flex justify-content-between align-items-center flex-wrap gap-2'>

                <div>
                    <div class='fw-semibold'>
                        <i class='fa fa-file'></i> " . fileEscape($displayFileName) . "
                    </div>
                    <small class='text-muted'>
                        Uploaded by: " . fileEscape($row['uploaded_by'] ?? '-') . "
                    </small>
                </div>

                <div class='d-flex gap-2 flex-wrap'>

                    <a href='" . fileEscape($viewUrl) . "'
                       target='_blank'
                       rel='noopener noreferrer'
                       class='btn btn-sm btn-info text-white'>
                        <i class='fa fa-eye'></i> View
                    </a>
    ";

    if($canDownload){
        echo "
                    <a href='" . fileEscape($downloadUrl) . "'
                       class='btn btn-sm btn-success'>
                        <i class='fa fa-download'></i> Download
                    </a>
        ";
    }

    if($canDelete){
        echo "
                    <button type='button'
                            class='btn btn-sm btn-danger'
                            onclick='deleteContractFile($fileId, " . json_encode($contract_id) . ")'>
                        <i class='fa fa-trash'></i> Delete
                    </button>
        ";
    }

    echo "
                </div>
            </div>
        </div>
    ";
}

echo "</div>";

$stmt->close();
?>