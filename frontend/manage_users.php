<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

include("../includes/db_connect.php");
require_once "../includes/permissions.php";
require_once "../includes/planner_profiles.php";

if(!hasPermission($mysqli, "users_view")){
    die("Access denied.");
}

$canAddUser = hasPermission($mysqli, "users_add");
$canEditUser = hasPermission($mysqli, "users_edit");
$canDeleteUser = hasPermission($mysqli, "users_delete");
ensurePlannerProfileSchema($mysqli);
$plannerOperationalRoles = plannerOperationalRoles();

$search = "";

if(isset($_GET['search'])){
    $search = trim($_GET['search']);
}

$searchLike = "%" . $search . "%";

$permissionGroups = [
    "users" => [
        "title" => "Users",
        "full" => "users_full",
        "items" => [
            "users_view" => "View",
            "users_add" => "Add",
            "users_edit" => "Edit",
            "users_delete" => "Delete"
        ]
    ],
    "contracts" => [
        "title" => "Contract",
        "full" => "contracts_full",
        "items" => [
            "contracts_view" => "View",
            "contracts_add" => "Add",
            "contracts_edit" => "Edit",
            "contracts_delete" => "Delete",
            "contracts_upload" => "Upload",
            "contracts_download" => "Download",
            "contracts_claim_view" => "View Claim",
            "contracts_master_budget" => "Master Budget",
            "contracts_task" => "Task Add",
            "contracts_task_edit" => "Task Edit",
            "contracts_task_delete" => "Task Delete",
            "contracts_task_document_add" => "Task Document Add",
            "contracts_task_document_upload" => "Task Document Upload",
            "contracts_task_document_view" => "Task Document View",
            "contracts_task_document_download" => "Task Document Download",
            "contracts_task_document_delete" => "Task Document Delete",
            "contracts_personal" => "Personal / Own"
        ]
    ],
    "inventory" => [
        "title" => "Inventory",
        "full" => "inventory_full",
        "items" => [
            "inventory_view" => "View",
            "inventory_add" => "Add",
            "inventory_edit" => "Edit",
            "inventory_stockout" => "Stock Out",
            "inventory_stockout_edit" => "Edit Stock Out Info",
            "inventory_stockout_add_info" => "Add Stock Out Info",
            "inventory_stockout_delete_info" => "Delete Stock Out Info",
            "inventory_delete" => "Delete",
            "inventory_export" => "Export",
            "inventory_report" => "Report"
        ]
    ],
    "office_inventory" => [
        "title" => "Office Inventory",
        "full" => "office_inventory_full",
        "items" => [
            "office_inventory_view" => "View",
            "office_inventory_add" => "Add",
            "office_inventory_edit" => "Edit",
            "office_inventory_delete" => "Delete",
            "office_inventory_document_view" => "Document View",
            "office_inventory_document_download" => "Document Download",
            "office_inventory_document_delete" => "Document Delete"
        ]
    ],
    "receiving" => [
        "title" => "Item Receive",
        "full" => "receiving_full",
        "items" => [
            "receiving_view" => "View Receiving Records",
            "receiving_add" => "Receive Items",
            "receiving_edit" => "Edit Received Items",
            "receiving_delete" => "Delete Received Items"
        ]
    ],
    "part_request" => [
        "title" => "Part Request",
        "full" => "part_request_full",
        "items" => [
            "part_request_view" => "View",
            "part_request_add" => "Create Request"
        ]
    ],
    "planner" => [
        "title" => "Planner",
        "full" => "planner_full",
        "items" => [
            "planner_view" => "View",
            "planner_add" => "Add",
            "planner_edit" => "Edit",
            "planner_delete" => "Delete"
        ]
    ],
    "visitor" => [
        "title" => "Visitor",
        "full" => "visitor_full",
        "items" => [
            "visitor_view" => "View visitor records",
            "visitor_delete" => "Delete visitor records",
            "visitor_report" => "Generate reports"
        ]
    ],
    "bulletin" => [
        "title" => "Bulletin",
        "full" => "bulletin_full",
        "items" => [
            "bulletin_view" => "View",
            "bulletin_add" => "Add standby",
            "bulletin_delete" => "Delete standby"
        ]
    ]
];

