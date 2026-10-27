<?php
/**
 * WesTec Eswatini Business Management System
 * Database Installation & Verification Runner
 * 
 * Executes phase3_schema.sql, phase4_additional_triggers.sql, and phase3_seed_data.sql.
 * Can be run from CLI: php sql/setup_database.php
 * Or accessed via browser during initial deployment.
 */

// Database connection parameters
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'westec_bms';

echo "=== WesTec BMS Database Setup Script ===\n";

try {
    // 1. Connect to MySQL server without database
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "[✓] Connected to MySQL server successfully.\n";

    // 2. Ensure database exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `{$dbname}`;");
    echo "[✓] Database `{$dbname}` selected.\n";

    // Helper function to execute SQL files containing DELIMITER blocks
    function executeSqlFile(PDO $pdo, string $filePath): void {
        if (!file_exists($filePath)) {
            throw new Exception("SQL file not found: {$filePath}");
        }

        $sqlContent = file_get_contents($filePath);
        
        // Handle DELIMITER blocks by parsing blocks separated by custom delimiters
        // Standard queries are separated by ; while DELIMITER $$ blocks end with $$
        $delimiter = ';';
        $lines = explode("\n", $sqlContent);
        $buffer = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Check for DELIMITER change
            if (stripos($trimmed, 'DELIMITER ') === 0) {
                // If there was any pending query, execute it
                if (!empty(trim($buffer))) {
                    $pdo->exec($buffer);
                    $buffer = '';
                }
                $delimiter = trim(substr($trimmed, 10));
                continue;
            }

            // Skip comments if line starts with -- or /*
            if (empty($trimmed) || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') && str_ends_with($trimmed, '*/')) {
                continue;
            }

            $buffer .= $line . "\n";

            // Check if buffer ends with current delimiter
            if ($delimiter !== ';' && str_ends_with(rtrim($buffer), $delimiter)) {
                $query = substr(rtrim($buffer), 0, -strlen($delimiter));
                if (!empty(trim($query))) {
                    $pdo->exec($query);
                }
                $buffer = '';
            } elseif ($delimiter === ';' && str_ends_with(rtrim($buffer), ';')) {
                if (!empty(trim($buffer))) {
                    $pdo->exec($buffer);
                }
                $buffer = '';
            }
        }

        if (!empty(trim($buffer))) {
            $pdo->exec($buffer);
        }
    }

    echo "[*] Importing schema and triggers from sql/phase3_schema.sql...\n";
    executeSqlFile($pdo, __DIR__ . '/phase3_schema.sql');
    echo "[✓] Schema created successfully.\n";

    echo "[*] Importing additional triggers from sql/phase4_additional_triggers.sql...\n";
    executeSqlFile($pdo, __DIR__ . '/phase4_additional_triggers.sql');
    echo "[✓] Additional triggers applied.\n";

    echo "[*] Seeding initial demonstration data from sql/phase3_seed_data.sql...\n";
    executeSqlFile($pdo, __DIR__ . '/phase3_seed_data.sql');
    echo "[✓] Seed data imported.\n";

    // 3. Verification of tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "\n=== Database Verification ===\n";
    echo "Found " . count($tables) . " tables in `{$dbname}`:\n";
    foreach ($tables as $tbl) {
        $count = $pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        echo "  - {$tbl}: {$count} records\n";
    }

    // 4. Verification of triggers
    $triggers = $pdo->query("SHOW TRIGGERS")->fetchAll();
    echo "\nFound " . count($triggers) . " triggers in `{$dbname}`:\n";
    foreach ($triggers as $trg) {
        echo "  - {$trg['Trigger']} on `{$trg['Table']}` ({$trg['Timing']} {$trg['Event']})\n";
    }

    echo "\n[✓] DATABASE SETUP COMPLETED SUCCESSFULLY!\n";
    echo "Default Admin: admin / Admin@12345\n";

} catch (Exception $e) {
    echo "\n[!] ERROR during database setup: " . $e->getMessage() . "\n";
    exit(1);
}
