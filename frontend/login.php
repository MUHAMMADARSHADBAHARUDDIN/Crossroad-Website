<?php
session_start();
require_once 'includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $mysqli->prepare("SELECT password FROM user WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 1) {
$stmt->bind_result($db_password);
$stmt->fetch();

if ($password === $db_password) {
$_SESSION['username'] = $username;
header("Location: dashboard.php");
exit();
} else {
$error = "Invalid username or password!";// checking error
}
} else {
$error = "Invalid username or password!";
}

$stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Crossroad Inventory</title>
</head>
<body>
<h2>Login</h2>

<?php if (!empty($error)) : ?>
<div style="color:red;"><?php echo $error; ?></div>
<?php endif; ?>

<form method="POST" action="">
    <label>Username</label>
    <input type="text" name="username" required><br><br>

    <label>Password</label>
    <input type="password" name="password" required><br><br>

    <button type="submit">Login</button>
</form>
</body>
</html>