function renderPermissionCheckboxes($permissionGroups, $checkedPermissions = [])
{
    foreach($permissionGroups as $module => $group):
        $fullChecked = in_array($group['full'], $checkedPermissions, true) ? "checked" : "";
?>
        <div class="permission-card mb-3">
            <div class="permission-header">
                <strong><?= htmlspecialchars($group['title']) ?></strong>

                <label class="perm-option full-option">
                    <input
                        type="checkbox"
                        name="permissions[]"
                        value="<?= htmlspecialchars($group['full']) ?>"
                        class="perm-check perm-full"
                        data-module="<?= htmlspecialchars($module) ?>"
                        <?= $fullChecked ?>
                    >
                    Full Access
                </label>
            </div>

            <div class="permission-grid">
                <?php foreach($group['items'] as $permission => $label):
                    $checked = in_array($permission, $checkedPermissions, true) ? "checked" : "";
                ?>
                    <label class="perm-option">
                        <input
                            type="checkbox"
                            name="permissions[]"
                            value="<?= htmlspecialchars($permission) ?>"
                            class="perm-check perm-child"
                            data-module="<?= htmlspecialchars($module) ?>"
                            <?= $checked ?>
                        >
                        <?= htmlspecialchars($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
<?php
    endforeach;
}

function getAllPermissionValues($permissionGroups)
{
    $all = [];

    foreach($permissionGroups as $group){
        $all[] = $group['full'];

        foreach($group['items'] as $permission => $label){
            $all[] = $permission;
        }
    }

    return array_values(array_unique($all));
}

$allFullPermissions = getAllPermissionValues($permissionGroups);

$accounts = [];

/* ✅ NORMAL USERS */
$userStmt = $mysqli->prepare("
    SELECT username, email, role
    FROM user
    WHERE username LIKE ?
       OR email LIKE ?
       OR role LIKE ?
    ORDER BY username ASC
");

if(!$userStmt){
    die("SQL Error: " . $mysqli->error);
}

$userStmt->bind_param("sss", $searchLike, $searchLike, $searchLike);
$userStmt->execute();
$userResult = $userStmt->get_result();

if($userResult){
    while($row = $userResult->fetch_assoc()){
        $row['account_type'] = "user";
        $row['display_role'] = $row['role'];
        $profile = plannerGetUserProfile($mysqli, $row['username'], "user");
        $row['planner_role'] = $profile['operational_role'] ?? "";
        $row['telegram_chat_id'] = $profile['telegram_chat_id'] ?? "";
        $accounts[] = $row;
    }
}

/* ✅ ADMINISTRATOR TABLE */
/* ✅ account_type = administrator, but display_role = real job role */
$administratorStmt = $mysqli->prepare("
    SELECT username, email, role
    FROM administrator
    WHERE username LIKE ?
       OR email LIKE ?
       OR role LIKE ?
    ORDER BY username ASC
");

if(!$administratorStmt){
    die("SQL Error: " . $mysqli->error);
}

$administratorStmt->bind_param("sss", $searchLike, $searchLike, $searchLike);
$administratorStmt->execute();
$administratorResult = $administratorStmt->get_result();

if($administratorResult){
    while($row = $administratorResult->fetch_assoc()){
        $row['account_type'] = "administrator";

        if(empty($row['role'])){
            $row['role'] = "Administrator";
        }

        $row['display_role'] = $row['role'];
        $profile = plannerGetUserProfile($mysqli, $row['username'], "administrator");
        $row['planner_role'] = $profile['operational_role'] ?? "";
        $row['telegram_chat_id'] = $profile['telegram_chat_id'] ?? "";
        $accounts[] = $row;
    }
}

/* ✅ SORT ALL ACCOUNTS ALPHABETICALLY BY USERNAME */
usort($accounts, function($a, $b){
    return strcasecmp($a['username'] ?? '', $b['username'] ?? '');
});
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<title>Manage Users</title>

<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link rel="stylesheet" href="style.css">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
html, body{
    overflow-x:hidden !important;
}

.main{
    max-width:100%;
    overflow-x:hidden !important;
}

.user-table{
    width:100%;
}

.user-table th,
.user-table td{
    vertical-align:middle;
    white-space:normal !important;
    word-break:break-word;
    overflow-wrap:anywhere;
}

.user-sort-header{
    cursor:pointer;
    user-select:none;
    white-space:nowrap !important;
}

.user-sort-header i{
    margin-left:6px;
    color:#adb5bd;
    font-size:11px;
}

.user-sort-header.active i{
    color:#ffc107;
}

.user-table tbody tr{
    cursor:pointer;
}

.user-table tbody tr:hover{
    background:#f5f7ff;
}

.table-responsive{
    overflow-x:auto !important;
    -webkit-overflow-scrolling:touch;
}

.badge-user{
    background:#0d6efd;
    color:#fff;
}

.badge-admin{
    background:#212529;
    color:#fff;
}

.permission-summary{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}

.permission-pill{
    background:#eef2ff;
    color:#1d4ed8;
    border:1px solid #bfdbfe;
    padding:3px 8px;
    border-radius:20px;
    font-size:12px;
    line-height:1.3;
}

.no-permission{
    color:#dc3545;
    font-size:13px;
    font-weight:600;
}

.action-cell{
    display:flex;
    gap:5px;
    flex-wrap:wrap;
}

.action-btn{
    transition:0.2s;
}

.action-btn:hover{
    transform:scale(1.08);
}

.card{
    border-radius:12px;
}

.btn-warning{
    border-radius:8px;
}

.modal-content{
    border-radius:12px;
}

.modal-xl-custom{
    max-width:950px;
}

.permission-card{
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:14px;
    background:#fafafa;
}

.permission-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:1px solid #e5e7eb;
    padding-bottom:8px;
    margin-bottom:10px;
    gap:10px;
}

.permission-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));
    gap:8px;
}

.perm-option{
    display:flex;
    gap:8px;
    align-items:center;
    padding:8px 10px;
    background:white;
    border:1px solid #e5e7eb;
    border-radius:8px;
    cursor:pointer;
    font-size:14px;
}

.perm-option:hover{
    background:#eef2ff;
}

