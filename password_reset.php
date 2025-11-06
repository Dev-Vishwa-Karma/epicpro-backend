<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

include 'db_connection.php';
include 'mailer.php';
include 'helpers.php';

//Add for mails
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

header('Content-Type: application/json');


$action = !empty($_GET['action']) ? $_GET['action'] : 'view';

if (isset($action)) {
    switch ($action) {
        case 'forgot-password':
            $email = $_POST['email'] ?? null;

            /** Validate */
            if (!$email) {
                sendJsonResponse('error', null, 'Email is required');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJsonResponse('error', null, 'Please enter a valid email address');
            }

            // Check if email exists
            $stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM employees WHERE email = ? AND deleted_at IS NULL AND status = 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                sendJsonResponse('error', null, 'No account found with this email address.');
            }

            $user = $result->fetch_assoc();

            // Generate reset token
            $reset_token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store token in database
            $stmt = $conn->prepare("UPDATE employees SET reset_token = ?, reset_expires_at = ? WHERE id = ?");
            $stmt->bind_param("ssi", $reset_token, $expires_at, $user['id']);

            if (!$stmt->execute()) {
                sendJsonResponse('error', null, 'Failed to generate reset token. Please try again.');
            }

            // Send reset email
            $host = $_SERVER['HTTP_HOST'];
            if ($host === 'hr.profilics.com') {
                $base_url = 'https://hr.profilics.com';
            } else {
                $base_url = 'http://localhost:3000';
            }
            $reset_link = $base_url . "/reset-password?token=" . $reset_token;
            $subject = "Password Reset Request - Profilics Systems";
            $body = "
            <html>
                <body style='margin:0; padding:0; font-family: Arial, sans-serif; background-color:#f6f8fa;'>
                    <table align='center' cellpadding='0' cellspacing='0' width='100%' style='max-width:600px; background-color:#ffffff; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.1); margin:40px auto;'>
                    <tr>
                        <td style='background-color:#007bff; padding:20px; text-align:center; border-top-left-radius:8px; border-top-right-radius:8px;'>
                        <table align='center' cellpadding='0' cellspacing='0' style='margin:0 auto;'>
                            <tr>
                            <td style='vertical-align:middle; padding-right:10px;'>
                                <img src='https://ik.imagekit.io/sentyaztie/profilics_logo-removebg-preview.png?updatedAt=1754393233457' alt='Profilics Systems Logo' width='40' height='40' style='border-radius:5px; vertical-align:middle;'>
                            </td>
                            <td style='vertical-align:middle;'>
                                <h1 style='color:#ffffff; margin:0; font-size:22px;'>Profilics Systems</h1>
                            </td>
                            </tr>
                        </table>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding:30px; color:#333333;'>
                        <h2 style='color:#007bff; font-size:20px; margin-bottom:10px;'>Password Reset Request</h2>
                        <p style='font-size:15px; line-height:1.6; margin:0 0 15px;'>Dear <strong>{$user['first_name']} {$user['last_name']}</strong>,</p>
                        <p style='font-size:15px; line-height:1.6; margin:0 0 15px;'>
                            We received a request to reset your password for your Profilics Systems account. Please click the button below to set a new password.
                        </p>
                        <p style='text-align:center; margin:30px 0;'>
                            <a href='{$reset_link}' style='background-color:#007bff; color:#ffffff; padding:12px 25px; font-size:16px; text-decoration:none; border-radius:5px; display:inline-block;'>
                            Reset Password
                            </a>
                        </p>
                        <p style='font-size:14px; color:#555555; line-height:1.6; margin:0 0 15px;'>
                            This link will expire in <strong>1 hour</strong>. If you did not request a password reset, please ignore this email or contact our support team.
                        </p>
                        <hr style='border:none; border-top:1px solid #e0e0e0; margin:25px 0;'>
                        <p style='font-size:14px; color:#777777; line-height:1.6; margin:0;'>
                            Best regards,<br>
                            <strong>Profilics Systems Team</strong><br>
                            <a href='https://hr.profilics.com/' style='color:#007bff; text-decoration:none;'>hr.profilics.com</a>
                        </p>
                        </td>
                    </tr>
                    <tr>
                        <td style='background-color:#f1f1f1; text-align:center; padding:15px; border-bottom-left-radius:8px; border-bottom-right-radius:8px;'>
                        <p style='font-size:12px; color:#888888; margin:0;'>
                            © " . date('Y') . " Profilics Systems. All rights reserved.
                        </p>
                        </td>
                    </tr>
                    </table>
                </body>
            </html>
            ";

            if (sendEmail($email, $subject, $body)) {
                sendJsonResponse('success', null, 'Password reset link has been sent to your email address.');
            } else {
                sendJsonResponse('error', null, 'Failed to send email. Please try again.');
            }

            break;

        case 'reset-password':
            $token = $_POST['token'] ?? null;
            $new_password = $_POST['new_password'] ?? null;
            $confirm_password = $_POST['confirm_password'] ?? null;

            /** Validate */
            if (!$token) {
                sendJsonResponse('error', null, 'Reset token is required');
            }
            if (!$new_password) {
                sendJsonResponse('error', null, 'New password is required');
            }
            if (!$confirm_password) {
                sendJsonResponse('error', null, 'Confirm password is required');
            }
            if ($new_password !== $confirm_password) {
                sendJsonResponse('error', null, 'Passwords do not match');
            }
            if (strlen($new_password) < 6) {
                sendJsonResponse('error', null, 'Password must be at least 6 characters long');
            }

            // Verify token
            $stmt = $conn->prepare("SELECT id, reset_expires_at FROM employees WHERE reset_token = ? AND deleted_at IS NULL AND status = 1");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                sendJsonResponse('error', null, 'Invalid or expired reset token.');
            }

            $user = $result->fetch_assoc();

            // Check if token is expired
            if (strtotime($user['reset_expires_at']) < time()) {
                sendJsonResponse('error', null, 'Reset token has expired. Please request a new one.');
            }

            // Update password and clear token
            $hashed_password = md5($new_password);
            $stmt = $conn->prepare("UPDATE employees SET password = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $user['id']);

            if ($stmt->execute()) {
                sendJsonResponse('success', null, 'Password has been reset successfully. You can now login with your new password.');
            } else {
                sendJsonResponse('error', null, 'Failed to reset password. Please try again.');
            }

            break;

        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
} else {
    sendJsonResponse('error', null, 'Action parameter is missing');
}