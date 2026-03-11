<?php
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

include("../includes/db_connect.php");

$users = $mysqli->query("SELECT username FROM user ORDER BY username ASC");
$admins = $mysqli->query("SELECT username FROM system_admin ORDER BY username ASC");
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<title>Manage Users</title>

<link rel="icon" href="../image/logo.png">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>

body{
background:#f4f6f9;
font-family:'Inter',sans-serif;
}

/* HEADER */

.page-header{
background:#1a1a1a;
color:white;
padding:20px 30px;
border-bottom:4px solid #ffc107;
display:flex;
justify-content:space-between;
align-items:center;
}

/* CARD */

.card{
border:none;
border-radius:12px;
box-shadow:0 5px 15px rgba(0,0,0,0.08);
}

/* TABLE */

.table thead{
background:#1a1a1a;
color:white;
}

/* BUTTON */

.btn-warning{
background:#ffc107;
border:none;
}

.btn-warning:hover{
background:#ffb300;
}

/* BADGES */

.badge-user{
background:#0d6efd;
}

.badge-admin{
background:#ffc107;
color:black;
}

/* ACTION */

.action-btn{
margin-right:5px;
transition:0.2s;
}

.action-btn:hover{
transform:scale(1.15);
}

.add-btn{
border-radius:30px;
padding:8px 20px;
}

</style>

</head>

<body>

<!-- HEADER -->

<div class="page-header">

<div class="d-flex align-items-center gap-3">

<a href="home.php" class="btn btn-warning btn-sm">
<i class="fa fa-arrow-left"></i>
</a>

<h4 class="mb-0">
<i class="fa fa-user-cog"></i> Manage Users
</h4>

</div>

<button class="btn btn-warning add-btn" data-bs-toggle="modal" data-bs-target="#addUserModal">
<i class="fa fa-user-plus"></i> Add User
</button>

</div>


<div class="container mt-4">

<div class="card p-3">

<table class="table table-hover align-middle">

<thead>

<tr>
<th>Username</th>
<th>Role</th>
<th style="width:180px">Actions</th>
</tr>

</thead>

<tbody>

<!-- USERS -->

<?php while($row = $users->fetch_assoc()): ?>

<tr>

<td><?= htmlspecialchars($row['username']) ?></td>

<td>
<span class="badge badge-user">User</span>
</td>

<td>

<button
class="btn btn-sm btn-primary action-btn editUserBtn"
data-username="<?= $row['username'] ?>"
data-role="user"
data-bs-toggle="modal"
data-bs-target="#editUserModal">

<i class="fa fa-edit"></i>

</button>

<a
href="../backend/delete_user.php?username=<?= $row['username'] ?>&role=user"
class="btn btn-sm btn-danger action-btn"
onclick="return confirm('Delete this user?')">

<i class="fa fa-trash"></i>

</a>

</td>

</tr>

<?php endwhile; ?>


<!-- SYSTEM ADMIN -->

<?php while($row = $admins->fetch_assoc()): ?>

<tr>

<td><?= htmlspecialchars($row['username']) ?></td>

<td>
<span class="badge badge-admin">System Admin</span>
</td>

<td>

<button
class="btn btn-sm btn-primary action-btn editUserBtn"
data-username="<?= $row['username'] ?>"
data-role="system_admin"
data-bs-toggle="modal"
data-bs-target="#editUserModal">

<i class="fa fa-edit"></i>

</button>

<a
href="../backend/delete_user.php?username=<?= $row['username'] ?>&role=system_admin"
class="btn btn-sm btn-danger action-btn"
onclick="return confirm('Delete this user?')">

<i class="fa fa-trash"></i>

</a>

</td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

</div>

</div>


<!-- ADD USER MODAL -->

<div class="modal fade" id="addUserModal">

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header bg-dark text-white">

<h5 class="modal-title">
<i class="fa fa-user-plus"></i> Add User
</h5>

<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

</div>

<form action="../backend/add_user.php" method="POST">

<div class="modal-body">

<div class="mb-3">
<label>Username / Email</label>
<input type="text" name="username" class="form-control" required>
</div>

<div class="mb-3">
<label>Password</label>
<input type="password" name="password" class="form-control" required>
</div>

<div class="mb-3">
<label>Role</label>
<select name="role" class="form-control" required>
<option value="user">User</option>
<option value="system_admin">System Admin</option>
</select>
</div>

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

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header bg-dark text-white">

<h5 class="modal-title">
<i class="fa fa-user-edit"></i> Update User
</h5>

<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

</div>

<form action="../backend/update_user.php" method="POST">

<div class="modal-body">

<input type="hidden" name="username" id="edit_username">
<input type="hidden" name="role" id="edit_role">

<div class="mb-3">
<label>Username</label>
<input type="text" id="display_username" class="form-control" disabled>
</div>

<div class="mb-3">
<label>New Password</label>
<input type="password" name="password" class="form-control" required>
</div>

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


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>

document.querySelectorAll(".editUserBtn").forEach(button => {

button.addEventListener("click", function(){

let username = this.dataset.username
let role = this.dataset.role

document.getElementById("edit_username").value = username
document.getElementById("display_username").value = username
document.getElementById("edit_role").value = role

})

})

</script>

</body>

</html>