<?php
/*
|--------------------------------------------------------------------------
| PERMISSION SYSTEM
|--------------------------------------------------------------------------
| This file controls:
| - Basic checkbox permission checking
| - Administrator full access
| - Contract helper permissions
| - Inventory/User helper compatibility
|--------------------------------------------------------------------------
*/

if(session_status() === PHP_SESSION_NONE){
    session_start();
}

/* =========================================================
   GET CURRENT ACCOUNT TYPE
   Checks real table instead of depending only on role name.
   This allows:
   - account_type = administrator
   - role/title = Founder/Director, General Manager, etc.
========================================================= */
if(!function_exists('getCurrentAccountType')){
    function getCurrentAccountType($mysqli){

        if(!isset($_SESSION['username'])){
            return "";
        }

        $username = $_SESSION['username'];

        /* Check administrator table first */
        $stmt = $mysqli->prepare("
            SELECT username
            FROM administrator
            WHERE username = ?
            LIMIT 1
        ");

        if($stmt){
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if($result && $result->num_rows > 0){
                return "administrator";
            }
        }

        /* Check normal user table */
        $stmt = $mysqli->prepare("
            SELECT username
            FROM user
            WHERE username = ?
            LIMIT 1
        ");

        if($stmt){
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if($result && $result->num_rows > 0){
                return "user";
            }
        }

        return "";
    }
}

/* =========================================================
   GET PERMISSIONS FOR ANY ACCOUNT
========================================================= */
if(!function_exists('getPermissionsForAccount')){
    function getPermissionsForAccount($mysqli, $username, $accountType){

        $permissions = [];

        if($username === "" || $accountType === ""){
            return $permissions;
        }

        $stmt = $mysqli->prepare("
            SELECT permission_name
            FROM user_permissions
            WHERE username = ?
            AND account_type = ?
        ");

        if(!$stmt){
            return $permissions;
        }

        $stmt->bind_param("ss", $username, $accountType);
        $stmt->execute();

        $result = $stmt->get_result();

        while($row = $result->fetch_assoc()){
            $permissions[] = $row['permission_name'];
        }

        return array_values(array_unique($permissions));
    }
}

/* =========================================================
   MAIN PERMISSION CHECK
========================================================= */
if(!function_exists('hasPermission')){
    function hasPermission($mysqli, $permissionName){

        if(!isset($_SESSION['username'])){
            return false;
        }

        $username = $_SESSION['username'];
        $accountType = getCurrentAccountType($mysqli);

        /*
        |--------------------------------------------------------------------------
        | ADMINISTRATOR ACCOUNT TYPE = FULL ACCESS
        |--------------------------------------------------------------------------
        | This means the account is stored inside administrator table.
        | The role/title can still be Founder/Director, Technical Manager, etc.
        |--------------------------------------------------------------------------
        */
        if($accountType === "administrator"){
            return true;
        }

        if($accountType === ""){
            return false;
        }

        $stmt = $mysqli->prepare("
            SELECT id
            FROM user_permissions
            WHERE username = ?
            AND account_type = ?
            AND permission_name = ?
            LIMIT 1
        ");

        if(!$stmt){
            return false;
        }

        $stmt->bind_param("sss", $username, $accountType, $permissionName);
        $stmt->execute();

        $result = $stmt->get_result();

        if($result && $result->num_rows > 0){
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | FULL ACCESS SHORTCUT
        |--------------------------------------------------------------------------
        */
        $module = "";

        if(strpos($permissionName, "users_") === 0){
            $module = "users_full";
        }
        elseif(strpos($permissionName, "contracts_") === 0){
            $module = "contracts_full";
        }
        elseif(strpos($permissionName, "inventory_") === 0){
            $module = "inventory_full";
        }

        if($module !== ""){
            $stmt = $mysqli->prepare("
                SELECT id
                FROM user_permissions
                WHERE username = ?
                AND account_type = ?
                AND permission_name = ?
                LIMIT 1
            ");

            if(!$stmt){
                return false;
            }

            $stmt->bind_param("sss", $username, $accountType, $module);
            $stmt->execute();

            $result = $stmt->get_result();

            if($result && $result->num_rows > 0){
                return true;
            }
        }

        return false;
    }
}

/* =========================================================
   CONTRACT VIEW ACCESS
========================================================= */
if(!function_exists('hasContractViewAccess')){
    function hasContractViewAccess($mysqli){

        return (
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_view") ||
            hasPermission($mysqli, "contracts_personal")
        );
    }
}

/* =========================================================
   CONTRACT ADD ACCESS
   ✅ Personal / Own can add contract now
========================================================= */
if(!function_exists('hasContractAddAccess')){
    function hasContractAddAccess($mysqli){

        return (
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_add") ||
            hasPermission($mysqli, "contracts_personal")
        );
    }
}

/* =========================================================
   CONTRACT EDIT ACCESS
   ✅ Personal / Own can edit own contract
========================================================= */
if(!function_exists('hasContractEditAccess')){
    function hasContractEditAccess($mysqli, $created_by = ""){

        $username = $_SESSION['username'] ?? "";

        if(
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_edit")
        ){
            return true;
        }

        if(
            hasPermission($mysqli, "contracts_personal") &&
            $created_by !== "" &&
            $username === $created_by
        ){
            return true;
        }

        return false;
    }
}

/* =========================================================
   CONTRACT DELETE ACCESS
   ✅ Personal / Own can delete own contract
========================================================= */
if(!function_exists('hasContractDeleteAccess')){
    function hasContractDeleteAccess($mysqli, $created_by = ""){

        $username = $_SESSION['username'] ?? "";

        if(
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_delete")
        ){
            return true;
        }

        if(
            hasPermission($mysqli, "contracts_personal") &&
            $created_by !== "" &&
            $username === $created_by
        ){
            return true;
        }

        return false;
    }
}

/* =========================================================
   CONTRACT UPLOAD ACCESS
========================================================= */
if(!function_exists('hasContractUploadAccess')){
    function hasContractUploadAccess($mysqli, $created_by = ""){

        $username = $_SESSION['username'] ?? "";

        if(
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_upload")
        ){
            return true;
        }

        if(
            hasPermission($mysqli, "contracts_personal") &&
            $created_by !== "" &&
            $username === $created_by
        ){
            return true;
        }

        return false;
    }
}

/* =========================================================
   CONTRACT DOWNLOAD ACCESS
========================================================= */
if(!function_exists('hasContractDownloadAccess')){
    function hasContractDownloadAccess($mysqli, $created_by = ""){

        $username = $_SESSION['username'] ?? "";

        if(
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_download")
        ){
            return true;
        }

        if(
            hasPermission($mysqli, "contracts_personal") &&
            $created_by !== "" &&
            $username === $created_by
        ){
            return true;
        }

        return false;
    }
}

/* =========================================================
   CONTRACT TASK ADD ACCESS
   ✅ contracts_task is kept for existing function compatibility
   ✅ contracts_task_add also supported if you use it later
   ✅ Personal / Own can add task for own contract
========================================================= */
if(!function_exists('hasContractTaskAddAccess')){
    function hasContractTaskAddAccess($mysqli, $created_by = ""){

        $username = $_SESSION['username'] ?? "";

        if(
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_task") ||
            hasPermission($mysqli, "contracts_task_add")
        ){
            return true;
        }

        if(
            hasPermission($mysqli, "contracts_personal") &&
            $created_by !== "" &&
            $username === $created_by
        ){
            return true;
        }

        return false;
    }
}

/* =========================================================
   CONTRACT TASK EDIT ACCESS
   ✅ New permission: contracts_task_edit
   ✅ Personal / Own can edit task for own contract
========================================================= */
if(!function_exists('hasContractTaskEditAccess')){
    function hasContractTaskEditAccess($mysqli, $created_by = ""){

        $username = $_SESSION['username'] ?? "";

        if(
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_task_edit")
        ){
            return true;
        }

        if(
            hasPermission($mysqli, "contracts_personal") &&
            $created_by !== "" &&
            $username === $created_by
        ){
            return true;
        }

        return false;
    }
}

/* =========================================================
   CONTRACT TASK DELETE ACCESS
   ✅ New permission: contracts_task_delete
   ✅ Personal / Own can delete task for own contract
========================================================= */
if(!function_exists('hasContractTaskDeleteAccess')){
    function hasContractTaskDeleteAccess($mysqli, $created_by = ""){

        $username = $_SESSION['username'] ?? "";

        if(
            hasPermission($mysqli, "contracts_full") ||
            hasPermission($mysqli, "contracts_task_delete")
        ){
            return true;
        }

        if(
            hasPermission($mysqli, "contracts_personal") &&
            $created_by !== "" &&
            $username === $created_by
        ){
            return true;
        }

        return false;
    }
}

/* =========================================================
   OLD TASK FUNCTION COMPATIBILITY
   Keep existing code working if it already calls hasContractTaskAccess()
========================================================= */
if(!function_exists('hasContractTaskAccess')){
    function hasContractTaskAccess($mysqli, $created_by = ""){

        return hasContractTaskAddAccess($mysqli, $created_by);
    }
}

/* =========================================================
   REAL ADMIN CHECK
   Use this only for pages that must be true administrator account.
========================================================= */
if(!function_exists('isAdministratorAccount')){
    function isAdministratorAccount($mysqli){

        return getCurrentAccountType($mysqli) === "administrator";
    }
}
?>