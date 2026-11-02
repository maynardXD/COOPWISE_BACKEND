USE coopwise_db;

SET @loan_review_note_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'loans'
      AND column_name = 'review_note'
);

SET @loan_review_note_upgrade = IF(
    @loan_review_note_exists = 0,
    'ALTER TABLE loans ADD COLUMN review_note VARCHAR(500) NULL AFTER approved_by_user_id',
    'SELECT 1'
);

PREPARE coopwise_loan_review_note_statement FROM @loan_review_note_upgrade;
EXECUTE coopwise_loan_review_note_statement;
DEALLOCATE PREPARE coopwise_loan_review_note_statement;

CREATE TABLE IF NOT EXISTS loan_readiness_submissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(500) NULL,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loan_readiness_member (member_id),
    KEY idx_loan_readiness_status (status),
    CONSTRAINT fk_loan_readiness_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_loan_readiness_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    readiness_submission_id INT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_name CHAR(69) NOT NULL,
    mime_type ENUM('image/jpeg','image/png','image/webp') NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loan_documents_submission (readiness_submission_id),
    CONSTRAINT fk_loan_documents_submission FOREIGN KEY (readiness_submission_id) REFERENCES loan_readiness_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_loan_documents_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_submissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    loan_id INT UNSIGNED NULL,
    cycle_id INT UNSIGNED NULL,
    category ENUM('cooperative','paluwagan','loan') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference_no VARCHAR(100) NULL,
    storage_name CHAR(69) NULL,
    mime_type ENUM('image/jpeg','image/png','image/webp') NULL,
    file_size INT UNSIGNED NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(500) NULL,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payment_submissions_member (member_id),
    KEY idx_payment_submissions_status (status),
    KEY idx_payment_submissions_category (category),
    CONSTRAINT fk_payment_submissions_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_submissions_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payment_submissions_cycle FOREIGN KEY (cycle_id) REFERENCES cooperative_cycles(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payment_submissions_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
