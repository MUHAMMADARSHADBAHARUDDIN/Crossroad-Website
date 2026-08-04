<?php
require_once "../includes/security.php";
startSecureSession();

require_once "../includes/db_connect.php";
require_once "../includes/auth_schema.php";
require_once "../includes/activity_log.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

ensureFirstLoginPasswordSchema($mysqli);

function changePasswordEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$account = authFetchAccountBySession($mysqli);

if(!$account){
    session_destroy();
    header("Location: index.html");
    exit();
}

if((int)($account['must_change_password'] ?? 0) !== 1){
    header("Location: dashboard.php");
    exit();
}

$error = "";

if($_SERVER["REQUEST_METHOD"] === "POST"){
    $currentPassword = trim($_POST['current_password'] ?? "");
    $newPassword = trim($_POST['new_password'] ?? "");
    $confirmPassword = trim($_POST['confirm_password'] ?? "");

    if($currentPassword === "" || $newPassword === "" || $confirmPassword === ""){
        $error = "Please fill in all password fields.";
    } elseif(!password_verify($currentPassword, $account['password'] ?? "")){
        $error = "Current password is incorrect.";
    } elseif($newPassword !== $confirmPassword){
        $error = "New password and confirm password do not match.";
    } elseif(strlen($newPassword) < 8){
        $error = "Password must be at least 8 characters long.";
    } elseif(!preg_match('/[A-Z]/', $newPassword)){
        $error = "Password must include at least one uppercase letter.";
    } elseif(!preg_match('/[\W]/', $newPassword)){
        $error = "Password must include at least one symbol.";
    } elseif(password_verify($newPassword, $account['password'] ?? "")){
        $error = "New password must be different from your current password.";
    } else {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if(authUpdateCurrentPassword($mysqli, $passwordHash)){
            $_SESSION['must_change_password'] = 0;

            $username = $_SESSION['username'];
            $role = $_SESSION['role'] ?? "UNKNOWN";
            $accountType = $_SESSION['account_type'] ?? "UNKNOWN";
            $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
            $time = date("Y-m-d H:i:s");

            logActivity(
                $mysqli,
                $username,
                $role,
                "FIRST LOGIN PASSWORD CHANGE",
                "User [$username] changed their first-login password.\nAccount Type: $accountType\nIP Address: $ip\nTime: $time"
            );

            header("Location: dashboard.php");
            exit();
        }

        $error = "Failed to update password. Please try again.";
    }
}

$csrfToken = ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password</title>
<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body{
    min-height:100vh;
    margin:0;
    display:flex;
    align-items:center;
    justify-content:center;
    position:relative;
    overflow:hidden;
    font-family:Arial, sans-serif;
    padding:24px;
}
body::before{
    content:"";
    position:absolute;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:url("../image/login_background.jpeg") no-repeat center center/cover;
    filter:blur(0px);
    z-index:-1;
}
.password-card{
    width:100%;
    max-width:430px;
    background:#fff;
    border-radius:8px;
    box-shadow:0 4px 10px rgba(0,0,0,0.5);
    overflow:hidden;
}
.password-card-header{
    background:#fff;
    color:#111;
    padding:26px 24px 12px;
    text-align:center;
}
.password-card-body{
    padding:12px 30px 30px;
}
.logo-mark{
    width:25px;
    height:auto;
    object-fit:contain;
    margin-bottom:10px;
}
.form-control{
    min-height:44px;
}
.password-title{
    font-size:24px;
    font-weight:bold;
    margin:0;
}
</style>
</head>
<body>
<main class="password-card">
    <div class="password-card-header">
        <img src="../image/logo.png" class="logo-mark" alt="Crossroad">
        <h1 class="password-title">Change Password</h1>
        <div class="small text-muted mt-1">First login for <?= changePasswordEscape($_SESSION['username'] ?? '') ?></div>
    </div>

    <div class="password-card-body">
        <?php if($error !== ""): ?>
            <div class="alert alert-danger"><?= changePasswordEscape($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= changePasswordEscape($csrfToken) ?>">

            <div class="mb-3">
                <label class="form-label" for="current_password">Current Password</label>
                <input type="password" class="form-control" id="current_password" name="current_password" required>
            </div>

            <div class="mb-3">
                <label class="form-label" for="new_password">New Password</label>
                <input type="password" class="form-control" id="new_password" name="new_password" required>
                <div class="form-text">Minimum 8 characters, one uppercase letter, and one symbol.</div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn btn-warning w-100 fw-semibold">
                <i class="fa fa-key me-1"></i> Save Password
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="logout.php" class="small text-muted">Logout</a>
        </div>
    </div>
</main>
</body>
</html>
