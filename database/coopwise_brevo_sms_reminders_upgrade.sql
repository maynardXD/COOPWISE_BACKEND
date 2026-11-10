-- Apply once after backing up an existing CoopWise database.
-- Members remain opted out until they explicitly enable payment reminders.

ALTER TABLE members
    ADD COLUMN sms_payment_reminders_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN sms_payment_reminders_consented_at DATETIME NULL,
    ADD COLUMN sms_payment_reminders_revoked_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS sms_payment_reminder_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    reminder_type ENUM('loan_installment','paluwagan_contribution') NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    reference_date DATE NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    provider_message_id VARCHAR(120) NULL,
    provider_status SMALLINT UNSIGNED NULL,
    error_code VARCHAR(40) NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sms_payment_reminder (member_id, reminder_type, reference_id, reference_date),
    KEY idx_sms_payment_reminder_status (status, attempted_at),
    CONSTRAINT fk_sms_payment_reminder_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
