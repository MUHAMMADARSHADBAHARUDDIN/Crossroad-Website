<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

include("../includes/db_connect.php");
require_once "../includes/permissions.php";

if(!hasPermission($mysqli, "users_view")){
    die("Access denied.");
}

$canAddUser = hasPermission($mysqli, "users_add");
$canEditUser = hasPermission($mysqli, "users_edit");
$canDeleteUser = hasPermission($mysqli, "users_delete");

/* ✅ SEARCH FIX */
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
            "inventory_delete" => "Delete",
            "inventory_export" => "Export"
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
    ORDER BY username ASC
");

if(!$userStmt){
    die("SQL Error: " . $mysqli->error);
}

$userStmt->bind_param("ss", $searchLike, $searchLike);
$userStmt->execute();
$userResult = $userStmt->get_result();

if($userResult){
    while($row = $userResult->fetch_assoc()){
        $row['account_type'] = "user";
        $row['display_role'] = $row['role'];
        $accounts[] = $row;
    }
}

/* ✅ REAL ADMINISTRATOR TABLE */
/* ❌ NO system_admin TABLE HERE */
$administratorStmt = $mysqli->prepare("
    SELECT username, email
    FROM administrator
    WHERE username LIKE ?
       OR email LIKE ?
    ORDER BY username ASC
");

if(!$administratorStmt){
    die("SQL Error: " . $mysqli->error);
}

$administratorStmt->bind_param("ss", $searchLike, $searchLike);
$administratorStmt->execute();
$administratorResult = $administratorStmt->get_result();

if($administratorResult){
    while($row = $administratorResult->fetch_assoc()){
        $row['account_type'] = "administrator";
        $row['role'] = "Administrator";
        $row['display_role'] = "Administrator";
        $accounts[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<title>Manage Users</title>

<link rel="icon" href="../image/logo.png">
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

/* ✅ SAME FEEL AS ASSET INVENTORY TABLE */
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

.user-table tbody tr{
    cursor:pointer;
}

.user-table tbody tr:hover{
    background:#f5f7ff;
}

.table-responsive{
    overflow-x:hidden !important;
}

/* ✅ BADGES */
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

/* ✅ PERMISSION CHECKBOX UI */
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

/* ✅ MOBILE STILL TABLE-LIKE BUT STACKED CLEANLY */
@media(max-width: 768px){
    .user-table thead{
        display:none;
    }

    .user-table,
    .user-table tbody,
    .user-table tr,
    .user-table td{
        display:block;
        width:100%;
    }

    .user-table tr{
        border:1px solid #dee2e6;
        border-radius:10px;
        margin-bottom:12px;
        padding:10px;
        background:#fff;
    }

    .user-table td{
        border:none;
        border-bottom:1px solid #f1f1f1;
        padding:8px 4px;
    }

    .user-table td:last-child{
        border-bottom:none;
    }

    .user-table td::before{
        content:attr(data-label);
        display:block;
        font-weight:700;
        color:#555;
        margin-bottom:4px;
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
            placeholder="Search by username or email..."
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
    <th>Username</th>
    <th>Email</th>
    <th>Role</th>
    <th>Permission</th>
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
} else if(in_array("users_view", $permissions, true)){
    $permissionText[] = "Users";
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

    if(in_array("inventory_delete", $permissions, true)){
        $inventoryLabels[] = "Delete";
    }

    if(in_array("inventory_export", $permissions, true)){
        $inventoryLabels[] = "Export";
    }

    if(!empty($inventoryLabels)){
        $permissionText[] = "Inventory: " . implode(", ", $inventoryLabels);
    }
}

$permissionDetailJson = htmlspecialchars(json_encode($permissionText), ENT_QUOTES, 'UTF-8');
?>

<tr
data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>"
data-email="<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>"
data-role="<?= htmlspecialchars($row['display_role'], ENT_QUOTES, 'UTF-8') ?>"
data-account-type="<?= htmlspecialchars($row['account_type'], ENT_QUOTES, 'UTF-8') ?>"
data-permission-detail="<?= $permissionDetailJson ?>"
>
<td data-label="Username"><?= htmlspecialchars($row['username']) ?></td>

<td data-label="Email"><?= htmlspecialchars($row['email']) ?></td>

<td data-label="Role">
<?php if($row['account_type'] === "administrator"): ?>
    <span class="badge badge-admin">Administrator</span>
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
If all modules are Full Access, this account becomes Administrator automatically.
</div>
</div>

<div class="col-md-6 mb-3">
<label>New Password</label>
<input
type="password"
name="password"
class="form-control"
pattern="^(?=.*[A-Z])(?=.*[\W]).{8,}$"
>
<div class="form-text">
Leave empty if you do not want to change password.
</div>
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

document.querySelectorAll(".editUserBtn").forEach(button => {
    button.addEventListener("click", function(event){
        event.stopPropagation();

        const username = this.dataset.username;
        const accountType = this.dataset.accountType;
        const displayRole = this.dataset.displayRole;
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

        /* ✅ FIX: if old role / administrator role is not inside dropdown, add it automatically */
        if(!foundRole && displayRole !== ""){
            const newOption = new Option(displayRole, displayRole, true, true);
            roleSelect.add(newOption);
        }

        roleSelect.value = displayRole;

        form.querySelector('input[name="password"]').value = "";

        form.querySelectorAll(".perm-check").forEach(checkbox => {
            checkbox.checked = permissions.includes(checkbox.value);
        });

        ["users", "contracts", "inventory"].forEach(module => {
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

function filterUserTable(){
    const keyword = liveUserSearch.value.toLowerCase().trim();
    const rows = document.querySelectorAll(".user-table tbody tr[data-username]");

    rows.forEach(row => {
        const username = (row.dataset.username || "").toLowerCase();
        const email = (row.dataset.email || "").toLowerCase();
        const role = (row.dataset.role || "").toLowerCase();

        const match =
            username.includes(keyword) ||
            email.includes(keyword) ||
            role.includes(keyword);

        row.style.display = match ? "" : "none";
    });
}

liveUserSearch.addEventListener("input", filterUserTable);

clearUserSearch.addEventListener("click", function(){
    liveUserSearch.value = "";
    filterUserTable();

    if(window.history.replaceState){
        window.history.replaceState({}, document.title, "manage_users.php");
    }
});
</script>

</body>
</html>