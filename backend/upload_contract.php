<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if($_SERVER["REQUEST_METHOD"] === "POST"){

    if(!isset($_POST['contract_id']) || !isset($_FILES['file'])){
        die("Invalid request");
    }

    $contract_id = intval($_POST['contract_id']);
    $uploaded_by = $_SESSION['username'];
    $role = $_SESSION['role'] ?? "UNKNOWN";

    $contractStmt = $mysqli->prepare("
        SELECT created_by
        FROM project_inventory
        WHERE no = ?
        LIMIT 1
    ");

    if(!$contractStmt){
        die("SQL Error: " . $mysqli->error);
    }

    $contractStmt->bind_param("i", $contract_id);
    $contractStmt->execute();
    $contractResult = $contractStmt->get_result();
    $contractData = $contractResult->fetch_assoc();

    if(!$contractData){
        die("Contract not found");
    }

    $created_by = $contractData['created_by'] ?? "";

    if(!hasContractUploadAccess($mysqli, $created_by)){
        die("Access denied");
    }

    $file = $_FILES['file'];

    if($file['error'] !== 0){
        die("File upload error");
    }

    /*
       Database/storage filename still has unique timestamp.
       Activity log display only uses original clean filename.
    */
    $original_file_name = basename($file['name']);
    $file_name = time() . "_" . $original_file_name;
    $target_path = "../uploads/" . $file_name;

    if(!is_dir("../uploads")){
        mkdir("../uploads", 0777, true);
    }

    if(move_uploaded_file($file['tmp_name'], $target_path)){

        $stmt = $mysqli->prepare("
            INSERT INTO contract_files (contract_id, file_name, uploaded_by)
            VALUES (?, ?, ?)
        ");

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->bind_param("iss", $contract_id, $file_name, $uploaded_by);

        if($stmt->execute()){

            $ip = $_SERVER['REMOTE_ADDR'];
            $time = date("Y-m-d H:i:s");

            $description = "User [$uploaded_by] uploaded a contract file.
Contract ID: $contract_id
File Name: $original_file_name
File Size: " . $file['size'] . " bytes
IP Address: $ip
Time: $time";

            logActivity(
                $mysqli,
                $uploaded_by,
                $role,
                "UPLOAD CONTRACT FILE",
                $description
            );

            echo "<script>alert('File uploaded successfully'); window.history.back();</script>";
            exit();

        } else {
            echo "Execute Error: " . $stmt->error;
        }

        $stmt->close();

    } else {
        echo "Failed to move uploaded file";
    }
}
?>