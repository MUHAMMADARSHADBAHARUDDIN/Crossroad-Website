<?php
session_start();
require_once "../includes/db_connect.php";

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';
require '../PHPMailer/src/Exception.php';

if(isset($_POST['submit'])){

    $email = trim($_POST['email']);

    // =========================
    // 1. CHECK EMAIL EXISTS
    // =========================
    $tables = ["user","administrator","system_admin"];
    $found = false;

    foreach($tables as $table){
        $stmt = $mysqli->prepare("SELECT email FROM $table WHERE email=?");

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if($stmt->num_rows == 1){
            $found = true;
            $stmt->close();
            break;
        }

        $stmt->close();
    }

    if(!$found){
        echo "<script>alert('Email not found'); window.location.href='../backend/forgot_request.php';</script>";
        exit();
    }

    // =========================
    // 2. SEND EMAIL USING PHPMailer
    // =========================
    $mail = new PHPMailer(true);

    try {
        // SMTP SETTINGS
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'crossroadinventory@gmail.com';   // ✅ YOUR SYSTEM EMAIL
        $mail->Password   = 'iitrihnuejntcpkc';               // ✅ APP PASSWORD (NO SPACES)
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        // DEBUG (REMOVE AFTER TESTING)
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = 'html';

        // EMAIL DETAILS
        $mail->setFrom('crossroadinventory@gmail.com', 'Crossroad System');

        // =========================
        // GET ADMIN EMAIL(S)
        // =========================
        $stmt = $mysqli->prepare("SELECT email FROM administrator");

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->execute();
        $stmt->bind_result($admin_email);

        $hasAdmin = false;

        while($stmt->fetch()){
            $mail->addAddress($admin_email);
            $hasAdmin = true;
        }

        $stmt->close();

        if(!$hasAdmin){
            die("No administrator email found in database");
        }

        // =========================
        // EMAIL CONTENT
        // =========================
        $mail->Subject = 'Password Reset Request';
        $mail->Body    = "User with email $email requested a password reset.\n\nPlease login to the system to handle this request.";

        // SEND
        $mail->send();

        echo "<script>alert('Request sent successfully'); window.location.href='../frontend/index.html';</script>";
        exit();

    } catch (Exception $e) {
        echo "Mailer Error: " . $mail->ErrorInfo;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    background: url("../image/login_background.jpeg") no-repeat center center/cover;
    font-family:Arial,sans-serif;
}
.box{
    background:white;
    padding:30px;
    border-radius:8px;
    box-shadow:0 2px 8px rgba(0,0,0,0.3);
    width:350px;
}
button{
    width:100%;
    padding:10px;
    border:none;
    background:#ffc107;
    color:black;
    border-radius:5px;
    cursor:pointer;
}
button:hover{
    background:#edb100;
}
</style>
</head>

<body>
<div class="box">
    <h3>Forgot Password</h3>

    <form method="POST">
        <label>Email</label>
        <input type="email" name="email" class="form-control" required>
        <button name="submit" class="mt-3">Send Request</button>
    </form>
</div>
</body>
</html>