.full-option{
    background:#fff7ed;
    border-color:#f59e0b;
    margin:0;
}

.form-text-warning{
    color:#dc3545;
    font-size:13px;
}

@media(max-width: 768px){
    .user-table thead{
        display:table-header-group;
    }

    .user-table,
    .user-table tbody,
    .user-table tr,
    .user-table td{
        width:auto;
    }

    .user-table{
        display:table;
        min-width:860px;
        table-layout:auto;
    }

    .user-table th,
    .user-table td{
        display:table-cell;
    }

    .user-table tbody{
        display:table-row-group;
    }

    .user-table tr{
        display:table-row;
    }

    .user-table tr{
        border:0;
        border-radius:0;
        margin-bottom:0;
        padding:0;
        background:inherit;
    }

    .user-table td{
        display:table-cell;
        border-bottom-width:1px;
        padding:8px 10px;
        white-space:normal !important;
        max-width:320px;
        word-break:break-word;
        overflow-wrap:anywhere;
    }

    .user-table td:last-child{
        white-space:nowrap !important;
        max-width:none;
    }

    .user-table td:last-child{
        border-bottom-width:1px;
    }

    .user-table td::before{
        content:none !important;
        display:none !important;
    }

    .table-responsive{
        overflow-x:auto !important;
        -webkit-overflow-scrolling:touch;
    }
}
</style>

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

<h2 style="margin-bottom:20px;">
<i class="fa fa-user-cog"></i> Manage Users
</h2>

<form method="GET" class="mb-3" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveUserSearch"
            name="search"
            class="form-control"
            placeholder="Search by username, email, or role..."
            value="<?= htmlspecialchars($search) ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i> Search
        </button>
    </div>
</form>

<?php if($canAddUser): ?>
<button class="btn btn-warning mb-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
<i class="fa fa-user-plus"></i> Add User
</button>
<?php endif; ?>

<div class="table-responsive">

<table class="table table-striped table-hover user-table">

<thead>
<tr>
    <th class="user-sort-header" data-sort-column="0">Username <i class="fa fa-sort"></i></th>
    <th class="user-sort-header" data-sort-column="1">Email <i class="fa fa-sort"></i></th>
    <th class="user-sort-header" data-sort-column="2">Role <i class="fa fa-sort"></i></th>
    <th class="user-sort-header" data-sort-column="3">Permission <i class="fa fa-sort"></i></th>
    <th>Actions</th>
</tr>
</thead>

<tbody>

<?php foreach($accounts as $row): ?>

<?php
if($row['account_type'] === "administrator"){
    $permissions = $allFullPermissions;
} else {
    $permissions = getPermissionsForAccount($mysqli, $row['username'], $row['account_type']);
}

$permissionsJson = htmlspecialchars(json_encode($permissions), ENT_QUOTES, 'UTF-8');

$permissionText = [];

if(in_array("users_full", $permissions, true)){
    $permissionText[] = "Users: Full";
} else {
    $userLabels = [];

    if(in_array("users_view", $permissions, true)){
        $userLabels[] = "View";
    }

    if(in_array("users_add", $permissions, true)){
        $userLabels[] = "Add";
    }

    if(in_array("users_edit", $permissions, true)){
        $userLabels[] = "Edit";
    }

    if(in_array("users_delete", $permissions, true)){
        $userLabels[] = "Delete";
    }

    if(!empty($userLabels)){
        $permissionText[] = "Users: " . implode(", ", $userLabels);
    }
}

if(in_array("contracts_full", $permissions, true)){
    $permissionText[] = "Contract: Full";
} else {
    $contractLabels = [];

    if(in_array("contracts_view", $permissions, true)){
        $contractLabels[] = "View";
    }

    if(in_array("contracts_add", $permissions, true)){
        $contractLabels[] = "Add";
    }

    if(in_array("contracts_edit", $permissions, true)){
        $contractLabels[] = "Edit";
    }

    if(in_array("contracts_delete", $permissions, true)){
        $contractLabels[] = "Delete";
    }

    if(in_array("contracts_upload", $permissions, true)){
        $contractLabels[] = "Upload";
    }

    if(in_array("contracts_download", $permissions, true)){
        $contractLabels[] = "Download";
    }

    if(in_array("contracts_claim_view", $permissions, true)){
        $contractLabels[] = "View Claim";
    }

    if(in_array("contracts_master_budget", $permissions, true)){
        $contractLabels[] = "Master Budget";
    }

    if(in_array("contracts_task", $permissions, true)){
        $contractLabels[] = "Task Add";
    }

    if(in_array("contracts_task_edit", $permissions, true)){
        $contractLabels[] = "Task Edit";
    }

    if(in_array("contracts_task_delete", $permissions, true)){
        $contractLabels[] = "Task Delete";
    }

    if(in_array("contracts_task_document_add", $permissions, true)){
        $contractLabels[] = "Task Document Add";
    }

    if(in_array("contracts_task_document_upload", $permissions, true)){
        $contractLabels[] = "Task Document Upload";
    }

    if(in_array("contracts_task_document_view", $permissions, true)){
        $contractLabels[] = "Task Document View";
    }

    if(in_array("contracts_task_document_download", $permissions, true)){
        $contractLabels[] = "Task Document Download";
    }

    if(in_array("contracts_task_document_delete", $permissions, true)){
        $contractLabels[] = "Task Document Delete";
    }

    if(in_array("contracts_personal", $permissions, true)){
        $contractLabels[] = "Personal / Own";
    }

    if(!empty($contractLabels)){
        $permissionText[] = "Contract: " . implode(", ", $contractLabels);
    }
}

