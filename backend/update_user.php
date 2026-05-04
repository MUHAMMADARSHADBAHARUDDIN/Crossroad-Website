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

function deleteAllPermissionsForUsername($mysqli, $username)
{
    $stmt = $mysqli->prepare("
        DELETE FROM user_permissions
        WHERE username = ?
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
}

function getCurrentAccount($mysqli, $username, $accountType)
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

    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

function usernameExistsElsewhere($mysqli, $newUsername, $oldUsername, $oldAccountType)
{
    $stmt = $mysqli->prepare("
        SELECT 'user' AS account_type, username
        FROM user
        WHERE username = ?

        UNION ALL

        SELECT 'administrator' AS account_type, username
        FROM administrator
        WHERE username = ?
    ");

    $stmt->bind_param("ss", $newUsername, $newUsername);
    $stmt->execute();

    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        if($row['username'] === $oldUsername && $row['account_type'] === $oldAccountType){
            continue;
        }

        return true;
    }

    return false;
}

function emailExistsElsewhere($mysqli, $newEmail, $oldUsername, $oldAccountType)
{
    $stmt = $mysqli->prepare("
        SELECT 'user' AS account_type, username, email
        FROM user
        WHERE email = ?

        UNION ALL

        SELECT 'administrator' AS account_type, username, email
        FROM administrator
        WHERE email = ?
    ");

    $stmt->bind_param("ss", $newEmail, $newEmail);
    $stmt->execute();

    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        if($row['username'] === $oldUsername && $row['account_type'] === $oldAccountType){
            continue;
        }

        return true;
    }

    return false;
}

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

    $currentAccount = getCurrentAccount($mysqli, $oldUsername, $oldAccountType);

    if(!$currentAccount){
        die("User not found.");
    }

    if(usernameExistsElsewhere($mysqli, $newUsername, $oldUsername, $oldAccountType)){
        die("Username already exists.");
    }

    if(emailExistsElsewhere($mysqli, $newEmail, $oldUsername, $oldAccountType)){
        die("Email already exists.");
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
    } else {
        $finalPassword = $currentAccount['password'];
    }

    $adminUser = $_SESSION['username'];
    $adminRole = $_SESSION['role'];

    $becomeAdministrator = isFullAdministratorAccess($permissions);

    /*
        ✅ Full access means administrator table.
        ✅ Role/title stays as selected role.
    */

    if($becomeAdministrator){

        if($oldAccountType === "administrator"){

            $stmt = $mysqli->prepare("
                UPDATE administrator
                SET username = ?,
                    email = ?,
                    role = ?,
                    password = ?
                WHERE username = ?
            ");

            if(!$stmt){
                die("SQL Error: " . $mysqli->error);
            }

            $stmt->bind_param(
                "sssss",
                $newUsername,
                $newEmail,
                $newRole,
                $finalPassword,
                $oldUsername
            );

            $stmt->execute();

            deleteAllPermissionsForUsername($mysqli, $oldUsername);
            deleteAllPermissionsForUsername($mysqli, $newUsername);

            $finalAccountType = "administrator";
            $actionNote = "Updated Administrator account. Role/title saved as: $newRole";

        } else {

            $insertAdmin = $mysqli->prepare("
                INSERT INTO administrator (username, email, role, password)
                VALUES (?, ?, ?, ?)
            ");

            if(!$insertAdmin){
                die("SQL Error: " . $mysqli->error);
            }

            $insertAdmin->bind_param(
                "ssss",
                $newUsername,
                $newEmail,
                $newRole,
                $finalPassword
            );

            $insertAdmin->execute();

            $deleteUser = $mysqli->prepare("
                DELETE FROM user
                WHERE username = ?
            ");
            $deleteUser->bind_param("s", $oldUsername);
            $deleteUser->execute();

            deleteAllPermissionsForUsername($mysqli, $oldUsername);
            deleteAllPermissionsForUsername($mysqli, $newUsername);

            $finalAccountType = "administrator";
            $actionNote = "User converted to Administrator because all modules were set to Full Access. Role/title saved as: $newRole";
        }

    } else {

        if($oldAccountType === "administrator"){

            /*
                If admin account no longer has full access,
                move it back to normal user table.
            */
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

            $insertUser->execute();

            $deleteAdmin = $mysqli->prepare("
                DELETE FROM administrator
                WHERE username = ?
            ");
            $deleteAdmin->bind_param("s", $oldUsername);
            $deleteAdmin->execute();

            deleteAllPermissionsForUsername($mysqli, $oldUsername);
            saveUserPermissions($mysqli, $newUsername, "user", $permissions);

            $finalAccountType = "user";
            $actionNote = "Administrator converted back to normal user because Full Access was removed. Role/title saved as: $newRole";

        } else {

            $stmt = $mysqli->prepare("
                UPDATE user
                SET username = ?,
                    email = ?,
                    role = ?,
                    password = ?
                WHERE username = ?
            ");

            if(!$stmt){
                die("SQL Error: " . $mysqli->error);
            }

            $stmt->bind_param(
                "sssss",
                $newUsername,
                $newEmail,
                $newRole,
                $finalPassword,
                $oldUsername
            );

            $stmt->execute();

            deleteAllPermissionsForUsername($mysqli, $oldUsername);
            saveUserPermissions($mysqli, $newUsername, "user", $permissions);

            $finalAccountType = "user";
            $actionNote = "Updated normal user account. Role/title saved as: $newRole";
        }
    }

    if($oldUsername === $_SESSION['username']){
        $_SESSION['username'] = $newUsername;
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "Admin [$adminUser] updated user account.
Old Username: $oldUsername
New Username: $newUsername
Email: $newEmail
Role/Title: $newRole
Original Account Type: $oldAccountType
Final Account Type: $finalAccountType
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