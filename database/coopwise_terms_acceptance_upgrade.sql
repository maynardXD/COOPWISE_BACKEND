-- This upgrade adds first-login terms acceptance records.
USE coopwise_db;

SET @terms_required_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'terms_required'
);
SET @terms_required_upgrade = IF(
    @terms_required_exists = 0,
    'ALTER TABLE users ADD COLUMN terms_required TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);
PREPARE coopwise_terms_required_statement FROM @terms_required_upgrade;
EXECUTE coopwise_terms_required_statement;
DEALLOCATE PREPARE coopwise_terms_required_statement;

SET @terms_accepted_at_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'terms_accepted_at'
);
SET @terms_accepted_at_upgrade = IF(
    @terms_accepted_at_exists = 0,
    'ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL AFTER terms_required',
    'SELECT 1'
);
PREPARE coopwise_terms_accepted_at_statement FROM @terms_accepted_at_upgrade;
EXECUTE coopwise_terms_accepted_at_statement;
DEALLOCATE PREPARE coopwise_terms_accepted_at_statement;

SET @terms_version_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'terms_version'
);
SET @terms_version_upgrade = IF(
    @terms_version_exists = 0,
    'ALTER TABLE users ADD COLUMN terms_version VARCHAR(32) NULL AFTER terms_accepted_at',
    'SELECT 1'
);
PREPARE coopwise_terms_version_statement FROM @terms_version_upgrade;
EXECUTE coopwise_terms_version_statement;
DEALLOCATE PREPARE coopwise_terms_version_statement;

SET @terms_required_default = (
    SELECT column_default FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'terms_required'
);
SET @terms_required_default_upgrade = IF(
    @terms_required_default = '0',
    'ALTER TABLE users MODIFY COLUMN terms_required TINYINT UNSIGNED NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE coopwise_terms_required_default_statement FROM @terms_required_default_upgrade;
EXECUTE coopwise_terms_required_default_statement;
DEALLOCATE PREPARE coopwise_terms_required_default_statement;