if(in_array("inventory_full", $permissions, true)){
    $permissionText[] = "Inventory: Full";
} else {
    $inventoryLabels = [];

    if(in_array("inventory_view", $permissions, true)){
        $inventoryLabels[] = "View";
    }

    if(in_array("inventory_add", $permissions, true)){
        $inventoryLabels[] = "Add";
    }

    if(in_array("inventory_edit", $permissions, true)){
        $inventoryLabels[] = "Edit";
    }

    if(in_array("inventory_stockout", $permissions, true)){
        $inventoryLabels[] = "Stock Out";
    }

    if(in_array("inventory_stockout_edit", $permissions, true)){
        $inventoryLabels[] = "Edit Stock Out Info";
    }

    if(in_array("inventory_stockout_add_info", $permissions, true)){
        $inventoryLabels[] = "Add Stock Out Info";
    }

    if(in_array("inventory_stockout_delete_info", $permissions, true)){
        $inventoryLabels[] = "Delete Stock Out Info";
    }

    if(in_array("inventory_delete", $permissions, true)){
        $inventoryLabels[] = "Delete";
    }

    if(in_array("inventory_export", $permissions, true)){
        $inventoryLabels[] = "Export";
    }

    if(in_array("inventory_report", $permissions, true)){
        $inventoryLabels[] = "Report";
    }

    if(!empty($inventoryLabels)){
        $permissionText[] = "Inventory: " . implode(", ", $inventoryLabels);
    }
}

if(in_array("office_inventory_full", $permissions, true)){
    $permissionText[] = "Office Inventory: Full";
} else {
    $officeInventoryLabels = [];

    if(in_array("office_inventory_view", $permissions, true)){
        $officeInventoryLabels[] = "View";
    }

    if(in_array("office_inventory_add", $permissions, true)){
        $officeInventoryLabels[] = "Add";
    }

    if(in_array("office_inventory_edit", $permissions, true)){
        $officeInventoryLabels[] = "Edit";
    }

    if(in_array("office_inventory_delete", $permissions, true)){
        $officeInventoryLabels[] = "Delete";
    }

    if(in_array("office_inventory_document_view", $permissions, true)){
        $officeInventoryLabels[] = "Document View";
    }

    if(in_array("office_inventory_document_download", $permissions, true)){
        $officeInventoryLabels[] = "Document Download";
    }

    if(in_array("office_inventory_document_delete", $permissions, true)){
        $officeInventoryLabels[] = "Document Delete";
    }

    if(!empty($officeInventoryLabels)){
        $permissionText[] = "Office Inventory: " . implode(", ", $officeInventoryLabels);
    }
}

if(in_array("planner_full", $permissions, true)){
    $permissionText[] = "Planner: Full";
} else {
    $plannerLabels = [];

    if(in_array("planner_view", $permissions, true)){
        $plannerLabels[] = "View";
    }

    if(in_array("planner_add", $permissions, true)){
        $plannerLabels[] = "Add";
    }

    if(in_array("planner_edit", $permissions, true)){
        $plannerLabels[] = "Edit";
    }

    if(in_array("planner_delete", $permissions, true)){
        $plannerLabels[] = "Delete";
    }

    if(!empty($plannerLabels)){
        $permissionText[] = "Planner: " . implode(", ", $plannerLabels);
    }
}

$permissionText[] = "Planner Role: " . plannerOperationalRoleLabel($row['planner_role'] ?? "");

if(in_array("visitor_full", $permissions, true) || in_array("visitor_view", $permissions, true) || in_array("visitor_delete", $permissions, true) || in_array("visitor_report", $permissions, true)){
    $visitorLabels = [];
    if(in_array("visitor_full", $permissions, true)){ $visitorLabels[] = "Full"; }
    else {
        if(in_array("visitor_view", $permissions, true)){ $visitorLabels[] = "View"; }
        if(in_array("visitor_delete", $permissions, true)){ $visitorLabels[] = "Delete"; }
        if(in_array("visitor_report", $permissions, true)){ $visitorLabels[] = "Report"; }
    }
    $permissionText[] = "Visitor: " . implode(", ", $visitorLabels);
}

if(in_array("bulletin_full", $permissions, true) || in_array("bulletin_view", $permissions, true) || in_array("bulletin_add", $permissions, true) || in_array("bulletin_delete", $permissions, true)){
    $bulletinLabels = [];
    if(in_array("bulletin_full", $permissions, true)){ $bulletinLabels[] = "Full"; }
    else {
        if(in_array("bulletin_view", $permissions, true)){ $bulletinLabels[] = "View"; }
        if(in_array("bulletin_add", $permissions, true)){ $bulletinLabels[] = "Add"; }
        if(in_array("bulletin_delete", $permissions, true)){ $bulletinLabels[] = "Delete"; }
    }
    $permissionText[] = "Bulletin: " . implode(", ", $bulletinLabels);
}

