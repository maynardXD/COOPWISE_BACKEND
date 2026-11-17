USE coopwise_db;

CREATE TABLE IF NOT EXISTS payment_reminder_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    reminder_type ENUM('loan_installment','paluwagan_contribution') NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    last_attempt_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    last_error_code VARCHAR(64) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_reminder_item (member_id, reminder_type, source_id, due_date),
    KEY idx_payment_reminder_status_attempt (status, last_attempt_at),
    CONSTRAINT fk_payment_reminder_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
