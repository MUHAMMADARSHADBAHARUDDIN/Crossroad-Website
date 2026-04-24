<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

if($_SERVER["REQUEST_METHOD"] === "POST"){

    if(!isset($_POST['contract_id']) || !isset($_FILES['file'])){
        die("Invalid request");
    }

    $contract_id = intval($_POST['contract_id']);
    $uploaded_by = $_SESSION['username'];
    $role = $_SESSION['role'];

    $file = $_FILES['file'];

    // VALIDATION
    if($file['error'] !== 0){
        die("File upload error");
    }

    $file_name = time() . "_" . basename($file['name']);
    $target_path = "../uploads/" . $file_name;

    // CREATE FOLDER IF NOT EXIST
    if(!is_dir("../uploads")){
        mkdir("../uploads", 0777, true);
    }

    // MOVE FILE
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
File Name: $file_name
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

        } else {
            echo "Execute Error: " . $stmt->error;
        }

        $stmt->close();

    } else {
        echo "Failed to move uploaded file";
    }
}
?>