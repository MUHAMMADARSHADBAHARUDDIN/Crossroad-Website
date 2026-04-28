<?php

function getCurrentAccountType($mysqli)
{
    if(!isset($_SESSION['username'])){
        return null;
    }

    $username = $_SESSION['username'];

    $stmt = $mysqli->prepare("SELECT username FROM administrator WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $adminResult = $stmt->get_result();

    if($adminResult && $adminResult->num_rows > 0){
        return "administrator";
    }

    $stmt = $mysqli->prepare("SELECT username FROM user WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $userResult = $stmt->get_result();

    if($userResult && $userResult->num_rows > 0){
        return "user";
    }

    return null;
}

function getFullPermissionName($permission)
{
    if(strpos($permission, "users_") === 0){
        return "users_full";
    }

    if(strpos($permission, "contracts_") === 0){
        return "contracts_full";
    }

    if(strpos($permission, "inventory_") === 0){
        return "inventory_full";
    }

    return "";
}

function hasPermission($mysqli, $permission)
{
    if(!isset($_SESSION['username'])){
        return false;
    }

    $username = $_SESSION['username'];
    $accountType = getCurrentAccountType($mysqli);

    if(!$accountType){
        return false;
    }

    /* ✅ REAL ADMINISTRATOR ALWAYS FULL ACCESS */
    if($accountType === "administrator"){
        return true;
    }

    $fullPermission = getFullPermissionName($permission);

    $stmt = $mysqli->prepare("
        SELECT id
        FROM user_permissions
        WHERE username = ?
        AND account_type = ?
        AND (
            permission_name = ?
            OR permission_name = ?
        )
        LIMIT 1
    ");

    if(!$stmt){
        return false;
    }

    $stmt->bind_param("ssss", $username, $accountType, $permission, $fullPermission);
    $stmt->execute();

    $result = $stmt->get_result();

    return ($result && $result->num_rows > 0);
}

function getPermissionsForAccount($mysqli, $username, $accountType)
{
    if($accountType === "administrator"){
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

            "inventory_full",
            "inventory_view",
            "inventory_add",
            "inventory_edit",
            "inventory_stockout",
            "inventory_delete",
            "inventory_export"
        ];
    }

    $permissions = [];

    $stmt = $mysqli->prepare("
        SELECT permission_name
        FROM user_permissions
        WHERE username = ?
        AND account_type = ?
    ");

    if(!$stmt){
        return [];
    }

    $stmt->bind_param("ss", $username, $accountType);
    $stmt->execute();

    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        $permissions[] = $row['permission_name'];
    }

    return array_values(array_unique($permissions));
}

/* =========================================================
   ✅ CONTRACT COMPATIBILITY FUNCTIONS
   These fix old calls like hasContractViewAccess()
========================================================= */

function hasContractViewAccess($mysqli)
{
    return (
        hasPermission($mysqli, "contracts_view") ||
        hasPermission($mysqli, "contracts_personal")
    );
}

function hasContractAddAccess($mysqli)
{
    return (
        hasPermission($mysqli, "contracts_add") ||
        hasPermission($mysqli, "contracts_personal")
    );
}

function hasContractDownloadAccess($mysqli)
{
    return (
        hasPermission($mysqli, "contracts_download") ||
        hasPermission($mysqli, "contracts_personal")
    );
}

function hasContractEditAccess($mysqli, $createdBy = null)
{
    if(hasPermission($mysqli, "contracts_edit")){
        return true;
    }

    if(hasPermission($mysqli, "contracts_personal")){
        return isset($_SESSION['username']) && $_SESSION['username'] === $createdBy;
    }

    return false;
}

function hasContractDeleteAccess($mysqli, $createdBy = null)
{
    if(hasPermission($mysqli, "contracts_delete")){
        return true;
    }

    if(hasPermission($mysqli, "contracts_personal")){
        return isset($_SESSION['username']) && $_SESSION['username'] === $createdBy;
    }

    return false;
}

function hasContractUploadAccess($mysqli, $createdBy = null)
{
    if(hasPermission($mysqli, "contracts_upload")){
        return true;
    }

    if(hasPermission($mysqli, "contracts_personal")){
        return isset($_SESSION['username']) && $_SESSION['username'] === $createdBy;
    }

    return false;
}

/* =========================================================
   ✅ INVENTORY COMPATIBILITY FUNCTIONS
========================================================= */

function hasInventoryViewAccess($mysqli)
{
    return hasPermission($mysqli, "inventory_view");
}

function hasInventoryAddAccess($mysqli)
{
    return hasPermission($mysqli, "inventory_add");
}

function hasInventoryEditAccess($mysqli)
{
    return hasPermission($mysqli, "inventory_edit");
}

function hasInventoryStockOutAccess($mysqli)
{
    return hasPermission($mysqli, "inventory_stockout");
}

function hasInventoryDeleteAccess($mysqli)
{
    return hasPermission($mysqli, "inventory_delete");
}

function hasInventoryExportAccess($mysqli)
{
    return hasPermission($mysqli, "inventory_export");
}

?>