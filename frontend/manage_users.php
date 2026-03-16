<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

include("../includes/db_connect.php");

$users = $mysqli->query("SELECT username,role FROM user ORDER BY username ASC");
$admins = $mysqli->query("SELECT username FROM system_admin ORDER BY username ASC");
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

        .table thead{
            background:#1a1a1a;
            color:white;
        }

        .badge-user{
            background:#0d6efd;
        }

        .badge-admin{
            background:#ffc107;
            color:black;
        }

        .action-btn{
            margin-right:5px;
            transition:0.2s;
        }

        .action-btn:hover{
            transform:scale(1.15);
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

    <!-- ADD USER BUTTON -->

    <button class="btn btn-warning mb-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="fa fa-user-plus"></i> Add User
    </button>

    <div class="card shadow-sm">
        <div class="card-body p-0">

            <div class="table-responsive">

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
                                <span class="badge badge-user"><?= htmlspecialchars($row['role']) ?></span>
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


                    <!-- SYSTEM ADMINS -->

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

                            <option value="system_admin">System Admin</option>

                            <option value="User (Project Coordinator)">Project Coordinator</option>

                            <option value="User (Technical)">Technical</option>

                            <option value="User (Project Manager)">Project Manager</option>

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


<?php include "layout/footer.php"; ?>

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