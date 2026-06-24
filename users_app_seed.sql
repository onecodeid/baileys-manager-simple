-- =============================================================================
-- users_app_seed.sql
-- Run this to ensure the `users` table has at least one working login.
--
-- Default credentials:
--   Email:    admin@example.com
--   Password: admin123
--
-- The password hash below is:  password_hash('admin123', PASSWORD_BCRYPT)
-- =============================================================================

USE `baileys_manager`;

-- Add role column if not already present (idempotent)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user'
    AFTER `remember_token`;

-- Upsert the default admin user
INSERT INTO `users` (`name`, `email`, `email_verified_at`, `password`, `role`, `created_at`, `updated_at`)
VALUES (
    'Admin',
    'admin@example.com',
    NOW(),
    '$2y$10$hashed_placeholder_replace_me',   -- Replace with real hash (see note below)
    'admin',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    `name`     = VALUES(`name`),
    `role`     = VALUES(`role`),
    `updated_at` = NOW();

-- NOTE: The hash above is a placeholder. To generate a real one run:
--   php -r "echo password_hash('admin123', PASSWORD_BCRYPT);"
-- and paste the result in place of the placeholder.

-- =============================================================================
-- Or simply run the PHP seeder:  php seed_users.php
-- =============================================================================
