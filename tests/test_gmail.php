<?php
/**
 * WesTec Eswatini BMS — Gmail SMTP Connection & Delivery Test Script
 * 
 * Usage from PowerShell/CLI:
 *   php tests/test_gmail.php destination@example.com
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$config = getMailConfig();

echo "==============================================================================\n";
echo "WESTEC ESWATINI BMS — GMAIL SMTP TEST\n";
echo "==============================================================================\n\n";

$targetEmail = $argv[1] ?? ($config['smtp']['username'] ?? '');

if (empty($targetEmail) || $targetEmail === 'your-email@gmail.com' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
    echo "[!] Please provide a valid destination email to test delivery:\n";
    echo "    Usage: php tests/test_gmail.php your-destination-email@gmail.com\n\n";
    echo "Current settings in config/mail.php:\n";
    echo "  - Host:       " . ($config['smtp']['host'] ?? '') . "\n";
    echo "  - Port:       " . ($config['smtp']['port'] ?? '') . "\n";
    echo "  - Encryption: " . ($config['smtp']['encryption'] ?? '') . "\n";
    echo "  - Username:   " . ($config['smtp']['username'] ?? '') . "\n";
    echo "  - Password:   " . (empty($config['smtp']['password']) || $config['smtp']['password'] === 'your-app-password' ? '[NOT CONFIGURED]' : '******** (Configured)') . "\n\n";
    exit(1);
}

echo "[*] Destination: {$targetEmail}\n";
echo "[*] Connecting to {$config['smtp']['host']}:{$config['smtp']['port']} via {$config['smtp']['encryption']}...\n";

$result = sendNewUserWelcomeEmail([
    'to_email'           => $targetEmail,
    'full_name'          => 'Test User',
    'username'           => 'test_user_' . substr(md5(uniqid()), 0, 4),
    'temporary_password' => 'TestPass@2026',
    'role_name'          => 'Senior Technician',
    'created_by_name'    => 'Administrator (Gmail Test)'
]);

if ($result['sent']) {
    echo "\n[✓] SUCCESS: Email successfully delivered via Gmail SMTP!\n";
    echo "    Check the inbox at {$targetEmail}.\n";
} else {
    echo "\n[!] DELIVERY FAILED OR ARCHIVED LOCALLY:\n";
    echo "    Message: " . $result['message'] . "\n";
    echo "    Archive: " . ($result['archive_file'] ?? 'None') . "\n";
}

echo "\n==============================================================================\n";
