<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Email & SMTP Configuration Template
 *
 * Copy this file to `config/mail.php` and configure your credentials.
 *
 * Supported Drivers:
 *   - 'smtp' : Connects directly to an SMTP server with authentication (Recommended for live email delivery)
 *   - 'mail' : Uses PHP's native mail() function (Requires local mail server or sendmail configured in php.ini)
 *   - 'log'  : Development/Testing mode (Saves full HTML emails to storage/logs/ without network transmission)
 */

return [
    /**
     * Active Email Driver
     * Options: 'smtp', 'mail', 'log'
     */
    'driver' => 'smtp',

    /**
     * SMTP Server Configuration
     * 
     * Common Presets:
     * - Gmail:
     *     host: 'smtp.gmail.com', port: 587, encryption: 'tls'
     *     (Use a 16-character Google "App Password" generated under your Google Account security settings)
     * 
     * - Microsoft 365 / Outlook:
     *     host: 'smtp.office365.com', port: 587, encryption: 'tls'
     * 
     * - cPanel / Custom Domain:
     *     host: 'mail.yourdomain.co.sz', port: 465, encryption: 'ssl' (or port 587 with 'tls')
     * 
     * - Mailtrap / MailHog (Testing):
     *     host: 'sandbox.smtp.mailtrap.io', port: 2525, encryption: 'tls'
     */
    'smtp' => [
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'encryption' => 'tls',             // 'tls', 'ssl', or '' (none)
        'username'   => 'your-email@gmail.com',
        'password'   => 'your-16-char-app-password',
        'timeout'    => 15,
    ],

    /**
     * Default Sender Identity (From Header)
     */
    'from' => [
        'address' => 'noreply@westeceswatini.co.sz',
        'name'    => 'WesTec Eswatini BMS',
    ],

    /**
     * Reply-To / Technical Support Contact
     */
    'reply_to' => [
        'address' => 'support@westeceswatini.co.sz',
        'name'    => 'WesTec Support Desk',
    ],

    /**
     * Archiving & Audit Logging Options
     */
    'logging' => [
        'archive_html' => true,            // Retains rendered copies in storage/logs/
        'audit_trail'  => true,            // Records dispatches in the database audit_logs table
    ],
];

