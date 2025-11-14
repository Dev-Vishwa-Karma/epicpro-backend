<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    // Enable SMTP debug output only on localhost
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if ($host === 'hr.profilics.com') {
        $mail->SMTPDebug = 0;
    } else {
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            error_log("SMTP Debug level {$level}: {$str}");
        };
    }

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'akash.profilics@gmail.com';
        // $mail->Password   = 'pojbqwmqwngvalhw';
        $mail->Password   = 'hjwftnvbytezjbof';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->Timeout    = 30;

        // Recipients
        $mail->setFrom('akash.profilics@gmail.com', 'Profilics Systems');
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

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
?>
