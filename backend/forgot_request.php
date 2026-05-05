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
        $mail->Username   = 'crossroadinventory@gmail.com';
        $mail->Password   = 'iitrihnuejntcpkc';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        // DEBUG DISABLED
        $mail->SMTPDebug = 0;

        // EMAIL DETAILS
        $mail->setFrom('crossroadinventory@gmail.com', 'Crossroad System');

        /*
        =========================
        MAIN EMAIL RECEIVER
        =========================
        This is needed as the main "To" receiver.
        All administrators will be CC below.
        */
        $mail->addAddress('crossroadinventory@gmail.com', 'Crossroad System');

        // =========================
        // GET ADMIN EMAIL(S) AS CC
        // EXCLUDE fazdlan@crossroad.my
        // =========================
        $excludedAdminEmail = "fazdlan@crossroad.my";

        $stmt = $mysqli->prepare("
            SELECT email
            FROM administrator
            WHERE email IS NOT NULL
            AND email != ''
        ");

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->execute();
        $stmt->bind_result($admin_email);

        $hasAdmin = false;
        $addedEmails = [];

        while($stmt->fetch()){

            $admin_email_clean = strtolower(trim($admin_email));

            if($admin_email_clean === ""){
                continue;
            }

            // Skip Fazdlan account
            if($admin_email_clean === strtolower($excludedAdminEmail)){
                continue;
            }

            // Avoid duplicate CC
            if(in_array($admin_email_clean, $addedEmails, true)){
                continue;
            }

            $mail->addCC($admin_email_clean);
            $addedEmails[] = $admin_email_clean;
            $hasAdmin = true;
        }

        $stmt->close();

        if(!$hasAdmin){
            die("No administrator email found in database after excluding fazdlan@crossroad.my");
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

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
    font-family: Arial, sans-serif;
    height: 100vh;
    margin: 0;
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;
    overflow: hidden;
}

body::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: url("../image/login_background.jpeg") no-repeat center center/cover;
    filter: blur(0px);
    z-index: -1;
}

.login-box{
    background:white;
    padding:30px;
    width:350px;
    border-radius:8px;
    box-shadow:0 4px 10px rgba(0,0,0,0.5);
}

.logo-container {
    text-align: center;
    margin-bottom: 20px;
}

.logo-text {
    display: flex;
    gap: 10px;
    font-size: 24px;
    font-weight: bold;
    justify-content: center;
    align-items: center;
    margin-bottom: 0;
}

.logo-text span {
    font-size: 16px;
    text-align: left;
}

.logo {
    width: 25px;
    height: auto;
}

.page-title{
    font-size:20px;
    font-weight:bold;
    margin-bottom:8px;
    text-align:center;
}

.page-desc{
    font-size:14px;
    color:#666;
    text-align:center;
    margin-bottom:22px;
}

label{
    font-size:14px;
    font-weight:400;
}

input{
    width:100%;
    padding:10px;
    margin-top:5px;
    margin-bottom:20px;
    border:1px solid #ccc;
    border-radius:5px;
    box-sizing:border-box;
}

button{
    width:100%;
    padding:10px;
    border:none;
    background:#ffc107;
    color:black;
    font-size:16px;
    border-radius:5px;
    cursor:pointer;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:8px;
}

button:hover{
    background:#edb100;
}

button:disabled{
    opacity:0.75;
    cursor:not-allowed;
}

.back-login{
    display:block;
    text-align:center;
    margin-top:15px;
    font-size:14px;
    color:#333;
    text-decoration:none;
}

.back-login:hover{
    text-decoration:underline;
    color:#000;
}

/* =========================
   LOADING SCREEN
========================= */
.loading-overlay{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.65);
    backdrop-filter:blur(4px);
    display:none;
    justify-content:center;
    align-items:center;
    z-index:9999;
}

.loading-box{
    background:white;
    width:330px;
    padding:30px;
    border-radius:14px;
    box-shadow:0 10px 35px rgba(0,0,0,0.35);
    text-align:center;
    animation:popIn 0.25s ease;
}

.loading-icon{
    width:58px;
    height:58px;
    border:6px solid #f1f1f1;
    border-top:6px solid #ffc107;
    border-radius:50%;
    animation:spin 0.9s linear infinite;
    margin:0 auto 18px auto;
}

.loading-title{
    font-size:18px;
    font-weight:bold;
    margin-bottom:6px;
}

.loading-text{
    font-size:14px;
    color:#666;
    margin-bottom:0;
}

.loading-dots span{
    animation:blink 1.4s infinite;
    font-weight:bold;
}

.loading-dots span:nth-child(2){
    animation-delay:0.2s;
}

.loading-dots span:nth-child(3){
    animation-delay:0.4s;
}

@keyframes spin{
    from{
        transform:rotate(0deg);
    }
    to{
        transform:rotate(360deg);
    }
}

@keyframes popIn{
    from{
        transform:scale(0.92);
        opacity:0;
    }
    to{
        transform:scale(1);
        opacity:1;
    }
}

@keyframes blink{
    0%, 80%, 100%{
        opacity:0;
    }
    40%{
        opacity:1;
    }
}
</style>
</head>

<body>

<!-- LOADING SCREEN -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-box">
        <div class="loading-icon"></div>
        <div class="loading-title">Sending Request</div>
        <p class="loading-text">
            Please wait while we notify the administrator
            <span class="loading-dots">
                <span>.</span><span>.</span><span>.</span>
            </span>
        </p>
    </div>
</div>

<div class="login-box">
    <h4 class="page-title">Forgot Password</h4>
    <p class="page-desc">Enter your registered email to request password reset assistance.</p>

    <form method="POST" id="forgotForm">
        <!-- Keeps your existing PHP isset($_POST['submit']) working even when button is disabled -->
        <input type="hidden" name="submit" value="1">

        <label>Email</label>
        <input type="email" name="email" required>

        <br>
        <br>

        <button name="submit" type="submit" id="sendBtn">
            <i class="fa-solid fa-paper-plane"></i> Send Request
        </button>

        <a href="../frontend/index.html" class="back-login" id="backLoginLink">
            <i class="fa-solid fa-arrow-left"></i> Back to Login
        </a>
    </form>

</div>

<script>
document.getElementById("forgotForm").addEventListener("submit", function(){
    const loadingOverlay = document.getElementById("loadingOverlay");
    const sendBtn = document.getElementById("sendBtn");
    const backLoginLink = document.getElementById("backLoginLink");

    loadingOverlay.style.display = "flex";

    sendBtn.disabled = true;
    sendBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';

    backLoginLink.style.pointerEvents = "none";
    backLoginLink.style.opacity = "0.5";
});
</script>

</body>
</html>