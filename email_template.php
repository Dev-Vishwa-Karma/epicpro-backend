<?php

class EmailTemplate
{

  /**
   * Email Template for connects.
   */
  public static function emailTemplate($userName, $message, $subject, $config)
  {
    $year = date('Y');

    return "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
          <title>{$subject}</title>
          <style>
            body {
              font-family: Arial, Helvetica, sans-serif;
              background-color: #f4f6f9;
              margin: 0;
              padding: 0;
            }

            .container {
              max-width: 600px;
              margin: 40px auto;
              background: #ffffff;
              border-radius: 8px;
              overflow: hidden;
              box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            .header {
              background: #2d6cdf;
              color: #ffffff;
              padding: 24px;
              text-align: center;
              font-size: 18px;
              font-weight: bold;
            }

            .content {
              padding: 24px;
              color: #333333;
              font-size: 15px;
              line-height: 1.6;
            }

            .content h2 {
              margin-top: 0;
              font-size: 18px;
              color: #222;
            }

            .button {
              display: inline-block;
              margin-top: 20px;
              padding: 12px 18px;
              background-color: #2d6cdf;
              color: #ffffff !important;
              text-decoration: none;
              border-radius: 6px;
              font-weight: 500;
            }

            .footer {
              background: #f1f3f5;
              text-align: center;
              padding: 14px;
              font-size: 12px;
              color: #666;
            }

            .muted {
              color: #888;
              font-size: 13px;
            }
          </style>
        </head>

        <body>

          <div class='container'>

            <div class='header'>
              Connects
            </div>

            <div class='content'>

              <h2>Dear {$userName},</h2>

              <p>Title:{$subject}</p></br>

              <p>Message:{$message}</p>

              <a class='button' href='{$config['redirect_path']}'>
                View Details
              </a>

              <p class='muted' style='margin-top:20px;'>
                If you have any questions, feel free to contact our support team.
              </p>

              <p>Thanks,<br><strong>Team Profilics</strong></p>

            </div>

            <div class='footer'>
              © {$year} HR Profilics. All rights reserved.
            </div>

          </div>

        </body>
        </html>
        ";
  }
  /**
   * Email template for email address change verification.
   */
  public static function emailChangeVerification(
    string $userName,
    string $newEmail,
    string $otpCode,
    string $subject = 'Verify Your New Email Address - EPIC HR'
  ): string {
    $year = date('Y');

    $userName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $newEmail = htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8');
    $otpCode  = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');
    $subject  = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

    return "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
          <title>{$subject}</title>

          <style>
            body {
              font-family: Arial, Helvetica, sans-serif;
              background-color: #f4f6f9;
              margin: 0;
              padding: 0;
            }

            .container {
              max-width: 600px;
              margin: 40px auto;
              background: #ffffff;
              border-radius: 8px;
              overflow: hidden;
              box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            .header {
              background: #2d6cdf;
              color: #ffffff;
              padding: 24px;
              text-align: center;
              font-size: 20px;
              font-weight: bold;
            }

            .content {
              padding: 28px 24px;
              color: #333333;
              font-size: 15px;
              line-height: 1.6;
            }

            .content h2 {
              margin-top: 0;
              font-size: 20px;
              color: #222222;
            }

            .email-box {
              background-color: #f8f9fa;
              border: 1px solid #e9ecef;
              padding: 12px 15px;
              border-radius: 6px;
              margin: 15px 0 20px;
              text-align: center;
            }

            .otp {
              background-color: #f4f6f9;
              padding: 18px;
              text-align: center;
              border-radius: 6px;
              font-size: 28px;
              font-weight: bold;
              letter-spacing: 6px;
              color: #206bc4;
              margin: 20px 0;
            }

            .notice {
              background-color: #fff8e1;
              border-left: 4px solid #ffc107;
              padding: 12px 15px;
              margin-top: 20px;
              color: #665c00;
              font-size: 13px;
            }

            .footer {
              background: #f1f3f5;
              text-align: center;
              padding: 14px;
              font-size: 12px;
              color: #666666;
            }

            .muted {
              color: #888888;
              font-size: 13px;
            }
          </style>
        </head>

        <body>

          <div class='container'>

            <div class='header'>
              EPIC HR
            </div>

            <div class='content'>

              <h2>Email Address Change Verification</h2>

              <p>
                Hello <strong>{$userName}</strong>,
              </p>

              <p>
                You have requested to change your email address
                associated with your EPIC HR account.
              </p>

              <p>
                Your new email address is:
              </p>

              <div class='email-box'>
                <strong>{$newEmail}</strong>
              </div>

              <p>
                Please enter the following verification code to
                confirm this email address:
              </p>

              <div class='otp'>
                {$otpCode}
              </div>

              <p>
                This code is valid for <strong>15 minutes</strong>.
              </p>

              <div class='notice'>
                <strong>Security notice:</strong>
                If you did not request this email address change,
                please ignore this email.
              </div>

              <p style='margin-top: 25px;'>
                Thanks,<br>
                <strong>EPIC HR Team</strong>
              </p>

            </div>

            <div class='footer'>
              © {$year} HR Profilics. All rights reserved.
            </div>

          </div>

        </body>
        </html>
        ";
  }

  /**
   * Email template for Resend email address change verification.
   */
  public static function emailChangeResendVerification(
    string $userName,
    string $newEmail,
    string $otpCode,
    string $subject = 'Resent: Verify Your New Email Address - EPIC HR'
  ): string {
    $year = date('Y');

    $userName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $newEmail = htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8');
    $otpCode  = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');
    $subject  = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

    return "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
          <title>{$subject}</title>

          <style>
            body {
              font-family: Arial, Helvetica, sans-serif;
              background-color: #f4f6f9;
              margin: 0;
              padding: 0;
            }

            .container {
              max-width: 600px;
              margin: 40px auto;
              background: #ffffff;
              border-radius: 8px;
              overflow: hidden;
              box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            .header {
              background: #2d6cdf;
              color: #ffffff;
              padding: 24px;
              text-align: center;
              font-size: 20px;
              font-weight: bold;
            }

            .content {
              padding: 28px 24px;
              color: #333333;
              font-size: 15px;
              line-height: 1.6;
            }

            .content h2 {
              margin-top: 0;
              font-size: 20px;
              color: #222222;
            }

            .email-box {
              background-color: #f8f9fa;
              border: 1px solid #e9ecef;
              padding: 12px 15px;
              border-radius: 6px;
              margin: 15px 0 20px;
              text-align: center;
            }

            .otp {
              background-color: #f4f6f9;
              padding: 18px;
              text-align: center;
              border-radius: 6px;
              font-size: 28px;
              font-weight: bold;
              letter-spacing: 6px;
              color: #206bc4;
              margin: 20px 0;
            }

            .notice {
              background-color: #fff8e1;
              border-left: 4px solid #ffc107;
              padding: 12px 15px;
              margin-top: 20px;
              color: #665c00;
              font-size: 13px;
            }

            .footer {
              background: #f1f3f5;
              text-align: center;
              padding: 14px;
              font-size: 12px;
              color: #666666;
            }

            .muted {
              color: #888888;
              font-size: 13px;
            }
          </style>
        </head>

        <body>

          <div class='container'>

            <div class='header'>
              EPIC HR
            </div>

            <div class='content'>

              <h2>Resent: Verification Code</h2>

              <p>
                Hello <strong>{$userName}</strong>,
              </p>

              <p>
                We received a request to resend the verification code for updating 
                your email address on your EPIC HR account.
              </p>

              <p>
                Target email address:
              </p>

              <div class='email-box'>
                <strong>{$newEmail}</strong>
              </div>

              <p>
                Please use the following new verification code:
              </p>

              <div class='otp'>
                {$otpCode}
              </div>

              <p>
                This code is valid for <strong>15 minutes</strong>.
              </p>

              <div class='notice'>
                <strong>Security notice:</strong>
                If you did not request to resend this code, please review your account 
                security immediately or contact support.
              </div>

              <p style='margin-top: 25px;'>
                Thanks,<br>
                <strong>EPIC HR Team</strong>
              </p>

            </div>

            <div class='footer'>
              © {$year} HR Profilics. All rights reserved.
            </div>

          </div>

        </body>
        </html>
        ";
  }
  /**
   * Email template for successful email address change.
   */
  public static function emailChangeSuccess(
    string $userName,
    string $subject = 'Email Address Changed Successfully - EPIC HR'
  ) {
    $year = date('Y');

    $userName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $subject  = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
      <meta charset='UTF-8'>
      <meta name='viewport' content='width=device-width, initial-scale=1.0'>
      <title>{$subject}</title>

      <style>
        body {
          font-family: Arial, Helvetica, sans-serif;
          background-color: #f4f6f9;
          margin: 0;
          padding: 0;
        }

        .container {
          max-width: 600px;
          margin: 40px auto;
          background: #ffffff;
          border-radius: 8px;
          overflow: hidden;
          box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .header {
          background: #2d6cdf;
          color: #ffffff;
          padding: 24px;
          text-align: center;
          font-size: 20px;
          font-weight: bold;
        }

        .content {
          padding: 28px 24px;
          color: #333333;
          font-size: 15px;
          line-height: 1.6;
        }

        .content h2 {
          margin-top: 0;
          color: #222222;
          font-size: 20px;
        }

        .success {
          background-color: #e8f7ee;
          border: 1px solid #b7e4c7;
          color: #1e7e34;
          padding: 16px;
          border-radius: 6px;
          text-align: center;
          font-weight: bold;
          margin: 20px 0;
        }

        .email-box {
          background-color: #f8f9fa;
          border: 1px solid #e9ecef;
          padding: 14px;
          border-radius: 6px;
          text-align: center;
          margin: 15px 0;
        }

        .muted {
          color: #888888;
          font-size: 13px;
        }

        .footer {
          background: #f1f3f5;
          text-align: center;
          padding: 14px;
          font-size: 12px;
          color: #666666;
        }
      </style>
    </head>

    <body>

      <div class='container'>

        <div class='header'>
          EPIC HR
        </div>

        <div class='content'>

          <h2>Email Address Updated Successfully</h2>

          <p>
            Hello <strong>{$userName}</strong>,
          </p>

          <div class='success'>
            ✓ Your email address has been successfully changed.
          </div>

          <p>
            You can now use this email address for future
            communications and account-related notifications.
          </p>

          <p class='muted'>
            If you did not make this change, please contact your
            administrator or support team immediately.
          </p>

          <p style='margin-top: 25px;'>
            Thanks,<br>
            <strong>EPIC HR Team</strong>
          </p>

        </div>

        <div class='footer'>
          © {$year} HR Profilics. All rights reserved.
        </div>

      </div>

    </body>
    </html>
    ";
  }
}