$permissionDetailJson = htmlspecialchars(json_encode($permissionText), ENT_QUOTES, 'UTF-8');
?>

<tr
data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>"
data-email="<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>"
data-role="<?= htmlspecialchars($row['display_role'], ENT_QUOTES, 'UTF-8') ?>"
data-planner-role="<?= htmlspecialchars($row['planner_role'] ?? "", ENT_QUOTES, 'UTF-8') ?>"
data-telegram-chat-id="<?= htmlspecialchars($row['telegram_chat_id'] ?? "", ENT_QUOTES, 'UTF-8') ?>"
data-account-type="<?= htmlspecialchars($row['account_type'], ENT_QUOTES, 'UTF-8') ?>"
data-permission-detail="<?= $permissionDetailJson ?>"
>
<td data-label="Username"><?= htmlspecialchars($row['username']) ?></td>

<td data-label="Email"><?= htmlspecialchars($row['email']) ?></td>

<td data-label="Role">
<?php if($row['account_type'] === "administrator"): ?>
    <span class="badge badge-admin"><?= htmlspecialchars($row['display_role']) ?></span>
<?php else: ?>
    <span class="badge badge-user"><?= htmlspecialchars($row['display_role']) ?></span>
<?php endif; ?>
</td>

<td data-label="Permission">
<?php if(!empty($permissionText)): ?>
    <div class="permission-summary">
        <?php foreach($permissionText as $text): ?>
            <span class="permission-pill"><?= htmlspecialchars($text) ?></span>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <span class="no-permission">No Access</span>
<?php endif; ?>
</td>

<td data-label="Actions">
<div class="action-cell">

<?php if($canEditUser): ?>
<button
class="btn btn-sm btn-primary action-btn editUserBtn"
data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>"
data-account-type="<?= htmlspecialchars($row['account_type'], ENT_QUOTES, 'UTF-8') ?>"
data-display-role="<?= htmlspecialchars($row['display_role'], ENT_QUOTES, 'UTF-8') ?>"
data-planner-role="<?= htmlspecialchars($row['planner_role'] ?? "", ENT_QUOTES, 'UTF-8') ?>"
data-telegram-chat-id="<?= htmlspecialchars($row['telegram_chat_id'] ?? "", ENT_QUOTES, 'UTF-8') ?>"
data-email="<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>"
data-permissions="<?= $permissionsJson ?>"
data-bs-toggle="modal"
data-bs-target="#editUserModal">
<i class="fa fa-edit"></i>
</button>
<?php endif; ?>

<?php if($canDeleteUser): ?>
<a
href="../backend/delete_user.php?username=<?= urlencode($row['username']) ?>&account_type=<?= urlencode($row['account_type']) ?>"
class="btn btn-sm btn-danger action-btn"
onclick="return confirm('Delete this user?')">
<i class="fa fa-trash"></i>
</a>
<?php endif; ?>

</div>
</td>
</tr>

<?php endforeach; ?>

<?php if(empty($accounts)): ?>
<tr>
    <td colspan="5" class="text-center text-muted">No users found.</td>
</tr>
<?php endif; ?>

</tbody>
</table>

</div>

</div>

<!-- PERMISSION DETAILS MODAL -->
<div class="modal fade" id="permissionDetailModal">
<div class="modal-dialog modal-lg">
<div class="modal-content">

<div class="modal-header bg-primary text-white">
<h5 class="modal-title">
<i class="fa fa-lock"></i> User Permission Details
</h5>
<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<div class="row g-3">
    <div class="col-md-6">
        <strong>Username</strong>
        <div id="detail_username"></div>
    </div>

    <div class="col-md-6">
        <strong>Email</strong>
        <div id="detail_email"></div>
    </div>

    <div class="col-md-6">
        <strong>Role</strong>
        <div id="detail_role"></div>
    </div>

    <div class="col-md-6">
        <strong>Account Type</strong>
        <div id="detail_account_type"></div>
    </div>
</div>

<hr>

<strong>Permissions</strong>
<div id="detail_permissions" class="permission-summary mt-2"></div>
</div>

</div>
</div>
</div>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal">
<div class="modal-dialog modal-xl-custom">
<div class="modal-content">

<div class="modal-header bg-dark text-white">
<h5 class="modal-title">
<i class="fa fa-user-plus"></i> Add User
</h5>
<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<form action="../backend/add_user.php" method="POST" id="addUserForm">

<div class="modal-body">

<div class="row">
<div class="col-md-6 mb-3">
<label>Username</label>
<input type="text" name="username" class="form-control" required>
</div>

<div class="col-md-6 mb-3">
<label>Email</label>
<input type="email" name="email" class="form-control" required>
</div>
</div>

<div class="row">
<div class="col-md-6 mb-3">
<label>Password</label>

<div class="position-relative">

<input
type="password"
name="password"
id="add_password"
class="form-control pe-5"
pattern="^(?=.*[A-Z])(?=.*[\W]).{8,}$"
required
>

