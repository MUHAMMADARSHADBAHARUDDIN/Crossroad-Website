<?php
session_start();
require_once "../includes/db_connect.php";

$role = $_SESSION['role'];
$username = $_SESSION['username'];

function canManageContract($role, $username, $owner){
    return (
        $role === "Administrator" ||
        $role === "User (Project Coordinator)" ||
        ($role === "User (Project Manager)" && $username === $owner)
    );
}

/* ✅ DOWNLOAD RULE */
function canDownloadFile($role){
    return $role !== "User (Technical)";
}

$contract_id = intval($_POST['id']);

$sql = "SELECT * FROM contract_files WHERE contract_id = $contract_id";
$result = $mysqli->query($sql);

if($result->num_rows > 0){

    while($row = $result->fetch_assoc()){

        $file = $row['file_name'];
        $file_id = $row['id'];
        $uploaded_by = $row['uploaded_by'];

        echo '<div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2">';

        echo '<div>
                <i class="fa fa-file"></i> ' . htmlspecialchars($file) . '
              </div>';

        echo '<div class="d-flex gap-2">';

        // VIEW (ALL USERS CAN VIEW)
        echo '<a class="btn btn-sm btn-success" target="_blank"
                href="../uploads/'.$file.'">
                View
              </a>';

        // DOWNLOAD (BLOCK TECHNICAL USERS)
        if(canDownloadFile($role)){
            echo '<a class="btn btn-sm btn-primary"
                    href="../uploads/'.$file.'"
                    download>
                    Download
                  </a>';
        }

        // DELETE (ONLY OWNER ROLES)
        if(canManageContract($role, $username, $uploaded_by)){
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