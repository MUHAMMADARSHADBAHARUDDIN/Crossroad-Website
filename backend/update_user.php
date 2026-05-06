<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username']) || !isset($_SESSION['role'])){
    die("No session");
}

if(!hasPermission($mysqli, "users_edit")){
    die("Access denied.");
}

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
        in_array("inventory_full", $permissions, true)
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
        "contracts_personal",

        /* Task permissions */
        "contracts_task",
        "contracts_task_add",
        "contracts_task_edit",
        "contracts_task_delete",

        "inventory_full",
        "inventory_view",
        "inventory_add",
        "inventory_edit",
        "inventory_stockout",
        "inventory_delete",
        "inventory_export"
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
   DELETE PERMISSIONS BY USERNAME
========================= */
function deleteAllPermissionsForUsername($mysqli, $username)
{
    $stmt = $mysqli->prepare("
        DELETE FROM user_permissions
        WHERE username = ?
    ");

    if($stmt){
        $stmt->bind_param("s", $username);
        $stmt->execute();
    }
}

/* =========================
   CHECK DUPLICATE EXCLUDING CURRENT ACCOUNT
========================= */
function duplicateAccountExists($mysqli, $newUsername, $newEmail, $oldUsername, $oldAccountType)
{
    if($oldAccountType === "user"){
        $stmt = $mysqli->prepare("
            SELECT username
            FROM user
            WHERE (username = ? OR email = ?)
            AND username != ?
            LIMIT 1
        ");

        if($stmt){
            $stmt->bind_param("sss", $newUsername, $newEmail, $oldUsername);
            $stmt->execute();
            $result = $stmt->get_result();

            if($result && $result->num_rows > 0){
                return true;
            }
        }

    } else {

        $stmt = $mysqli->prepare("
            SELECT username
            FROM user
            WHERE username = ?
            OR email = ?
            LIMIT 1
        ");

        if($stmt){
            $stmt->bind_param("ss", $newUsername, $newEmail);
            $stmt->execute();
            $result = $stmt->get_result();

            if($result && $result->num_rows > 0){
                return true;
            }
        }
    }

    if($oldAccountType === "administrator"){
        $stmt = $mysqli->prepare("
            SELECT username
            FROM administrator
            WHERE (username = ? OR email = ?)
            AND username != ?
            LIMIT 1
        ");

        if($stmt){
            $stmt->bind_param("sss", $newUsername, $newEmail, $oldUsername);
            $stmt->execute();
            $result = $stmt->get_result();

            if($result && $result->num_rows > 0){
                return true;
            }
        }

    } else {

        $stmt = $mysqli->prepare("
            SELECT username
            FROM administrator
            WHERE username = ?
            OR email = ?
            LIMIT 1
        ");

        if($stmt){
            $stmt->bind_param("ss", $newUsername, $newEmail);
            $stmt->execute();
            $result = $stmt->get_result();

            if($result && $result->num_rows > 0){
                return true;
            }
        }
    }

    return false;
}

/* =========================
   GET USER DATA
========================= */
function getAccountData($mysqli, $username, $accountType)
{
    if($accountType === "administrator"){

        $stmt = $mysqli->prepare("
            SELECT username, email, password, role
            FROM administrator
            WHERE username = ?
            LIMIT 1
        ");

    } else {

        $stmt = $mysqli->prepare("
            SELECT username, email, password, role
            FROM user
            WHERE username = ?
            LIMIT 1
        ");
    }

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/* =========================
   MAIN
========================= */
if($_SERVER["REQUEST_METHOD"] === "POST"){

    $oldUsername = trim($_POST['old_username'] ?? "");
    $oldAccountType = trim($_POST['old_account_type'] ?? "");

    $newUsername = trim($_POST['username'] ?? "");
    $newEmail = trim($_POST['email'] ?? "");
    $newRole = trim($_POST['role'] ?? "");
    $password = trim($_POST['password'] ?? "");

    $permissions = selectedPermissions();

    if($oldUsername === "" || $oldAccountType === "" || $newUsername === "" || $newEmail === "" || $newRole === ""){
        die("Invalid request.");
    }

    if(!in_array($oldAccountType, ["user", "administrator"], true)){
        die("Invalid account type.");
    }

    $oldData = getAccountData($mysqli, $oldUsername, $oldAccountType);

    if(!$oldData){
        die("User not found.");
    }

    if(duplicateAccountExists($mysqli, $newUsername, $newEmail, $oldUsername, $oldAccountType)){
        echo "<script>
            alert('Username or email already exists.');
            window.history.back();
        </script>";
        exit();
    }

    if($password !== ""){
        if(strlen($password) < 8){
            die("Password must be at least 8 characters long.");
        }

        if(!preg_match('/[A-Z]/', $password)){
            die("Password must include at least one uppercase letter.");
        }

        if(!preg_match('/[\W]/', $password)){
            die("Password must include at least one symbol.");
        }

        $finalPassword = password_hash($password, PASSWORD_DEFAULT);
        $passwordNote = "Password changed.";
    } else {
        $finalPassword = $oldData['password'];
        $passwordNote = "Password unchanged.";
    }

    $adminUser = $_SESSION['username'];
    $adminRole = $_SESSION['role'] ?? "UNKNOWN";

    $becomeAdministrator = isFullAdministratorAccess($permissions);

    /*
    |--------------------------------------------------------------------------
    | CASE 1: FINAL ACCOUNT TYPE = ADMINISTRATOR
    |--------------------------------------------------------------------------
    | Role/title remains selected role.
    |--------------------------------------------------------------------------
    */
    if($becomeAdministrator){

        if($oldAccountType === "administrator"){

            $stmt = $mysqli->prepare("
                UPDATE administrator
                SET username = ?,
                    email = ?,
                    password = ?,
                    role = ?
                WHERE username = ?
            ");

            if(!$stmt){
                die("SQL Error: " . $mysqli->error);
            }

            $stmt->bind_param(
                "sssss",
                $newUsername,
                $newEmail,
                $finalPassword,
                $newRole,
                $oldUsername
            );

            if(!$stmt->execute()){
                die("Update Error: " . $stmt->error);
            }

            deleteAllPermissionsForUsername($mysqli, $oldUsername);
            deleteAllPermissionsForUsername($mysqli, $newUsername);

            $finalAccountType = "administrator";
            $actionNote = "Updated administrator account. Role/title kept as [$newRole]. $passwordNote";

        } else {

            $insertAdmin = $mysqli->prepare("
                INSERT INTO administrator (username, email, password, role)
                VALUES (?, ?, ?, ?)
            ");

            if(!$insertAdmin){
                die("SQL Error: " . $mysqli->error);
            }

            $insertAdmin->bind_param(
                "ssss",
                $newUsername,
                $newEmail,
                $finalPassword,
                $newRole
            );

            if(!$insertAdmin->execute()){
                die("Insert Administrator Error: " . $insertAdmin->error);
            }

            $deleteUser = $mysqli->prepare("
                DELETE FROM user
                WHERE username = ?
            ");

            if($deleteUser){
                $deleteUser->bind_param("s", $oldUsername);
                $deleteUser->execute();
            }

            deleteAllPermissionsForUsername($mysqli, $oldUsername);
            deleteAllPermissionsForUsername($mysqli, $newUsername);

            $finalAccountType = "administrator";
            $actionNote = "User converted to administrator because all modules were set to Full Access. Role/title kept as [$newRole]. $passwordNote";
        }

    } else {

        /*
        |--------------------------------------------------------------------------
        | CASE 2: FINAL ACCOUNT TYPE = NORMAL USER
        |--------------------------------------------------------------------------
        */
        if($oldAccountType === "user"){

            $stmt = $mysqli->prepare("
                UPDATE user
                SET username = ?,
                    email = ?,
                    password = ?,
                    role = ?
                WHERE username = ?
            ");

            if(!$stmt){
                die("SQL Error: " . $mysqli->error);
            }

            $stmt->bind_param(
                "sssss",
                $newUsername,
                $newEmail,
                $finalPassword,
                $newRole,
                $oldUsername
            );

            if(!$stmt->execute()){
                die("Update Error: " . $stmt->error);
            }

            deleteAllPermissionsForUsername($mysqli, $oldUsername);
            saveUserPermissions($mysqli, $newUsername, "user", $permissions);

            $finalAccountType = "user";
            $actionNote = "Updated normal user account permissions. $passwordNote";

        } else {

            $insertUser = $mysqli->prepare("
                INSERT INTO user (username, email, password, role)
                VALUES (?, ?, ?, ?)
            ");

            if(!$insertUser){
                die("SQL Error: " . $mysqli->error);
            }

            $insertUser->bind_param(
                "ssss",
                $newUsername,
                $newEmail,
                $finalPassword,
                $newRole
            );

            if(!$insertUser->execute()){
                die("Insert User Error: " . $insertUser->error);
            }

            $deleteAdmin = $mysqli->prepare("
                DELETE FROM administrator
                WHERE username = ?
            ");

            if($deleteAdmin){
                $deleteAdmin->bind_param("s", $oldUsername);
                $deleteAdmin->execute();
            }

            deleteAllPermissionsForUsername($mysqli, $oldUsername);
            saveUserPermissions($mysqli, $newUsername, "user", $permissions);

            $finalAccountType = "user";
            $actionNote = "Administrator converted to normal user because Full Access was removed. Role/title kept as [$newRole]. $passwordNote";
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "Admin [$adminUser] updated user account.

OLD DATA:
Username: {$oldData['username']}
Email: {$oldData['email']}
Role/Title: {$oldData['role']}
Account Type: $oldAccountType

NEW DATA:
Username: $newUsername
Email: $newEmail
Role/Title: $newRole
Account Type: $finalAccountType

Details: $actionNote
IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $adminUser,
        $adminRole,
        "UPDATE USER",
        $description
    );

    echo "<script>
        alert('User updated successfully');
        window.location.href='../frontend/manage_users.php';
    </script>";
    exit();
}
?>