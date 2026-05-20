<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

function contractDeleteTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function contractDeleteColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);

    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id <= 0){
    die("Invalid request");
}

/* GET CONTRACT BEFORE DELETE */
$stmt = $mysqli->prepare("
    SELECT *
    FROM project_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$contract = $result->fetch_assoc();

if(!$contract){
    die("Contract not found");
}

$created_by = $contract['created_by'] ?? "";

if(!hasContractDeleteAccess($mysqli, $created_by)){
    die("Access denied");
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";

$mysqli->begin_transaction();

try{

    /*
        1. Delete related files/tasks for the contract being deleted.
        This prevents old contract 192 files/tasks from mixing with new shifted 192.
    */
    if(
        contractDeleteTableExists($mysqli, "contract_files") &&
        contractDeleteColumnExists($mysqli, "contract_files", "contract_id")
    ){
        $fileStmt = $mysqli->prepare("
            DELETE FROM contract_files
            WHERE contract_id = ?
        ");

        if(!$fileStmt){
            throw new Exception("File delete prepare error: " . $mysqli->error);
        }

        $fileStmt->bind_param("i", $id);

        if(!$fileStmt->execute()){
            throw new Exception("File delete error: " . $fileStmt->error);
        }
    }

    if(
        contractDeleteTableExists($mysqli, "contract_tasks") &&
        contractDeleteColumnExists($mysqli, "contract_tasks", "contract_id")
    ){
        $taskStmt = $mysqli->prepare("
            DELETE FROM contract_tasks
            WHERE contract_id = ?
        ");

        if(!$taskStmt){
            throw new Exception("Task delete prepare error: " . $mysqli->error);
        }

        $taskStmt->bind_param("i", $id);

        if(!$taskStmt->execute()){
            throw new Exception("Task delete error: " . $taskStmt->error);
        }
    }

    /*
        2. Delete selected contract.
    */
    $deleteStmt = $mysqli->prepare("
        DELETE FROM project_inventory
        WHERE no = ?
    ");

    if(!$deleteStmt){
        throw new Exception("Contract delete prepare error: " . $mysqli->error);
    }

    $deleteStmt->bind_param("i", $id);

    if(!$deleteStmt->execute()){
        throw new Exception("Contract delete error: " . $deleteStmt->error);
    }

    /*
        3. Shift contract numbers after deleted ID.
        Example:
        Delete 192.
        193 becomes 192.
        194 becomes 193.
    */
    $shiftProjectStmt = $mysqli->prepare("
        UPDATE project_inventory
        SET no = no - 1
        WHERE no > ?
        ORDER BY no ASC
    ");

    if(!$shiftProjectStmt){
        throw new Exception("Contract number shift prepare error: " . $mysqli->error);
    }

    $shiftProjectStmt->bind_param("i", $id);

    if(!$shiftProjectStmt->execute()){
        throw new Exception("Contract number shift error: " . $shiftProjectStmt->error);
    }

    /*
        4. Shift related attachment contract_id.
        So files from old 193 now follow new 192.
    */
    if(
        contractDeleteTableExists($mysqli, "contract_files") &&
        contractDeleteColumnExists($mysqli, "contract_files", "contract_id")
    ){
        $shiftFileStmt = $mysqli->prepare("
            UPDATE contract_files
            SET contract_id = contract_id - 1
            WHERE contract_id > ?
        ");

        if(!$shiftFileStmt){
            throw new Exception("File contract_id shift prepare error: " . $mysqli->error);
        }

        $shiftFileStmt->bind_param("i", $id);

        if(!$shiftFileStmt->execute()){
            throw new Exception("File contract_id shift error: " . $shiftFileStmt->error);
        }
    }

    /*
        5. Shift related checklist task contract_id.
        So tasks from old 193 now follow new 192.
    */
    if(
        contractDeleteTableExists($mysqli, "contract_tasks") &&
        contractDeleteColumnExists($mysqli, "contract_tasks", "contract_id")
    ){
        $shiftTaskStmt = $mysqli->prepare("
            UPDATE contract_tasks
            SET contract_id = contract_id - 1
            WHERE contract_id > ?
        ");

        if(!$shiftTaskStmt){
            throw new Exception("Task contract_id shift prepare error: " . $mysqli->error);
        }

        $shiftTaskStmt->bind_param("i", $id);

        if(!$shiftTaskStmt->execute()){
            throw new Exception("Task contract_id shift error: " . $shiftTaskStmt->error);
        }
    }

    /*
        6. Log activity.
    */
    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $projectName = $contract['project_name'] ?? "";
    $yearAwarded = $contract['year_awarded'] ?? "";
    $projectOwner = $contract['project_owner'] ?? "";
    $projectManager = $contract['project_manager'] ?? "";
    $accountManager = $contract['account_manager'] ?? "";
    $endUser = $contract['end_user'] ?? "";
    $contractNo = $contract['contract_no'] ?? "";
    $service = $contract['service'] ?? "";
    $poDate = $contract['po_date'] ?? "";
    $contractStart = $contract['contract_start'] ?? "";
    $contractEnd = $contract['contract_end'] ?? "";
    $amount = $contract['amount'] ?? "";
    $status = $contract['status'] ?? "";

    $description = "User [$username] deleted contract.
Deleted Contract No: $id
Project Name: $projectName
Year Awarded: $yearAwarded
Project Owner: $projectOwner
Project Manager: $projectManager
Account Manager: $accountManager
End User: $endUser
Contract No: $contractNo
Service: $service
PO Date: $poDate
Start Date: $contractStart
End Date: $contractEnd
Amount: RM $amount
Status: $status
Reorder Action: Contract numbers after $id were shifted down by 1
IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "DELETE CONTRACT",
        $description
    );

    $mysqli->commit();

    header("Location: ../frontend/contracts.php");
    exit();

}catch(Exception $e){

    $mysqli->rollback();

    die("Delete Error: " . $e->getMessage());
}
?>