<i
id="add_eye_icon"
class="fa-solid fa-eye position-absolute"
style="right:15px; top:50%; transform:translateY(-50%); cursor:pointer;"
onclick="toggleAddPassword()">
</i>

</div>

<div class="form-text">
Password must contain at least 8 characters, 1 uppercase letter, and 1 symbol.
</div>
</div>

<div class="col-md-6 mb-3">
<label>Role</label>
<select name="role" class="form-control" required>
    <option value="Founder/Director">Founder/Director</option>
    <option value="General Manager">General Manager</option>
    <option value="Sales Director">Sales Director</option>
    <option value="Project Manager cum Account Manager">Project Manager cum Account Manager</option>
    <option value="Technical Manager">Technical Manager</option>
    <option value="Senior Engineer">Senior Engineer</option>
    <option value="Senior System Engineer">Senior System Engineer</option>
    <option value="Technical Support">Technical Support</option>
    <option value="Technical Department">Technical Department</option>
    <option value="Sales Administrator">Sales Administrator</option>
    <option value="Sales Admin">Sales Admin</option>
    <option value="Sales Department">Sales Department</option>
    <option value="IT Executive">IT Executive</option>
    <option value="Project Manager">Project Manager</option>
    <option value="Project and Admin Coordinator">Project and Admin Coordinator</option>
    <option value="Project Coordinator">Project Coordinator</option>
    <option value="Admin Executive">Admin Executive</option>
</select>
</div>
</div>

<div class="row">
<div class="col-md-6 mb-3">
<label>Planner Role</label>
<select name="planner_role" class="form-control" required>
    <option value="">Select Planner Role</option>
    <?php foreach($plannerOperationalRoles as $plannerRoleValue => $plannerRoleLabel): ?>
        <option value="<?= htmlspecialchars($plannerRoleValue) ?>"><?= htmlspecialchars($plannerRoleLabel) ?></option>
    <?php endforeach; ?>
</select>
</div>

<div class="col-md-6 mb-3">
<label>Telegram Chat ID</label>
<input type="text" name="telegram_chat_id" class="form-control" placeholder="Example: 123456789">
<div class="form-text">Optional. The user must start a chat with the Telegram bot first.</div>
</div>

</div>

<hr>

<h5 class="mb-3">
<i class="fa fa-lock"></i> Account Permission
</h5>

<?php renderPermissionCheckboxes($permissionGroups); ?>

</div>

<div class="modal-footer">
<button class="btn btn-warning">
<i class="fa fa-save"></i> Create User
</button>
</div>

</form>

</div>
</div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal fade" id="editUserModal">
<div class="modal-dialog modal-xl-custom">
<div class="modal-content">

<div class="modal-header bg-dark text-white">
<h5 class="modal-title">
<i class="fa fa-user-edit"></i> Update User
</h5>
<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<form action="../backend/update_user.php" method="POST" id="editUserForm">

<div class="modal-body">

<input type="hidden" name="old_username" id="edit_old_username">
<input type="hidden" name="old_account_type" id="edit_old_account_type">

<div class="row">

<div class="col-md-6 mb-3">
<label>Username</label>
<input type="text" name="username" id="edit_username_input" class="form-control" required>
</div>

<div class="col-md-6 mb-3">
<label>Email</label>
<input type="email" name="email" id="edit_email_input" class="form-control" required>
</div>

</div>

<div class="row">

<div class="col-md-6 mb-3">
<label>Role</label>
<select name="role" id="edit_role_select" class="form-control" required>
    <option value="Founder/Director">Founder/Director</option>
    <option value="General Manager">General Manager</option>
    <option value="Sales Director">Sales Director</option>
    <option value="Project Manager cum Account Manager">Project Manager cum Account Manager</option>
    <option value="Technical Manager">Technical Manager</option>
    <option value="Senior Engineer">Senior Engineer</option>
    <option value="Senior System Engineer">Senior System Engineer</option>
    <option value="Technical Support">Technical Support</option>
    <option value="Technical Department">Technical Department</option>
    <option value="Sales Administrator">Sales Administrator</option>
    <option value="Sales Admin">Sales Admin</option>
    <option value="Sales Department">Sales Department</option>
    <option value="IT Executive">IT Executive</option>
    <option value="Project Manager">Project Manager</option>
    <option value="Project and Admin Coordinator">Project and Admin Coordinator</option>
    <option value="Project Coordinator">Project Coordinator</option>
    <option value="Admin Executive">Admin Executive</option>
</select>
<div class="form-text">
If all modules are Full Access, this account becomes Administrator automatically, but this role/title will stay the same.
</div>
</div>

<div class="col-md-6 mb-3">
<label>New Password</label>
<input
type="password"
name="password"
id="edit_temporary_password"
class="form-control"
pattern="^(?=.*[A-Za-z])(?=.*[^A-Za-z0-9\s]).{8,}$"
aria-describedby="temporary_password_help temporary_password_requirements"
>
<div class="form-text" id="temporary_password_help">
Leave empty to keep the current password. If entered, this becomes a temporary password and the user must change it after login.
</div>
<div id="temporary_password_requirements" class="mt-2 small" aria-live="polite">
    <div id="temp_req_length" class="text-muted"><i class="fa fa-circle-xmark me-1"></i> At least 8 characters</div>
    <div id="temp_req_letter" class="text-muted"><i class="fa fa-circle-xmark me-1"></i> Contains an alphabetical letter</div>
    <div id="temp_req_symbol" class="text-muted"><i class="fa fa-circle-xmark me-1"></i> Contains a symbol</div>
