<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require_once __DIR__ . '/email_template.php';

function sendMailToUsers($users, $to, $subject, $message, $attachments = [], $config = []) {
    $results = [];

    foreach ($users as $user) {
        $mail = new PHPMailer(true);

        try {
            // SMTP config
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $config['port'];

            // $mail->SMTPDebug = 2;
            // $mail->Debugoutput = 'html';

            // Email setup
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($user['email']);
            $mail->Subject = $config['subject'];

            $body = EmailTemplate::emailTemplate(
                $user['name'],
                $message,
                $subject
            );

            $mail->isHTML(true);
            $mail->Body = $body;
            $mail->AltBody = strip_tags($message);
            $mail->send();

            $results[] = [
                "email" => $user['email'],
                "status" => true
            ];

        } catch (Exception $e) {
            $results[] = [
                "email" => $user['email'],
                "status" => false,
                "error" => $mail->ErrorInfo
            ];
        }
    }

    return $results;
}