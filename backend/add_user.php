<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username']) || !isset($_SESSION['role'])){
    die("No session");
}

if(($_SESSION['role'] ?? '') !== "Administrator"){
    die("Access denied.");
}

if(!hasPermission($mysqli, "users_add")){
    die("Access denied.");
}

function selectedPermissions()
{
    if(!isset($_POST['permissions']) || !is_array($_POST['permissions'])){
        return [];
    }

    return array_values(array_unique(array_map("trim", $_POST['permissions'])));
}

function isFullAdministratorAccess($permissions)
{
    return (
        in_array("users_full", $permissions, true) &&
        in_array("contracts_full", $permissions, true) &&
        in_array("inventory_full", $permissions, true)
    );
}

function saveUserPermissions($mysqli, $username, $accountType, $permissions)
{
    $deleteStmt = $mysqli->prepare("
        DELETE FROM user_permissions
        WHERE username = ?
        AND account_type = ?
    ");
    $deleteStmt->bind_param("ss", $username, $accountType);
    $deleteStmt->execute();

    if(empty($permissions)){
        return;
    }

    $allowed = [
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
        "contracts_personal",

        "inventory_full",
        "inventory_view",
        "inventory_add",
        "inventory_edit",
        "inventory_stockout",
        "inventory_delete",
        "inventory_export"
    ];

    $insertStmt = $mysqli->prepare("
        INSERT INTO user_permissions (username, account_type, permission_name)
        VALUES (?, ?, ?)
    ");

    foreach($permissions as $permission){
        if(!in_array($permission, $allowed, true)){
            continue;
        }

        $insertStmt->bind_param("sss", $username, $accountType, $permission);
        $insertStmt->execute();
    }
}

if($_SERVER["REQUEST_METHOD"] === "POST"){

    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = trim($_POST["password"] ?? "");
    $role = trim($_POST["role"] ?? "");
    $permissions = selectedPermissions();

    if($username === "" || $email === "" || $password === ""){
        die("Username, email and password are required.");
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

    $checkStmt = $mysqli->prepare("
        SELECT username FROM user WHERE username = ? OR email = ?
        UNION
        SELECT username FROM administrator WHERE username = ? OR email = ?
        UNION
        SELECT username FROM system_admin WHERE username = ? OR email = ?
        LIMIT 1
    ");

    $checkStmt->bind_param(
        "ssssss",
        $username,
        $email,
        $username,
        $email,
        $username,
        $email
    );

    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if($checkResult->num_rows > 0){
        die("Username or email already exists.");
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $isAdministrator = isFullAdministratorAccess($permissions);

    if($isAdministrator){

        $accountType = "administrator";

        $stmt = $mysqli->prepare("
            INSERT INTO administrator (username, email, password)
            VALUES (?, ?, ?)
        ");

        $stmt->bind_param("sss", $username, $email, $hashed_password);

    } else {

        $accountType = "user";

        $stmt = $mysqli->prepare("
            INSERT INTO user (username, email, password, role)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);
    }

    if($stmt->execute()){

        if($accountType === "user"){
            saveUserPermissions($mysqli, $username, $accountType, $permissions);
        }

        $adminUser = $_SESSION['username'];
        $adminRole = $_SESSION['role'];
        $ip = $_SERVER['REMOTE_ADDR'];
        $time = date("Y-m-d H:i:s");

        $createdRoleText = ($accountType === "administrator") ? "Administrator" : $role;

        $description = "Admin [$adminUser] created new account.
New Username: $username
Email: $email
Role: $createdRoleText
Account Type: $accountType
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
        alert('User added successfully');
        window.location.href='../frontend/manage_users.php';
        </script>";
        exit();

    } else {
        echo "Error: " . $stmt->error;
    }
}
?>