<?php

class EmailTemplate {

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
}