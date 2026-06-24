<?php
/**
 * seed_users.php
 * Run once via CLI or browser to seed the default admin user.
 *
 * CLI:  php seed_users.php
 *
 * Default credentials created:
 *   Email:    admin@example.com
 *   Password: admin123
 *
 * Change $users below before running if you want different accounts.
 */

require_once __DIR__ . '/db_config.php';

$users = [
    [
        'name'     => 'Admin',
        'email'    => 'admin@example.com',
        'password' => 'admin123',   // <-- change this!
        'role'     => 'admin',
    ],
];

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

// ── Add `role` column if not present ─────────────────────────────────────────
try {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `role` VARCHAR(50)
                COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user'
                AFTER `remember_token`");
    echo "Added 'role' column.\n";
} catch (PDOException $e) {
    // Duplicate column — ignore
    if (strpos($e->getMessage(), 'Duplicate column') === false &&
        strpos($e->getMessage(), '1060') === false) {
        throw $e;
    }
    echo "Column 'role' already exists — skipped.\n";
}

// ── Seed users ────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'INSERT INTO `users` (`name`, `email`, `email_verified_at`, `password`, `role`, `created_at`, `updated_at`)
     VALUES (:name, :email, NOW(), :password, :role, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
         `name`       = VALUES(`name`),
         `password`   = VALUES(`password`),
         `role`       = VALUES(`role`),
         `updated_at` = NOW()'
);

foreach ($users as $u) {
    $hash = password_hash($u['password'], PASSWORD_BCRYPT);
    $stmt->execute([
        ':name'     => $u['name'],
        ':email'    => $u['email'],
        ':password' => $hash,
        ':role'     => $u['role'],
    ]);
    echo "Upserted user: {$u['email']} (role: {$u['role']})\n";
}

echo "\nDone. You can now log in with:\n";
foreach ($users as $u) {
    echo "  Email: {$u['email']}   Password: {$u['password']}\n";
}
echo "\nDelete this file after seeding for security.\n";
