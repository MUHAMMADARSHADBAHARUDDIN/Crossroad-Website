<?php
require_once __DIR__ . "/env.php";
require_once __DIR__ . "/../PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../PHPMailer/src/SMTP.php";
require_once __DIR__ . "/../PHPMailer/src/Exception.php";

if(!function_exists('crossroadMailConfig')){
    function crossroadMailConfig(){
        $username = trim((string)(getenv("CROSSROAD_SMTP_USER") ?: ""));

        return [
            "host" => trim((string)(getenv("CROSSROAD_SMTP_HOST") ?: "smtp.gmail.com")),
            "port" => max(1, (int)(getenv("CROSSROAD_SMTP_PORT") ?: 587)),
            "username" => $username,
            "password" => (string)(getenv("CROSSROAD_SMTP_PASSWORD") ?: ""),
            "from" => trim((string)(getenv("CROSSROAD_SMTP_FROM") ?: $username)),
            "from_name" => trim((string)(getenv("CROSSROAD_SMTP_FROM_NAME") ?: "Crossroad System")),
            "encryption" => strtolower(trim((string)(getenv("CROSSROAD_SMTP_ENCRYPTION") ?: "tls")))
        ];
    }
}

if(!function_exists('crossroadMailMissingSettings')){
    function crossroadMailMissingSettings(){
        $config = crossroadMailConfig();
        $required = [
            "host" => "CROSSROAD_SMTP_HOST",
            "username" => "CROSSROAD_SMTP_USER",
            "password" => "CROSSROAD_SMTP_PASSWORD",
            "from" => "CROSSROAD_SMTP_FROM"
        ];
        $missing = [];

        foreach($required as $key => $setting){
            if(trim((string)($config[$key] ?? "")) === ""){
                $missing[] = $setting;
            }
        }

        return $missing;
    }
}

if(!function_exists('crossroadCreateMailer')){
    function crossroadCreateMailer(){
        $missing = crossroadMailMissingSettings();

        if($missing){
            throw new RuntimeException("Email is not configured. Missing: " . implode(", ", $missing));
        }

        $config = crossroadMailConfig();
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = $config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->CharSet = "UTF-8";
        $mail->Timeout = 30;
        $mail->SMTPDebug = 0;

        if(in_array($config['encryption'], ["ssl", "smtps"], true)){
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        }
        elseif(in_array($config['encryption'], ["tls", "starttls"], true)){
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        else{
            $mail->SMTPSecure = "";
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($config['from'], $config['from_name']);
        return $mail;
    }
}

if(!function_exists('crossroadSendEmail')){
    function crossroadSendEmail($recipientEmail, $recipientName, $subject, $htmlBody, $textBody = ""){
        $recipientEmail = trim((string)$recipientEmail);
        $recipientName = trim((string)$recipientName);

        if(!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)){
            return [
                "success" => false,
                "response" => "Invalid recipient email address."
            ];
        }

        try{
            $mail = crossroadCreateMailer();
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = (string)$subject;
            $mail->Body = (string)$htmlBody;
            $mail->AltBody = $textBody !== "" ? (string)$textBody : trim(strip_tags((string)$htmlBody));
            $mail->send();

            return [
                "success" => true,
                "response" => "Email accepted by SMTP."
            ];
        }
        catch(Throwable $error){
            $details = isset($mail) && trim((string)$mail->ErrorInfo) !== ""
                ? $mail->ErrorInfo
                : $error->getMessage();

            return [
                "success" => false,
                "response" => $details
            ];
        }
    }
}
?>
