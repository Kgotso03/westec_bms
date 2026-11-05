<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Outbound Email Notification & SMTP Dispatch Service
 *
 * Configuration file: config/mail.php
 */

/**
 * Loads the active mail configuration from config/mail.php.
 */
function getMailConfig(): array {
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/../config/mail.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
        } else {
            $config = [
                'driver' => 'smtp',
                'from' => [
                    'address' => 'noreply@westeceswatini.co.sz',
                    'name'    => 'WesTec Eswatini BMS'
                ],
                'reply_to' => [
                    'address' => 'support@westeceswatini.co.sz',
                    'name'    => 'WesTec Support Desk'
                ]
            ];
        }
    }
    return $config;
}

/**
 * Sends a welcome email containing login credentials to a newly created user.
 * 
 * @param array $params [
 *    'to_email'           => string (required),
 *    'username'           => string (required),
 *    'temporary_password' => string (required),
 *    'full_name'          => string (optional),
 *    'role_name'          => string (optional),
 *    'created_by_name'    => string (optional),
 *    'job_title'          => string (optional),
 *    'department'         => string (optional)
 * ]
 * @return array ['success' => bool, 'sent' => bool, 'message' => string, 'archive_file' => string|null]
 */
function sendNewUserWelcomeEmail(array $params): array {
    $config = getMailConfig();

    $toEmail = trim($params['to_email'] ?? '');
    $username = trim($params['username'] ?? '');
    $temporaryPassword = $params['temporary_password'] ?? '';
    $fullName = trim($params['full_name'] ?? '') ?: $username;
    $roleName = trim($params['role_name'] ?? '') ?: 'Staff Member';
    $createdByName = trim($params['created_by_name'] ?? '') ?: 'Administrator';
    $jobTitle = trim($params['job_title'] ?? '');
    $department = trim($params['department'] ?? '');

    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'sent'    => false,
            'message' => 'Invalid recipient email address.',
            'archive_file' => null
        ];
    }

    if (empty($username) || empty($temporaryPassword)) {
        return [
            'success' => false,
            'sent'    => false,
            'message' => 'Missing username or temporary password.',
            'archive_file' => null
        ];
    }

    // Determine login portal URL
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $loginUrl = $protocol . $host . '/westec_bms/auth/login.php';

    $subject = "Welcome to WesTec Eswatini BMS – Your Account Credentials";

    // Sender details from configuration
    $fromAddress = $config['from']['address'] ?? 'noreply@westeceswatini.co.sz';
    $fromName    = $config['from']['name'] ?? 'WesTec Eswatini BMS';
    $replyTo     = $config['reply_to']['address'] ?? 'support@westeceswatini.co.sz';

    // Build modern, branded responsive HTML email template
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 30px 10px; }
        .main-card { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
        .header { background: linear-gradient(135deg, #0f2942 0%, #1e3a5f 100%); padding: 32px 30px; text-align: center; color: #ffffff; }
        .brand-badge { display: inline-block; background-color: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; padding: 6px 14px; font-size: 13px; font-weight: 600; letter-spacing: 0.5px; margin-bottom: 12px; color: #5eead4; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: 0.85; }
        .body-content { padding: 32px 30px; }
        .greeting { font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #0f172a; }
        .intro-text { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
        .credentials-box { background-color: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #0d9488; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .credentials-box h3 { margin: 0 0 14px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; color: #0f766e; font-weight: 700; }
        .btn-container { text-align: center; margin: 30px 0 24px; }
        .btn-login { display: inline-block; background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%); color: #ffffff !important; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-weight: 600; font-size: 14px; letter-spacing: 0.3px; box-shadow: 0 4px 10px rgba(13,148,136,0.25); }
        .security-notice { background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; font-size: 12.5px; color: #92400e; line-height: 1.5; margin-bottom: 24px; }
        .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 22px 30px; text-align: center; font-size: 11.5px; color: #94a3b8; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="main-card">
            <div class="header">
                <div class="brand-badge">WESTEC ESWATINI BMS</div>
                <h1>Welcome to WesTec Eswatini</h1>
                <p>Enterprise Business Management & IT Operations Platform</p>
            </div>
            <div class="body-content">
                <div class="greeting">Hello {$fullName},</div>
                <div class="intro-text">
                    Your user account has been successfully provisioned by <strong>{$createdByName}</strong> on the <strong>WesTec Eswatini Business Management System (BMS)</strong>. Below are your official system credentials to access the management portal:
                </div>

                <div class="credentials-box">
                    <h3>Account Credentials</h3>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <tr style="border-bottom: 1px dashed #e2e8f0;">
                            <td style="padding: 8px 0; color: #64748b;"><strong>Username:</strong></td>
                            <td style="padding: 8px 0; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">{$username}</td>
                        </tr>
                        <tr style="border-bottom: 1px dashed #e2e8f0;">
                            <td style="padding: 8px 0; color: #64748b;"><strong>Temporary Password:</strong></td>
                            <td style="padding: 8px 0; text-align: right; font-family: monospace; font-weight: 700; color: #0d9488;">{$temporaryPassword}</td>
                        </tr>
                        <tr style="border-bottom: 1px dashed #e2e8f0;">
                            <td style="padding: 8px 0; color: #64748b;"><strong>Assigned Role:</strong></td>
                            <td style="padding: 8px 0; text-align: right; font-weight: 600; color: #0f172a;">{$roleName}</td>
                        </tr>
HTML;

    if (!empty($jobTitle)) {
        $htmlBody .= <<<HTML
                        <tr style="border-bottom: 1px dashed #e2e8f0;">
                            <td style="padding: 8px 0; color: #64748b;"><strong>Job Title:</strong></td>
                            <td style="padding: 8px 0; text-align: right; color: #0f172a;">{$jobTitle}</td>
                        </tr>
HTML;
    }

    if (!empty($department)) {
        $htmlBody .= <<<HTML
                        <tr style="border-bottom: 1px dashed #e2e8f0;">
                            <td style="padding: 8px 0; color: #64748b;"><strong>Department:</strong></td>
                            <td style="padding: 8px 0; text-align: right; color: #0f172a;">{$department}</td>
                        </tr>
HTML;
    }

    $htmlBody .= <<<HTML
                    </table>
                </div>

                <div class="btn-container">
                    <a href="{$loginUrl}" class="btn-login" target="_blank">Sign In to Management Portal &rarr;</a>
                </div>

                <div class="security-notice">
                    <strong>Security Recommendation:</strong> For security compliance, please log in and change your temporary password immediately. Do not share your login credentials with anyone.
                </div>

                <p style="font-size: 13px; color: #64748b; line-height: 1.5; margin: 0;">
                    If you did not request this account or believe this was sent in error, please contact your IT Manager or System Administrator immediately.
                </p>
            </div>
            <div class="footer">
                &copy; 2026 <strong>WesTec Eswatini (Pty) Ltd</strong> &bull; Business Management System<br>
                Mbabane &bull; Eswatini &bull; Support: <a href="mailto:{$replyTo}" style="color: #0d9488; text-decoration: none;">{$replyTo}</a><br>
                <span style="font-size: 10px; color: #cbd5e1;">This is an automated notification. Please do not reply directly to this email.</span>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

    // Dispatch based on configured driver
    $driver = strtolower($config['driver'] ?? 'smtp');
    $mailSent = false;
    $deliveryMessage = '';

    if ($driver === 'smtp') {
        $smtpResult = sendViaSmtp($toEmail, $subject, $htmlBody, $config);
        $mailSent = $smtpResult['sent'];
        $deliveryMessage = $smtpResult['message'];
    } elseif ($driver === 'mail') {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $replyTo,
            'X-Mailer: WesTec-BMS-Mailer/1.0'
        ];
        $mailSent = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));
        $deliveryMessage = $mailSent ? 'Dispatched via PHP mail()' : 'PHP mail() returned false (local sendmail not configured)';
    } else {
        // 'log' driver
        $mailSent = true;
        $deliveryMessage = 'Archived locally in storage/logs/ (log driver active)';
    }

    // Always archive outbound emails locally for auditability, verification, and inspection
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $archiveFile = $logDir . '/email_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $username) . '.html';
    @file_put_contents($archiveFile, $htmlBody);

    // Append to JSON ledger
    $ledgerFile = $logDir . '/outbound_emails.json';
    $ledger = [];
    if (file_exists($ledgerFile)) {
        $content = @file_get_contents($ledgerFile);
        $ledger = json_decode($content, true) ?: [];
    }

    $ledgerEntry = [
        'timestamp'    => date('Y-m-d H:i:s'),
        'recipient'    => $toEmail,
        'username'     => $username,
        'role'         => $roleName,
        'subject'      => $subject,
        'driver'       => $driver,
        'dispatched'   => $mailSent,
        'delivery_msg' => $deliveryMessage,
        'created_by'   => $createdByName,
        'archive_file' => basename($archiveFile)
    ];
    array_unshift($ledger, $ledgerEntry);
    $ledger = array_slice($ledger, 0, 100);
    @file_put_contents($ledgerFile, json_encode($ledger, JSON_PRETTY_PRINT));

    // Also write an audit_logs entry if database is accessible
    try {
        if (function_exists('getDbConnection')) {
            $pdo = getDbConnection();
            $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                VALUES (NULL, 'WELCOME_EMAIL_DISPATCHED', 'users', NULL, ?, ?, NOW())
            ")->execute([
                "Welcome email dispatched to '{$toEmail}' for user '{$username}' ({$roleName}). Status: " . ($mailSent ? 'Sent' : 'Archived Locally') . " [Driver: {$driver}]",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        }
    } catch (Throwable $e) {
        // Non-blocking
    }

    return [
        'success'      => true,
        'sent'         => $mailSent,
        'message'      => $deliveryMessage,
        'archive_file' => $archiveFile
    ];
}

/**
 * Sends a password reset link email to a user.
 *
 * @param array $params [
 *    'to_email'   => string (required),
 *    'username'   => string (required),
 *    'reset_url'  => string (required),
 *    'expires_in' => string (optional, default '1 hour')
 * ]
 * @return array ['success' => bool, 'sent' => bool, 'message' => string, 'archive_file' => string|null]
 */
function sendPasswordResetEmail(array $params): array {
    $config = getMailConfig();

    $toEmail = trim($params['to_email'] ?? '');
    $username = trim($params['username'] ?? '');
    $resetUrl = trim($params['reset_url'] ?? '');
    $expiresIn = trim($params['expires_in'] ?? '1 hour');

    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'sent'    => false,
            'message' => 'Invalid recipient email address.',
            'archive_file' => null
        ];
    }

    if (empty($resetUrl)) {
        return [
            'success' => false,
            'sent'    => false,
            'message' => 'Missing password reset URL.',
            'archive_file' => null
        ];
    }

    $subject = "WesTec BMS – Password Reset Request";
    $fromAddress = $config['from']['address'] ?? 'noreply@westeceswatini.co.sz';
    $fromName    = $config['from']['name'] ?? 'WesTec Eswatini BMS';
    $replyTo     = $config['reply_to']['address'] ?? 'support@westeceswatini.co.sz';

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 30px 10px; }
        .main-card { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
        .header { background: linear-gradient(135deg, #0f2942 0%, #1e3a5f 100%); padding: 32px 30px; text-align: center; color: #ffffff; }
        .brand-badge { display: inline-block; background-color: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; padding: 6px 14px; font-size: 13px; font-weight: 600; letter-spacing: 0.5px; margin-bottom: 12px; color: #5eead4; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: 0.85; }
        .body-content { padding: 32px 30px; }
        .greeting { font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #0f172a; }
        .intro-text { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
        .btn-container { text-align: center; margin: 30px 0 24px; }
        .btn-reset { display: inline-block; background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%); color: #ffffff !important; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-weight: 600; font-size: 14px; letter-spacing: 0.3px; box-shadow: 0 4px 10px rgba(13,148,136,0.25); }
        .url-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; font-size: 12px; word-break: break-all; color: #0f766e; margin-bottom: 20px; }
        .security-notice { background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; font-size: 12.5px; color: #92400e; line-height: 1.5; margin-bottom: 24px; }
        .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 22px 30px; text-align: center; font-size: 11.5px; color: #94a3b8; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="main-card">
            <div class="header">
                <div class="brand-badge">WESTEC ESWATINI BMS</div>
                <h1>Password Reset Request</h1>
                <p>Enterprise Business Management & IT Operations Platform</p>
            </div>
            <div class="body-content">
                <div class="greeting">Hello {$username},</div>
                <div class="intro-text">
                    We received a request to reset your password for your WesTec Eswatini BMS account. Click the button below to choose a new password:
                </div>

                <div class="btn-container">
                    <a href="{$resetUrl}" class="btn-reset" target="_blank">Reset My Password &rarr;</a>
                </div>

                <p style="font-size: 13px; color: #64748b; margin-bottom: 8px;">
                    Or copy and paste this link into your web browser:
                </p>
                <div class="url-box">{$resetUrl}</div>

                <div class="security-notice">
                    <strong>Note:</strong> This password reset link is valid for <strong>{$expiresIn}</strong> only and can only be used once. If you did not request a password reset, you can safely ignore this email &mdash; your existing password will remain active and secure.
                </div>
            </div>
            <div class="footer">
                &copy; 2026 <strong>WesTec Eswatini (Pty) Ltd</strong> &bull; Business Management System<br>
                Mbabane &bull; Eswatini &bull; Support: <a href="mailto:{$replyTo}" style="color: #0d9488; text-decoration: none;">{$replyTo}</a><br>
                <span style="font-size: 10px; color: #cbd5e1;">This is an automated notification. Please do not reply directly to this email.</span>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

    $driver = strtolower($config['driver'] ?? 'smtp');
    $mailSent = false;
    $deliveryMessage = '';

    if ($driver === 'smtp') {
        $smtpResult = sendViaSmtp($toEmail, $subject, $htmlBody, $config);
        $mailSent = $smtpResult['sent'];
        $deliveryMessage = $smtpResult['message'];
    } elseif ($driver === 'mail') {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $replyTo,
            'X-Mailer: WesTec-BMS-Mailer/1.0'
        ];
        $mailSent = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));
        $deliveryMessage = $mailSent ? 'Dispatched via PHP mail()' : 'PHP mail() returned false (local sendmail not configured)';
    } else {
        $mailSent = true;
        $deliveryMessage = 'Archived locally in storage/logs/ (log driver active)';
    }

    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $archiveFile = $logDir . '/email_reset_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $username) . '.html';
    @file_put_contents($archiveFile, $htmlBody);

    $ledgerFile = $logDir . '/outbound_emails.json';
    $ledger = [];
    if (file_exists($ledgerFile)) {
        $content = @file_get_contents($ledgerFile);
        $ledger = json_decode($content, true) ?: [];
    }

    $ledgerEntry = [
        'timestamp'    => date('Y-m-d H:i:s'),
        'recipient'    => $toEmail,
        'username'     => $username,
        'role'         => 'Password Reset',
        'subject'      => $subject,
        'driver'       => $driver,
        'dispatched'   => $mailSent,
        'delivery_msg' => $deliveryMessage,
        'created_by'   => 'Self-Service Password Reset',
        'archive_file' => basename($archiveFile)
    ];
    array_unshift($ledger, $ledgerEntry);
    $ledger = array_slice($ledger, 0, 100);
    @file_put_contents($ledgerFile, json_encode($ledger, JSON_PRETTY_PRINT));

    try {
        if (function_exists('getDbConnection')) {
            $pdo = getDbConnection();
            $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                VALUES (NULL, 'PASSWORD_RESET_DISPATCHED', 'users', NULL, ?, ?, NOW())
            ")->execute([
                "Password reset email dispatched to '{$toEmail}' for user '{$username}'. Status: " . ($mailSent ? 'Sent' : 'Archived Locally') . " [Driver: {$driver}]",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        }
    } catch (Throwable $e) {
        // Non-blocking
    }

    return [
        'success'      => true,
        'sent'         => $mailSent,
        'message'      => $deliveryMessage,
        'archive_file' => $archiveFile
    ];
}

/**
 * Pure PHP SMTP Client implementation over stream sockets.
 * Supports TLS (587), SSL (465), and Plain (25) with AUTH LOGIN.
 */
function sendViaSmtp(string $toEmail, string $subject, string $htmlBody, array $config): array {
    $smtp = $config['smtp'] ?? [];
    $host = $smtp['host'] ?? 'localhost';
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtolower($smtp['encryption'] ?? 'tls');
    $username = $smtp['username'] ?? '';
    $password = $smtp['password'] ?? '';
    $timeout = (int)($smtp['timeout'] ?? 15);

    $fromAddress = $config['from']['address'] ?? 'noreply@westeceswatini.co.sz';
    $fromName = $config['from']['name'] ?? 'WesTec Eswatini BMS';
    $replyTo = $config['reply_to']['address'] ?? 'support@westeceswatini.co.sz';

    // If credentials are placeholder defaults, don't attempt live connection to avoid delay
    if ($username === 'your-email@gmail.com' || $password === 'your-app-password') {
        return [
            'sent'    => false,
            'message' => 'SMTP credentials in config/mail.php are set to defaults. Email was archived locally.'
        ];
    }

    $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
    $socket = @stream_socket_client(
        $prefix . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [
            'sent'    => false,
            'message' => "Could not connect to SMTP server {$host}:{$port} ($errstr). Email archived locally."
        ];
    }

    stream_set_timeout($socket, $timeout);

    $readResponse = function() use ($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };

    $sendCommand = function(string $cmd) use ($socket, $readResponse) {
        fputs($socket, $cmd . "\r\n");
        return $readResponse();
    };

    // 1. Initial greeting
    $greeting = $readResponse();
    if (!str_starts_with($greeting, '220')) {
        fclose($socket);
        return ['sent' => false, 'message' => "SMTP greeting error: {$greeting}"];
    }

    // 2. EHLO
    $ehlo = $sendCommand("EHLO " . gethostname());

    // 3. STARTTLS if required
    if ($encryption === 'tls') {
        $starttls = $sendCommand("STARTTLS");
        if (str_starts_with($starttls, '220')) {
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                fclose($socket);
                return ['sent' => false, 'message' => 'TLS handshake failed with SMTP server.'];
            }
            $ehlo = $sendCommand("EHLO " . gethostname());
        }
    }

    // 4. Authentication
    if (!empty($username) && !empty($password)) {
        $authResp = $sendCommand("AUTH LOGIN");
        if (!str_starts_with($authResp, '334')) {
            fclose($socket);
            return ['sent' => false, 'message' => "AUTH LOGIN rejected: {$authResp}"];
        }

        $userResp = $sendCommand(base64_encode($username));
        if (!str_starts_with($userResp, '334')) {
            fclose($socket);
            return ['sent' => false, 'message' => "SMTP username rejected: {$userResp}"];
        }

        $passResp = $sendCommand(base64_encode($password));
        if (!str_starts_with($passResp, '235')) {
            fclose($socket);
            return ['sent' => false, 'message' => "SMTP authentication failed: {$passResp}"];
        }
    }

    // 5. Envelope
    $mailFromResp = $sendCommand("MAIL FROM: <{$fromAddress}>");
    if (!str_starts_with($mailFromResp, '250')) {
        fclose($socket);
        return ['sent' => false, 'message' => "MAIL FROM error: {$mailFromResp}"];
    }

    $rcptToResp = $sendCommand("RCPT TO: <{$toEmail}>");
    if (!str_starts_with($rcptToResp, '250')) {
        fclose($socket);
        return ['sent' => false, 'message' => "RCPT TO error: {$rcptToResp}"];
    }

    // 6. Data
    $dataResp = $sendCommand("DATA");
    if (!str_starts_with($dataResp, '354')) {
        fclose($socket);
        return ['sent' => false, 'message' => "DATA rejected: {$dataResp}"];
    }

    // Construct raw email with standard RFC 2822 formatting
    $messageId = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(8)), gethostname());
    $emailData  = "Date: " . date('r') . "\r\n";
    $emailData .= "To: <{$toEmail}>\r\n";
    $emailData .= "From: \"{$fromName}\" <{$fromAddress}>\r\n";
    $emailData .= "Reply-To: <{$replyTo}>\r\n";
    $emailData .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $emailData .= "Message-ID: {$messageId}\r\n";
    $emailData .= "X-Mailer: WesTec-BMS-SMTP/1.0\r\n";
    $emailData .= "MIME-Version: 1.0\r\n";
    $emailData .= "Content-Type: text/html; charset=UTF-8\r\n";
    $emailData .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $emailData .= chunk_split(base64_encode($htmlBody)) . "\r\n.";

    $finalResp = $sendCommand($emailData);
    $sendCommand("QUIT");
    fclose($socket);

    if (str_starts_with($finalResp, '250')) {
        return ['sent' => true, 'message' => 'Email sent successfully via SMTP server.'];
    }

    return ['sent' => false, 'message' => "SMTP delivery error: {$finalResp}"];
}
