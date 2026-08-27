<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/planner_profiles.php";

if(!isset($_SESSION['username']) || !isset($_SESSION['role'])){
    die("No session");
}

if(!hasPermission($mysqli, "users_add")){
    die("Access denied.");
}

ensurePlannerProfileSchema($mysqli);

/* =========================
   SELECTED PERMISSIONS
========================= */
function selectedPermissions()
{
    if(!isset($_POST['permissions']) || !is_array($_POST['permissions'])){
        return [];
    }

    return array_values(array_unique(array_map("trim", $_POST['permissions'])));
}

/* =========================
   FULL ADMIN ACCESS CHECK
========================= */
function isFullAdministratorAccess($permissions)
{
    return (
        in_array("users_full", $permissions, true) &&
        in_array("contracts_full", $permissions, true) &&
        in_array("inventory_full", $permissions, true) &&
        in_array("office_inventory_full", $permissions, true) &&
        in_array("planner_full", $permissions, true) &&
        in_array("visitor_full", $permissions, true) &&
        in_array("bulletin_full", $permissions, true)
    );
}

/* =========================
   ALLOWED PERMISSIONS
========================= */
function allowedPermissionsList()
{
    return [
        "users_full",
        "users_view",
        "users_add",
        "users_edit",
        "users_delete",

        "contracts_full",
        "contracts_view",
        "contracts_add",
        "contracts_edit",
        "contracts_delete",
        "contracts_upload",
        "contracts_download",
        "contracts_claim_view",
        "contracts_master_budget",
        "contracts_personal",

        /* Task permissions */
        "contracts_task",
        "contracts_task_add",
        "contracts_task_edit",
        "contracts_task_delete",
        "contracts_task_document_add",
        "contracts_task_document_upload",
        "contracts_task_document_view",
        "contracts_task_document_download",
        "contracts_task_document_delete",

        "inventory_full",
        "inventory_view",
        "inventory_add",
        "inventory_edit",
        "inventory_stockout",
        "inventory_stockout_edit",
        "inventory_stockout_add_info",
        "inventory_stockout_delete_info",
        "inventory_delete",
        "inventory_export",
        "inventory_report",

        "office_inventory_full",
        "office_inventory_view",
        "office_inventory_add",
        "office_inventory_edit",
        "office_inventory_delete",
        "office_inventory_document_view",
        "office_inventory_document_download",
        "office_inventory_document_delete",
        "receiving_full",
        "receiving_view",
        "receiving_add",
        "receiving_edit",
        "receiving_delete",
        "part_request_full",
        "part_request_view",
        "part_request_add",

        "planner_full",
        "planner_view",
        "planner_add",
        "planner_edit",
        "planner_delete"
        ,"visitor_full"
        ,"visitor_view"
        ,"visitor_delete"
        ,"visitor_report"
        ,"bulletin_full"
        ,"bulletin_view"
        ,"bulletin_add"
        ,"bulletin_delete"
    ];
}

