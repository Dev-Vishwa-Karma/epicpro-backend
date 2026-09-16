<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$config = require __DIR__ . '/config.php';
function sendEmail($to, $subject, $body)
{
    global $config;
    $mail = new PHPMailer(true);

    // Enable SMTP debug output only on localhost
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if ($host === 'hr.profilics.com') {
        $mail->SMTPDebug = 0;
    } else {
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function ($str, $level) {
            error_log("SMTP Debug level {$level}: {$str}");
        };
    }

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $config['email']['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['email']['username'];
        $mail->Password = $config['email']['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $config['email']['port'];
        $mail->Timeout    = 30;

        // Recipients
        $mail->setFrom($config['email']['from_email'], $config['email']['from_name']);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        // Try sending
        $mail->send();
        error_log("Mail successfully sent to {$to}");
        return true;
    } catch (Exception $e) {
        $error = "PHPMailer Error: " . $mail->ErrorInfo . " | Exception: " . $e->getMessage();
        error_log($error);
        return $error;
    }
}
