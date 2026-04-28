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
    ");
    $deleteStmt->bind_param("s", $username);
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

function deleteUserPermissions($mysqli, $username)
{
    $stmt = $mysqli->prepare("
        DELETE FROM user_permissions
        WHERE username = ?
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
}

function updateUsernameReferences($mysqli, $oldUsername, $newUsername)
{
    if($oldUsername === $newUsername){
        return;
    }

    $tables = [
        ["project_inventory", "created_by"],
        ["asset_inventory", "created_by"],
        ["server_inventory", "created_by"],
        ["contract_files", "uploaded_by"],
        ["contract_documents", "uploaded_by"],
        ["stock_out_history", "stock_out_by"],
        ["server_stockout_history", "stock_out_by"]
    ];

    foreach($tables as $item){
        $table = $item[0];
        $column = $item[1];

        $sql = "UPDATE `$table` SET `$column` = ? WHERE `$column` = ?";
        $stmt = $mysqli->prepare($sql);

        if($stmt){
            $stmt->bind_param("ss", $newUsername, $oldUsername);
            $stmt->execute();
        }
    }
}

function duplicateAccountExists($mysqli, $newUsername, $newEmail, $oldUsername, $oldAccountType)
{
    $checks = [
        "user" => "SELECT username, email FROM `user` WHERE username = ? OR email = ?",
        "administrator" => "SELECT username, email FROM administrator WHERE username = ? OR email = ?",
        "system_admin" => "SELECT username, email FROM system_admin WHERE username = ? OR email = ?"
    ];

    foreach($checks as $type => $sql){
        $stmt = $mysqli->prepare($sql);

        if(!$stmt){
            continue;
        }

        $stmt->bind_param("ss", $newUsername, $newEmail);
        $stmt->execute();

        $result = $stmt->get_result();

        while($row = $result->fetch_assoc()){
            $foundUsername = $row['username'];
            $foundEmail = $row['email'];

            if($type === $oldAccountType && $foundUsername === $oldUsername){
                continue;
            }

            return true;
        }
    }

    return false;
}

if($_SERVER["REQUEST_METHOD"] === "POST"){

    $oldUsername = trim($_POST['old_username'] ?? $_POST['username'] ?? "");
    $oldAccountType = trim($_POST['old_account_type'] ?? $_POST['account_type'] ?? "");

    $newUsername = trim($_POST['username'] ?? "");
    $newEmail = trim($_POST['email'] ?? "");
    $newRole = trim($_POST['role'] ?? "");
    $password = trim($_POST['password'] ?? "");
    $permissions = selectedPermissions();

    if($oldUsername === "" || $oldAccountType === "" || $newUsername === "" || $newEmail === ""){
        die("Invalid request.");
    }

    if(!in_array($oldAccountType, ["user", "administrator"], true)){
        die("Invalid account type.");
    }

    $adminUser = $_SESSION['username'];
    $adminRole = $_SESSION['role'];

    $becomeAdministrator = isFullAdministratorAccess($permissions);
    $newAccountType = $becomeAdministrator ? "administrator" : "user";

    if($oldAccountType === "administrator" && $oldUsername === $adminUser && $newAccountType !== "administrator"){
        die("You cannot remove your own administrator access.");
    }

    if(duplicateAccountExists($mysqli, $newUsername, $newEmail, $oldUsername, $oldAccountType)){
        die("Username or email already exists.");
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

        $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);
    }

    if($oldAccountType === "administrator"){
        $fetchStmt = $mysqli->prepare("
            SELECT username, email, password
            FROM administrator
            WHERE username = ?
            LIMIT 1
        ");
    } else {
        $fetchStmt = $mysqli->prepare("
            SELECT username, email, password, role
            FROM `user`
            WHERE username = ?
            LIMIT 1
        ");
    }

    $fetchStmt->bind_param("s", $oldUsername);
    $fetchStmt->execute();
    $fetchResult = $fetchStmt->get_result();
    $oldData = $fetchResult->fetch_assoc();

    if(!$oldData){
        die("Account not found.");
    }

    $finalPassword = ($password !== "") ? $newPasswordHash : $oldData['password'];

    $mysqli->begin_transaction();

    try{

        if($oldAccountType === "user" && $newAccountType === "user"){

            $stmt = $mysqli->prepare("
                UPDATE `user`
                SET username = ?, email = ?, password = ?, role = ?
                WHERE username = ?
            ");
            $stmt->bind_param("sssss", $newUsername, $newEmail, $finalPassword, $newRole, $oldUsername);
            $stmt->execute();

            deleteUserPermissions($mysqli, $oldUsername);
            saveUserPermissions($mysqli, $newUsername, "user", $permissions);

            updateUsernameReferences($mysqli, $oldUsername, $newUsername);

            $actionNote = "Updated normal user account.";

        } elseif($oldAccountType === "user" && $newAccountType === "administrator"){

            $insertAdmin = $mysqli->prepare("
                INSERT INTO administrator (username, email, password)
                VALUES (?, ?, ?)
            ");
            $insertAdmin->bind_param("sss", $newUsername, $newEmail, $finalPassword);
            $insertAdmin->execute();

            $deleteUser = $mysqli->prepare("
                DELETE FROM `user`
                WHERE username = ?
            ");
            $deleteUser->bind_param("s", $oldUsername);
            $deleteUser->execute();

            deleteUserPermissions($mysqli, $oldUsername);
            deleteUserPermissions($mysqli, $newUsername);

            updateUsernameReferences($mysqli, $oldUsername, $newUsername);

            $actionNote = "Converted user to Administrator because all modules are Full Access.";

        } elseif($oldAccountType === "administrator" && $newAccountType === "administrator"){

            $stmt = $mysqli->prepare("
                UPDATE administrator
                SET username = ?, email = ?, password = ?
                WHERE username = ?
            ");
            $stmt->bind_param("ssss", $newUsername, $newEmail, $finalPassword, $oldUsername);
            $stmt->execute();

            deleteUserPermissions($mysqli, $oldUsername);
            deleteUserPermissions($mysqli, $newUsername);

            updateUsernameReferences($mysqli, $oldUsername, $newUsername);

            if($oldUsername === $adminUser){
                $_SESSION['username'] = $newUsername;
            }

            $actionNote = "Updated administrator account.";

        } elseif($oldAccountType === "administrator" && $newAccountType === "user"){

            $insertUser = $mysqli->prepare("
                INSERT INTO `user` (username, email, password, role)
                VALUES (?, ?, ?, ?)
            ");
            $insertUser->bind_param("ssss", $newUsername, $newEmail, $finalPassword, $newRole);
            $insertUser->execute();

            $deleteAdmin = $mysqli->prepare("
                DELETE FROM administrator
                WHERE username = ?
            ");
            $deleteAdmin->bind_param("s", $oldUsername);
            $deleteAdmin->execute();

            deleteUserPermissions($mysqli, $oldUsername);
            saveUserPermissions($mysqli, $newUsername, "user", $permissions);

            updateUsernameReferences($mysqli, $oldUsername, $newUsername);

            $actionNote = "Converted administrator to normal user.";
        }

        $mysqli->commit();

    } catch(Exception $e){
        $mysqli->rollback();
        die("Update failed: " . $e->getMessage());
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $oldRoleText = $oldData['role'] ?? "Administrator";

    $description = "Admin [$adminUser] updated user account.

OLD DATA:
Username: $oldUsername
Email: {$oldData['email']}
Role: $oldRoleText
Account Type: $oldAccountType

NEW DATA:
Username: $newUsername
Email: $newEmail
Role: " . ($newAccountType === "administrator" ? "Administrator" : $newRole) . "
Account Type: $newAccountType

Details: $actionNote
Password Changed: " . ($password !== "" ? "YES" : "NO") . "
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