/* =========================
   SAVE USER PERMISSIONS
========================= */
function saveUserPermissions($mysqli, $username, $accountType, $permissions)
{
    $deleteStmt = $mysqli->prepare("
        DELETE FROM user_permissions
        WHERE username = ?
        AND account_type = ?
    ");

    if($deleteStmt){
        $deleteStmt->bind_param("ss", $username, $accountType);
        $deleteStmt->execute();
    }

    if(empty($permissions)){
        return;
    }

    $allowed = allowedPermissionsList();

    $insertStmt = $mysqli->prepare("
        INSERT INTO user_permissions (username, account_type, permission_name)
        VALUES (?, ?, ?)
    ");

    if(!$insertStmt){
        die("SQL Error: " . $mysqli->error);
    }

    foreach($permissions as $permission){

        if(!in_array($permission, $allowed, true)){
            continue;
        }

        $insertStmt->bind_param("sss", $username, $accountType, $permission);
        $insertStmt->execute();
    }
}

/* =========================
   CHECK DUPLICATE ACCOUNT
========================= */
function accountExists($mysqli, $username, $email)
{
    $stmt = $mysqli->prepare("
        SELECT username
        FROM user
        WHERE username = ?
        OR email = ?
        LIMIT 1
    ");

    if($stmt){
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result && $result->num_rows > 0){
            return true;
        }
    }

    $stmt = $mysqli->prepare("
        SELECT username
        FROM administrator
        WHERE username = ?
        OR email = ?
        LIMIT 1
    ");

    if($stmt){
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result && $result->num_rows > 0){
            return true;
        }
    }

    return false;
}

/* =========================
   MAIN
========================= */
if($_SERVER["REQUEST_METHOD"] === "POST"){

    $username = trim($_POST['username'] ?? "");
    $email = trim($_POST['email'] ?? "");
    $password = trim($_POST['password'] ?? "");
    $role = trim($_POST['role'] ?? "");
    $plannerRole = plannerNormalizeOperationalRole($_POST['planner_role'] ?? "");
    $telegramChatId = trim($_POST['telegram_chat_id'] ?? "");

    $permissions = selectedPermissions();

    if($username === "" || $email === "" || $password === "" || $role === "" || $plannerRole === ""){
        die("Please fill in all required fields.");
    }

    if($telegramChatId !== "" && !preg_match('/^(?:-?\d{5,20}|@[A-Za-z0-9_]{5,32})$/', $telegramChatId)){
        die("Telegram Chat ID must be a numeric chat ID or a valid @channel username.");
    }

    if(strlen($password) < 8){
        die("Password must be at least 8 characters long.");
    }

    if(!preg_match('/[A-Z]/', $password)){
        die("Password must include at least one uppercase letter.");
    }

    if(!preg_match('/[\W]/', $password)){
        die("Password must include at least one symbol.");
    }

    if(accountExists($mysqli, $username, $email)){
        echo "<script>
            alert('Username or email already exists.');
            window.history.back();
        </script>";
        exit();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $adminUser = $_SESSION['username'];
    $adminRole = $_SESSION['role'] ?? "UNKNOWN";

    $becomeAdministrator = isFullAdministratorAccess($permissions);

    if($becomeAdministrator){

        /*
        |--------------------------------------------------------------------------
        | FULL ACCESS = ADMINISTRATOR ACCOUNT TYPE
        | But role/title remains selected role.
        |--------------------------------------------------------------------------
        */
        $stmt = $mysqli->prepare("
            INSERT INTO administrator (username, email, password, role)
            VALUES (?, ?, ?, ?)
        ");

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);

        if(!$stmt->execute()){
            die("Insert Error: " . $stmt->error);
        }

        /* Clean permission table just in case */
        $deletePerm = $mysqli->prepare("
            DELETE FROM user_permissions
            WHERE username = ?
        ");

        if($deletePerm){
            $deletePerm->bind_param("s", $username);
            $deletePerm->execute();
        }

        $accountType = "administrator";
        $actionNote = "Created administrator account because all modules were set to Full Access. Role/title kept as [$role].";

    } else {

        /*
        |--------------------------------------------------------------------------
        | NORMAL USER ACCOUNT
        |--------------------------------------------------------------------------
        */
        $stmt = $mysqli->prepare("
            INSERT INTO user (username, email, password, role)
            VALUES (?, ?, ?, ?)
        ");

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);

        if(!$stmt->execute()){
            die("Insert Error: " . $stmt->error);
        }

        saveUserPermissions($mysqli, $username, "user", $permissions);

        $accountType = "user";
        $actionNote = "Created normal user account with selected permissions.";
    }

    if(!plannerSaveUserProfile($mysqli, $username, $accountType, $plannerRole, $telegramChatId)){
        die("Unable to save the planner role.");
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "Admin [$adminUser] created new user account.
Username: $username
Email: $email
Role/Title: $role
Planner Role: " . plannerOperationalRoleLabel($plannerRole) . "
Account Type: $accountType
Details: $actionNote
IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $adminUser,
        $adminRole,
        "ADD USER",
        $description
    );

    echo "<script>
        alert('User created successfully');
        window.location.href='../frontend/manage_users.php';
    </script>";
    exit();
}
?>
