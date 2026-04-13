<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_POST['id'])){
    echo "<span class='text-danger'>Invalid request</span>";
    exit();
}

$role = $_SESSION['role'];

$id = intval($_POST['id']);

// GET FILES
$result = $mysqli->query("SELECT * FROM contract_files WHERE contract_id = $id");

// ERROR CHECK
if(!$result){
    die("SQL Error: " . $mysqli->error);
}

// DISPLAY FILES
if($result->num_rows > 0){

    while($row = $result->fetch_assoc()){

        // REMOVE TIMESTAMP FROM DISPLAY NAME
        $clean_name = preg_replace('/^\d+_/', '', $row['file_name']);

        echo "
        <div class='mb-2 d-flex justify-content-between align-items-center border p-2 rounded'>

            <div>
                <i class='fa fa-file text-secondary'></i>
                <span>$clean_name</span>
            </div>

            <div class='d-flex gap-2'>

                <a href='../uploads/".$row['file_name']."' target='_blank' class='btn btn-sm btn-primary'>
                    <i class='fa fa-eye'></i> View
                </a>
        ";

        // ✅ ONLY CHANGE: Technical = NO DOWNLOAD
        if($role !== "User (Technical)"){

            echo "
                <a href='../uploads/".$row['file_name']."' download class='btn btn-sm btn-success'>
                    <i class='fa fa-download'></i> Download
                </a>
            ";

        } else {

            echo "
                <span class='btn btn-sm btn-secondary disabled'>
                    <i class='fa fa-eye-slash'></i> View Only
                </span>
            ";
        }

        echo "
            </div>

        </div>
        ";
    }

} else {
    echo "<span class='text-muted'>No files uploaded</span>";
}
?>