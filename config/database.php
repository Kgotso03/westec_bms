<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Database Connection & Configuration File
 * 
 * Provides a secure, reusable PDO database connection singleton and
 * a health-check utility for connection verification.
 */

// Define database credentials
defined('DB_HOST') or define('DB_HOST', 'localhost');
defined('DB_NAME') or define('DB_NAME', 'westec_bms');
defined('DB_USER') or define('DB_USER', 'root');
defined('DB_PASS') or define('DB_PASS', '');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// System brand constants
defined('APP_NAME') or define('APP_NAME', 'WesTec Eswatini BMS');
defined('APP_VERSION') or define('APP_VERSION', 'Phase 4.0');

/**
 * Returns a singleton PDO connection instance.
 * Automatically recovers from stale or closed MySQL connections.
 *
 * @param bool $forceReconnect
 * @return PDO
 * @throws Exception
 */
function getDbConnection(bool $forceReconnect = false): PDO {
    static $pdo = null;

    if ($pdo !== null && !$forceReconnect) {
        try {
            // Light probe to confirm MySQL socket is alive
            $pdo->query("SELECT 1");
            return $pdo;
        } catch (\Throwable $e) {
            $pdo = null; // Stale connection, re-instantiate below
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // Enforce native prepared statements
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
        PDO::ATTR_TIMEOUT            => 5
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('Database Connection Error: ' . $e->getMessage());
        throw new Exception('A secure connection to the WesTec Eswatini database could not be established. Please ensure the local MySQL service is running.');
    }

    return $pdo;
}

/**
 * Performs a safe health check on the database connection and core tables.
 *
 * @return array ['connected' => bool, 'message' => string, 'table_count' => int, 'version' => string]
 */
function checkDatabaseHealth(): array {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT VERSION() AS db_version");
        $version = $stmt->fetchColumn() ?: 'Unknown';

        $tablesStmt = $pdo->query("SHOW TABLES");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        return [
            'connected'   => true,
            'message'     => 'Database connection active and responding normally.',
            'table_count' => count($tables),
            'version'     => $version,
            'database'    => DB_NAME
        ];
    } catch (Exception $e) {
        return [
            'connected'   => false,
            'message'     => $e->getMessage(),
            'table_count' => 0,
            'version'     => 'Unavailable',
            'database'    => DB_NAME
        ];
    }
}
