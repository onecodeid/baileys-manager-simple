<?php
/**
 * db_config.php — Database configuration loader
 * Parses the .env file in the root directory and defines DB constants.
 */

function loadEnv(string $dir): array {
    $envPath = $dir . '/.env';
    $vars = [];
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments
            if (strpos($line, '#') === 0 || $line === '') {
                continue;
            }
            // Parse Key=Value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key   = trim($key);
                $value = trim($value);
                // Strip surrounding quotes
                if (preg_match('/^"([^"]*)"$/', $value, $m) || preg_match("/^'([^']*)'$/", $value, $m)) {
                    $value = $m[1];
                }
                $vars[$key] = $value;
            }
        }
    }
    return $vars;
}

$env = loadEnv(__DIR__);

// Define DB Constants with fallback defaults
if (!defined('DB_HOST')) {
    define('DB_HOST', $env['DB_HOST'] ?? '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', isset($env['DB_PORT']) ? (int)$env['DB_PORT'] : 3306);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $env['DB_NAME'] ?? 'baileys_manager');
}
if (!defined('DB_USER')) {
    define('DB_USER', $env['DB_USER'] ?? 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $env['DB_PASS'] ?? '');
}
