<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

include 'helpers.php';
include 'db_connection.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_template.php';

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
            $body = EmailTemplate::resetPasswordEmail($user, $reset_link, $subject);

            $result = sendEmail($email, $subject, $body);
            if ($result === true) {
                sendJsonResponse('success', null, 'Password reset link has been sent to your email address.');
            } else {
                sendJsonResponse('error', null, $result);
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
