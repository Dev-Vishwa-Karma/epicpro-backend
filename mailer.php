<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/email_template.php';

$config = require __DIR__ . '/config.php';

/**
 * Creates and configures a new PHPMailer instance.
 *
 * @param array $emailConfig Optional email configuration override. If empty, uses $config['email'].
 * @return \PHPMailer\PHPMailer\PHPMailer
 */
function getMailerInstance(array $emailConfig = []): \PHPMailer\PHPMailer\PHPMailer
{
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    // Enable SMTP debug output only on non-production environment
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if ($host === 'hr.profilics.com') {
        $mail->SMTPDebug = 0;
    } else {
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function ($str, $level) {
            error_log("SMTP Debug level {$level}: {$str}");
        };
    }

    // Server settings
    $mail->isSMTP();
    $mail->Host       = $emailConfig['host'] ?? '';
    $mail->SMTPAuth   = true;
    $mail->Username   = $emailConfig['username'] ?? '';
    $mail->Password   = $emailConfig['password'] ?? '';
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $emailConfig['port'] ?? 587;
    $mail->Timeout    = 30;

    return $mail;
}

/**
 * Send a single email to a recipient.
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Email HTML body
 * @return bool|string True on success, error message string on failure
 */
function sendEmail(
    string $to,
    string $subject,
    string $body
) {
    global $config;
    $emailConfig = $config['email'] ?? [];

    try {
        $mail = getMailerInstance($emailConfig);
        // Recipients
        $fromEmail = $emailConfig['from_email'] ?? '';
        $fromName  = $emailConfig['from_name'] ?? '';
        $mail->setFrom($fromEmail, $fromName);
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
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $error = "PHPMailer Error: " . $e->getMessage();
        error_log($error);
        return $error;
    }
}

/**
 * Send Mail to multiple users with template styling.
 *
 * @param array $users List of users, each containing 'email' and 'name'
 * @param string $subject Email subject
 * @param string $message Email body content/message
 * @return array Array of sending results per user
 */
function sendMailToUsers(array $users, string $subject, string $message): array
{
    global $config;
    $emailConfig = $config['email'] ?? [];

    $results = [];

    try {
        $mail = getMailerInstance($emailConfig);
        $fromEmail = $emailConfig['from_email'] ?? '';
        $fromName  = $emailConfig['from_name'] ?? '';

        foreach ($users as $user) {
            try {
                $mail->clearAddresses();
                $mail->clearAttachments();
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($user['email']);
                $mail->Subject = $subject;

                $body = EmailTemplate::emailTemplate(
                    $user['name'],
                    $message,
                    $subject,
                    $emailConfig
                );

                $mail->isHTML(true);
                $mail->Body    = $body;
                $mail->AltBody = strip_tags($message);
                $mail->send();

                $results[] = [
                    "email" => $user['email'],
                    "status" => true
                ];
            } catch (\Throwable $th) {
                $results[] = [
                    "email" => $user['email'],
                    "status" => false,
                    "error" => $mail->ErrorInfo
                ];
            }
        }
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $results[] = [
            "email" => "",
            "status" => false,
            "error" => $e->getMessage()
        ];
    }

    return $results;
}