</div>
</div>

</div>

<div class="row">
<div class="col-md-6 mb-3">
<label>Planner Role</label>
<select name="planner_role" id="edit_planner_role" class="form-control" required>
    <option value="">Select Planner Role</option>
    <?php foreach($plannerOperationalRoles as $plannerRoleValue => $plannerRoleLabel): ?>
        <option value="<?= htmlspecialchars($plannerRoleValue) ?>"><?= htmlspecialchars($plannerRoleLabel) ?></option>
    <?php endforeach; ?>
</select>
</div>

<div class="col-md-6 mb-3">
<label>Telegram Chat ID</label>
<input type="text" name="telegram_chat_id" id="edit_telegram_chat_id" class="form-control" placeholder="Example: 123456789">
<div class="form-text">Optional. Used for CSSB Planner Telegram reminders.</div>
</div>

</div>

<hr>

<h5 class="mb-3">
<i class="fa fa-lock"></i> Account Permission
</h5>

<?php renderPermissionCheckboxes($permissionGroups); ?>

</div>

<div class="modal-footer">
<button class="btn btn-warning">
<i class="fa fa-save"></i> Update User
</button>
</div>

</form>

</div>
</div>
</div>

<?php include "layout/footer.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function syncFullCheckbox(form, module){
    const children = form.querySelectorAll('.perm-child[data-module="' + module + '"]');
    const full = form.querySelector('.perm-full[data-module="' + module + '"]');

    if(!full || children.length === 0){
        return;
    }

    let allChecked = true;

    children.forEach(child => {
        if(!child.checked){
            allChecked = false;
        }
    });

    full.checked = allChecked;
}

function setupPermissionForm(form){
    form.querySelectorAll(".perm-check").forEach(checkbox => {
        checkbox.addEventListener("change", function(){
            const module = this.dataset.module;

            if(this.classList.contains("perm-full")){
                const children = form.querySelectorAll('.perm-child[data-module="' + module + '"]');

                children.forEach(child => {
                    child.checked = this.checked;
                });
            } else {
                syncFullCheckbox(form, module);
            }
        });
    });
}

setupPermissionForm(document.getElementById("addUserForm"));
setupPermissionForm(document.getElementById("editUserForm"));

const temporaryPasswordInput = document.getElementById("edit_temporary_password");

function setTemporaryPasswordRequirement(id, passed){
    const item = document.getElementById(id);
    if(!item){ return; }
    item.classList.toggle("text-success", passed);
    item.classList.toggle("text-muted", !passed);
    const icon = item.querySelector("i");
    if(icon){
        icon.classList.toggle("fa-circle-check", passed);
        icon.classList.toggle("fa-circle-xmark", !passed);
    }
}

function updateTemporaryPasswordRequirements(){
    const value = temporaryPasswordInput ? temporaryPasswordInput.value : "";
    setTemporaryPasswordRequirement("temp_req_length", value.length >= 8);
    setTemporaryPasswordRequirement("temp_req_letter", /[A-Za-z]/.test(value));
    setTemporaryPasswordRequirement("temp_req_symbol", /[^A-Za-z0-9\s]/.test(value));
}

if(temporaryPasswordInput){
    temporaryPasswordInput.addEventListener("input", updateTemporaryPasswordRequirements);
    updateTemporaryPasswordRequirements();
}

document.querySelectorAll(".editUserBtn").forEach(button => {
    button.addEventListener("click", function(event){
        event.stopPropagation();

        const username = this.dataset.username;
        const accountType = this.dataset.accountType;
        const displayRole = this.dataset.displayRole;
        const plannerRole = this.dataset.plannerRole || "";
        const telegramChatId = this.dataset.telegramChatId || "";
        const email = this.dataset.email || "";

        let permissions = [];

        try{
            permissions = JSON.parse(this.dataset.permissions || "[]");
        }catch(e){
            permissions = [];
        }

        const form = document.getElementById("editUserForm");

        document.getElementById("edit_old_username").value = username;
        document.getElementById("edit_old_account_type").value = accountType;
        document.getElementById("edit_username_input").value = username;
        document.getElementById("edit_email_input").value = email;

        const roleSelect = document.getElementById("edit_role_select");

        let foundRole = false;

        Array.from(roleSelect.options).forEach(option => {
            if(option.value === displayRole){
                foundRole = true;
            }
        });

        if(!foundRole && displayRole !== ""){
            const newOption = new Option(displayRole, displayRole, true, true);
            roleSelect.add(newOption);
        }

        roleSelect.value = displayRole;
        document.getElementById("edit_planner_role").value = plannerRole;
        document.getElementById("edit_telegram_chat_id").value = telegramChatId;

        form.querySelector('input[name="password"]').value = "";
        updateTemporaryPasswordRequirements();

        form.querySelectorAll(".perm-check").forEach(checkbox => {
            checkbox.checked = permissions.includes(checkbox.value);
        });

        ["users", "contracts", "inventory", "office_inventory", "receiving", "part_request", "planner", "visitor", "bulletin"].forEach(module => {
            syncFullCheckbox(form, module);
        });
    });
});

