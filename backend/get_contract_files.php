<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

if(!hasContractViewAccess($mysqli)){
    exit("Access denied");
}

$contract_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if(!$contract_id){
    exit("Invalid request");
}

$contractStmt = $mysqli->prepare("
    SELECT created_by
    FROM project_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$contractStmt){
    exit("Prepare failed: " . $mysqli->error);
}

$contractStmt->bind_param("i", $contract_id);
$contractStmt->execute();
$contractResult = $contractStmt->get_result();
$contractData = $contractResult->fetch_assoc();

if(!$contractData){
    exit("Contract not found");
}

$contractCreatedBy = $contractData['created_by'] ?? "";

$canDownload = hasContractDownloadAccess($mysqli);
$canDeleteFile = hasContractDeleteAccess($mysqli, $contractCreatedBy);

$stmt = $mysqli->prepare("
    SELECT *
    FROM contract_files
    WHERE contract_id = ?
    ORDER BY created_at DESC
");

if(!$stmt){
    exit("Prepare failed: " . $mysqli->error);
}

$stmt->bind_param("i", $contract_id);
$stmt->execute();

$result = $stmt->get_result();

if($result->num_rows > 0){

    while($row = $result->fetch_assoc()){

        $file = $row['file_name'];
        $file_id = $row['id'];

        $safeFileText = htmlspecialchars($file);
        $safeFileUrl = rawurlencode($file);

        echo '<div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2">';

        echo '<div>
                <i class="fa fa-file"></i> ' . $safeFileText . '
              </div>';

        echo '<div class="d-flex gap-2">';

        echo '<a class="btn btn-sm btn-success" target="_blank"
                href="../uploads/'.$safeFileUrl.'">
                View
              </a>';

        if($canDownload){
            echo '<a class="btn btn-sm btn-primary"
                    href="../uploads/'.$safeFileUrl.'"
                    download>
                    Download
                  </a>';
        }

        if($canDeleteFile){
            echo '<a href="../backend/delete_contract_file.php?id='.$file_id.'"
                    class="btn btn-sm btn-danger"
                    onclick="return confirm(\'Delete this file?\')">
                    Delete
                  </a>';
        }

        echo '</div>';
        echo '</div>';
    }

} else {
    echo "<p>No files uploaded.</p>";
}
?>