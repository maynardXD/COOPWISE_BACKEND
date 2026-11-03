USE coopwise_db;

SET @loan_autopay_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'members'
      AND column_name = 'loan_autopay_enabled'
);

SET @loan_autopay_upgrade = IF(
    @loan_autopay_exists = 0,
    'ALTER TABLE members ADD COLUMN loan_autopay_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE coopwise_loan_autopay_statement FROM @loan_autopay_upgrade;
EXECUTE coopwise_loan_autopay_statement;
DEALLOCATE PREPARE coopwise_loan_autopay_statement;

SET @paluwagan_autopay_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'members'
      AND column_name = 'paluwagan_autopay_enabled'
);

SET @paluwagan_autopay_upgrade = IF(
    @paluwagan_autopay_exists = 0,
    'ALTER TABLE members ADD COLUMN paluwagan_autopay_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE coopwise_paluwagan_autopay_statement FROM @paluwagan_autopay_upgrade;
EXECUTE coopwise_paluwagan_autopay_statement;
DEALLOCATE PREPARE coopwise_paluwagan_autopay_statement;

SET @autopay_consent_updated_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'members'
      AND column_name = 'autopay_consent_updated_at'
);

SET @autopay_consent_updated_upgrade = IF(
    @autopay_consent_updated_exists = 0,
    'ALTER TABLE members ADD COLUMN autopay_consent_updated_at DATETIME NULL',
    'SELECT 1'
);
PREPARE coopwise_autopay_consent_updated_statement FROM @autopay_consent_updated_upgrade;
EXECUTE coopwise_autopay_consent_updated_statement;
DEALLOCATE PREPARE coopwise_autopay_consent_updated_statement;

CREATE TABLE IF NOT EXISTS savings_withdrawal_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    request_reference VARCHAR(100) NOT NULL,
    savings_reference_no VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    gcash_account_name VARCHAR(120) NOT NULL,
    gcash_number VARCHAR(20) NOT NULL,
    status ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
    receipt_storage_name CHAR(69) NULL,
    receipt_mime_type ENUM('image/jpeg','image/png','image/webp') NULL,
    receipt_file_size INT UNSIGNED NULL,
    admin_note VARCHAR(500) NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_by_user_id INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_savings_withdrawal_reference (request_reference),
    UNIQUE KEY uq_savings_withdrawal_savings_reference (savings_reference_no),
    KEY idx_savings_withdrawal_member_status (member_id, status),
    KEY idx_savings_withdrawal_status_date (status, requested_at),
    CONSTRAINT fk_savings_withdrawal_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_savings_withdrawal_completer FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