document.querySelectorAll(".user-table tbody tr").forEach(row => {
    row.addEventListener("click", function(event){
        if(event.target.closest("button, a")){
            return;
        }

        const username = this.dataset.username || "";
        const email = this.dataset.email || "";
        const role = this.dataset.role || "";
        const accountType = this.dataset.accountType || "";

        let permissionDetail = [];

        try{
            permissionDetail = JSON.parse(this.dataset.permissionDetail || "[]");
        }catch(e){
            permissionDetail = [];
        }

        document.getElementById("detail_username").innerText = username;
        document.getElementById("detail_email").innerText = email;
        document.getElementById("detail_role").innerText = role;
        document.getElementById("detail_account_type").innerText = accountType;

        let html = "";

        if(permissionDetail.length > 0){
            permissionDetail.forEach(item => {
                html += `<span class="permission-pill">${item}</span>`;
            });
        }else{
            html = `<span class="no-permission">No Access</span>`;
        }

        document.getElementById("detail_permissions").innerHTML = html;

        new bootstrap.Modal(document.getElementById("permissionDetailModal")).show();
    });
});

function toggleAddPassword(){
    const password = document.getElementById("add_password");
    const icon = document.getElementById("add_eye_icon");

    if(password.type === "password"){
        password.type = "text";
        icon.classList.replace("fa-eye","fa-eye-slash");
    }else{
        password.type = "password";
        icon.classList.replace("fa-eye-slash","fa-eye");
    }
}

const liveUserSearch = document.getElementById("liveUserSearch");
const clearUserSearch = document.getElementById("clearUserSearch");
let userSortColumn = null;
let userSortDirection = "asc";

function getUserSortValue(row, columnIndex){
    if(columnIndex === 0){
        return row.dataset.username || "";
    }

    if(columnIndex === 1){
        return row.dataset.email || "";
    }

    if(columnIndex === 2){
        const accountTypeRank = (row.dataset.accountType || "") === "administrator" ? "0" : "1";
        return accountTypeRank + " " + (row.dataset.role || "");
    }

    const cells = row.querySelectorAll("td");
    return cells[columnIndex] ? cells[columnIndex].innerText : "";
}

function updateUserSortHeaders(activeHeader){
    document.querySelectorAll(".user-sort-header").forEach(header => {
        const icon = header.querySelector("i");
        header.classList.toggle("active", header === activeHeader);

        if(!icon){
            return;
        }

        if(header !== activeHeader){
            icon.className = "fa fa-sort";
            return;
        }

        icon.className = userSortDirection === "asc" ? "fa fa-sort-up" : "fa fa-sort-down";
    });
}

function sortUserTable(columnIndex, activeHeader){
    const tbody = document.querySelector(".user-table tbody");

    if(!tbody){
        return;
    }

    if(userSortColumn === columnIndex){
        userSortDirection = userSortDirection === "asc" ? "desc" : "asc";
    }else{
        userSortColumn = columnIndex;
        userSortDirection = "asc";
    }

    const direction = userSortDirection === "asc" ? 1 : -1;
    const rows = Array.from(tbody.querySelectorAll("tr[data-username]"));

    rows.sort((a, b) => {
        const aValue = getUserSortValue(a, columnIndex).trim().toLowerCase();
        const bValue = getUserSortValue(b, columnIndex).trim().toLowerCase();

        return aValue.localeCompare(bValue, undefined, {
            numeric: true,
            sensitivity: "base"
        }) * direction;
    });

    rows.forEach(row => tbody.appendChild(row));
    updateUserSortHeaders(activeHeader);
}

function filterUserTable(){
    const keyword = liveUserSearch ? liveUserSearch.value.toLowerCase().trim() : "";
    const rows = document.querySelectorAll(".user-table tbody tr[data-username]");

    rows.forEach(row => {
        const username = (row.dataset.username || "").toLowerCase();
        const email = (row.dataset.email || "").toLowerCase();
        const role = (row.dataset.role || "").toLowerCase();
        const accountType = (row.dataset.accountType || "").toLowerCase();
        const permissions = ((row.dataset.permissionDetail || "") + " " + (row.innerText || "")).toLowerCase();

        const globalMatch = keyword === "" ||
            username.includes(keyword) ||
            email.includes(keyword) ||
            role.includes(keyword) ||
            accountType.includes(keyword) ||
            permissions.includes(keyword);

        row.style.display = globalMatch ? "" : "none";
    });
}

if(liveUserSearch){
    liveUserSearch.addEventListener("input", filterUserTable);
}

if(clearUserSearch){
    clearUserSearch.addEventListener("click", function(){
        liveUserSearch.value = "";
        filterUserTable();

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, "manage_users.php");
        }
    });
}

document.querySelectorAll(".user-sort-header").forEach(header => {
    header.addEventListener("click", function(){
        sortUserTable(parseInt(this.dataset.sortColumn, 10), this);
    });
});
</script>

</body>
</html>
