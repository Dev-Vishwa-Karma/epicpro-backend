<?php
function getAdminNotificationEmail($applicant) {
    $skillsList = implode(', ', json_decode($applicant['skills'], true));
    
    return
     '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>New Application Received</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background-color: #f4f6f8;
                margin: 0;
                padding: 0;
            }
            .container {
                max-width: 600px;
                background-color: #ffffff;
                margin: 40px auto;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 30px;
            }
            .header {
                text-align: center;
                padding-bottom: 20px;
                border-bottom: 1px solid #e0e0e0;
            }
            .logo {
                max-height: 50px;
                margin-bottom: 10px;
            }
            h2 {
                color: #333333;
            }
            p {
                line-height: 1.6;
                color: #555555;
            }
            .info-label {
                font-weight: bold;
                color: #333333;
            }
            .footer {
                margin-top: 30px;
                text-align: center;
                font-size: 12px;
                color: #999999;
            }
            .button {
                display: inline-block;
                margin-top: 15px;
                padding: 10px 20px;
                background-color: #007bff;
                color: #ffffff !important;
                text-decoration: none;
                border-radius: 5px;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <img src="https://media.licdn.com/dms/image/v2/C4E1BAQHFGqLkG3JFdQ/company-background_10000/company-background_10000/0/1594115529786/profilics_cover?e=2147483647&v=beta&t=DTPwUTb3dR51d6ofWRy95FDEJJkpNOdq1hc-bmTXtPI" alt="Company Logo" class="logo">
                <h2>New Application Submission</h2>
            </div>
            <p><span class="info-label">Name:</span> ' . $applicant['fullname'] . '</p>
            <p><span class="info-label">Email:</span> ' . $applicant['email'] . '</p>
            <p><span class="info-label">Phone:</span> ' . $applicant['phone'] . '</p>
            <p><span class="info-label">Address:</span> ' . $applicant['streetaddress'] . '</p>
            <p><span class="info-label">Experience:</span> ' . $applicant['experience'] . '</p>
            <p><span class="info-label">Skills:</span> ' . $skillsList . '</p>
            
            <p>You can view the full application in the admin dashboard.</p>
            <p><a class="button" href="http://localhost:3000/applicant">Go to Dashboard</a></p>

            <div class="footer">
                © ' . date('Y') . ' Your Company. All rights reserved.
            </div>
        </div>
    </body>
    </html>';
}

function getApplicantConfirmationEmail($applicant) {
    $skillsList = implode(', ', json_decode($applicant['skills'], true));
    $logoUrl = "https://media.licdn.com/dms/image/v2/C4E1BAQHFGqLkG3JFdQ/company-background_10000/company-background_10000/0/1594115529786/profilics_cover?e=2147483647&v=beta&t=DTPwUTb3dR51d6ofWRy95FDEJJkpNOdq1hc-bmTXtPI";
    
    return 
    '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; border:1px solid #e0e0e0; border-radius:8px; overflow:hidden;">
        <div style="background: #f8f8f8; padding: 24px 24px 12px 24px; text-align: center;">
            <img src="' . $logoUrl . '" alt="Profilics Systems" style="max-width: 180px; margin-bottom: 10px;">
        </div>
        <div style="padding: 24px;">
            <p style="font-size: 18px; color: #333;">Dear <b>' . htmlspecialchars($applicant['fullname']) . '</b>,</p>
            <p style="font-size: 16px; color: #444;">
                Thank you for applying for a position at <b>Profilics Systems</b>.<br>
                We have received your application and our team will review your profile soon.
            </p>
            <div style="background: #f3f7fa; border-radius: 6px; padding: 16px; margin: 20px 0;">
                <strong>Summary of your submission:</strong><br>
                <b>Email:</b> ' . htmlspecialchars($applicant['email']) . '<br>
                <b>Phone:</b> ' . htmlspecialchars($applicant['phone']) . '<br>
                <b>Experience:</b> ' . htmlspecialchars($applicant['experience']) . '<br>
                <b>Skills:</b> ' . htmlspecialchars($skillsList) . '
            </div>
            <p style="font-size: 15px; color: #444;">
                We appreciate your interest and will be in touch if your profile matches our requirements.<br>
                If you have any questions, feel free to reply to this email.
            </p>
            <p style="margin-top: 32px; font-size: 15px;">
                Best regards,<br>
                <span style="color: #0a6ebd;"><b>Hiring Team</b></span><br>
                <span style="color: #888;">Profilics Systems</span>
            </p>
            <div style="background: #f8f8f8; padding: 12px 24px; text-align: center; color: #aaa; font-size: 13px;">
                Visit Our Website to know more .. <br>
                <button style="background:blue; color:white; border: 1px solid blue; border-radius:10px; padding:2px 5px; margin-top:4px;">
                    <a style="text-decoration:none; color:white;" href="https://www.profilics.com/">www.profilics.com</a>
                </button>
            </div>
        </div>
        <div style="background: #f8f8f8; padding: 12px 24px; text-align: center; color: #aaa; font-size: 13px;">
            &copy; ' . date('Y') . ' Profilics Systems. All rights reserved.
        </div>
    </div>';
}

function getStatusUpdateEmail($applicant, $status) {
    $message = "Dear {$applicant['fullname']},<br><br>";

    switch ($status) {
        case 'reviewed':
            $subject = "Your Application is Under Review";
            $message .= "Thank you for your interest in joining <strong>Profilics Systems</strong>.<br><br>";
            $message .= "We've carefully reviewed your application and appreciate the time and effort you invested in sharing your background with us. Your profile has caught our attention, and we're currently evaluating it for potential progression to the next stage.<br><br>";
            $message .= "You can expect to hear from us shortly regarding the outcome or further steps in the process.<br><br>";
            $message .= "We truly value your patience and continued interest.<br>";
            break;
        case 'interviewed':
            $subject = "Thank You for Your Interview";
            $message .= "Thank you for taking the time to speak with our team.<br><br>";
            $message .= "We greatly enjoyed learning more about your experience and the strengths you could bring to our organization. We are currently assessing all interviewed candidates to determine the best fit for the role.<br><br>";
            $message .= "We will keep you informed and aim to provide an update as soon as a final decision has been made.<br><br>";
            $message .= "We appreciate your time, effort, and interest in being part of Profilics Systems.<br>";
            break;
        case 'hired':
            $subject = "Congratulations! Job Offer from Profilics Systems";
            $message .= "<strong>Congratulations!</strong><br><br>";
            $message .= "We are delighted to extend an offer for the position you applied for at <strong>Profilics Systems</strong>.<br><br>";
            $message .= "Your qualifications and enthusiasm stood out during the selection process, and we are confident that you will be a valuable addition to our team.<br><br>";
            $message .= "Our HR department will be in touch shortly with your official offer letter, onboarding details, and other relevant information.<br><br>";
            $message .= "Welcome aboard—we look forward to a successful journey together!<br>";
            break;
        default:
            $subject = "Your Application Status Update";
            $message .= "This is an update regarding your application status at Profilics Systems.<br><br>";
    }

    $message .= '<p style="margin-top: 32px; font-size: 15px;">
                    Best regards,<br>
                    <span style="color: #0a6ebd;"><b>Hiring Team</b></span><br>
                    <span style="color: #888;">Profilics Systems</span>
                </p>';
    $message .= "<small>This is an automated message. Please do not reply to this email.</small>";

    return [
        'subject' => $subject,
        'message' => $message
    ];
}
?>