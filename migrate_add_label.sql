-- =============================================================================
-- migrate_add_label.sql
-- Adds a `label` column to whats_app_sessions for clean label storage.
-- Run once:  SOURCE migrate_add_label.sql
-- =============================================================================

USE `baileys_manager`;

-- Add label column (idempotent)
ALTER TABLE `whats_app_sessions`
    ADD COLUMN IF NOT EXISTS `label` varchar(255)
        COLLATE utf8mb4_unicode_ci DEFAULT NULL
        AFTER `name`;

-- Backfill label from session_data JSON for existing rows (optional)
UPDATE `whats_app_sessions`
   SET `label` = JSON_UNQUOTE(JSON_EXTRACT(`session_data`, '$.label'))
 WHERE `label` IS NULL
   AND `session_data` IS NOT NULL
   AND JSON_EXTRACT(`session_data`, '$.label') IS NOT NULL;
