USE coopwise_db;

SET @middle_name_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'members'
      AND column_name = 'middle_name'
);

SET @middle_name_upgrade = IF(
    @middle_name_column_exists = 0,
    'ALTER TABLE members ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name',
    'SELECT 1'
);

PREPARE coopwise_middle_name_statement FROM @middle_name_upgrade;
EXECUTE coopwise_middle_name_statement;
DEALLOCATE PREPARE coopwise_middle_name_statement;

SET @contact_number_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'members'
      AND index_name = 'uq_members_contact_number'
);

SET @contact_number_index_upgrade = IF(
    @contact_number_index_exists = 0,
    'ALTER TABLE members ADD UNIQUE KEY uq_members_contact_number (contact_number)',
    'SELECT 1'
);

PREPARE coopwise_contact_number_index_statement FROM @contact_number_index_upgrade;
EXECUTE coopwise_contact_number_index_statement;
DEALLOCATE PREPARE coopwise_contact_number_index_statement;

SET @username_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'username'
);

SET @username_index_names = (
    SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', index_name, '`') SEPARATOR ', ')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'username'
      AND index_name <> 'PRIMARY'
);

SET @username_upgrade = IF(
    @username_column_exists = 0,
    'SELECT 1',
    IF(
        @username_index_names IS NULL,
        'ALTER TABLE users DROP COLUMN username',
        CONCAT('ALTER TABLE users ', @username_index_names, ', DROP COLUMN username')
    )
);

PREPARE coopwise_username_statement FROM @username_upgrade;
EXECUTE coopwise_username_statement;
DEALLOCATE PREPARE coopwise_username_statement;
