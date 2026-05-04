<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username']) || !isset($_SESSION['role'])){
    die("No session");
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

function usernameExists($mysqli, $username)
{
    $stmt = $mysqli->prepare("
        SELECT username FROM user WHERE username = ?
        UNION
        SELECT username FROM administrator WHERE username = ?
    ");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result && $result->num_rows > 0;
}

function emailExists($mysqli, $email)
{
    $stmt = $mysqli->prepare("
        SELECT email FROM user WHERE email = ?
        UNION
        SELECT email FROM administrator WHERE email = ?
    ");
    $stmt->bind_param("ss", $email, $email);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result && $result->num_rows > 0;
}

if($_SERVER["REQUEST_METHOD"] === "POST"){

    $username = trim($_POST['username'] ?? "");
    $email = trim($_POST['email'] ?? "");
    $password = trim($_POST['password'] ?? "");
    $role = trim($_POST['role'] ?? "");
    $permissions = selectedPermissions();

    if($username === "" || $email === "" || $password === "" || $role === ""){
        die("Please fill in all required fields.");
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

    if(usernameExists($mysqli, $username)){
        die("Username already exists.");
    }

    if(emailExists($mysqli, $email)){
        die("Email already exists.");
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $adminUser = $_SESSION['username'];
    $adminRole = $_SESSION['role'];

    $becomeAdministrator = isFullAdministratorAccess($permissions);

    if($becomeAdministrator){

        /*
            ✅ Full access means account type is administrator.
            ✅ But role stays as selected role, for example Founder/Director.
        */
        $stmt = $mysqli->prepare("
            INSERT INTO administrator (username, email, role, password)
            VALUES (?, ?, ?, ?)
        ");

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->bind_param("ssss", $username, $email, $role, $hashed_password);
        $stmt->execute();

        /*
            Administrator accounts do not need user_permissions rows
            because they are treated as full access automatically.
        */
        $deletePerm = $mysqli->prepare("
            DELETE FROM user_permissions
            WHERE username = ?
        ");
        $deletePerm->bind_param("s", $username);
        $deletePerm->execute();

        $accountType = "administrator";
        $actionNote = "Created as Administrator account because all modules were set to Full Access. Role/title saved as: $role";

    } else {

        $stmt = $mysqli->prepare("
            INSERT INTO user (username, email, password, role)
            VALUES (?, ?, ?, ?)
        ");

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);
        $stmt->execute();

        saveUserPermissions($mysqli, $username, "user", $permissions);

        $accountType = "user";
        $actionNote = "Created as normal user account. Role/title saved as: $role";
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "Admin [$adminUser] created user account.
Username: $username
Email: $email
Role/Title: $role
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