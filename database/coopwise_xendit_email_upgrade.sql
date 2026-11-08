CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    purpose ENUM('savings','loan','paluwagan','share_capital') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    provider_fee DECIMAL(12,2) NULL,
    net_amount DECIMAL(12,2) NULL,
    reference_no VARCHAR(100) NOT NULL,
    loan_id INT UNSIGNED NULL,
    cycle_id INT UNSIGNED NULL,
    subscription_id INT UNSIGNED NULL,
    business_date DATE NULL,
    status ENUM('pending','paid','failed','cancelled','needs_review') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(500) NULL,
    provider_checkout_id VARCHAR(100) NULL,
    provider_payment_id VARCHAR(100) NULL,
    checkout_url TEXT NULL,
    paid_at DATETIME NULL,
    applied_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_transaction_reference (reference_no),
    UNIQUE KEY uq_payment_transaction_checkout (provider_checkout_id),
    UNIQUE KEY uq_payment_transaction_provider_payment (provider_payment_id),
    KEY idx_payment_transaction_member (member_id, created_at),
    KEY idx_payment_transaction_status (status, created_at),
    KEY idx_payment_transaction_cycle_date (cycle_id, member_id, business_date, status),
    CONSTRAINT fk_payment_transaction_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payment_transaction_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payment_transaction_cycle FOREIGN KEY (cycle_id) REFERENCES cooperative_cycles(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payment_transaction_subscription FOREIGN KEY (subscription_id) REFERENCES share_capital_subscriptions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_event_id VARCHAR(120) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_webhook_event (provider_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_otps (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_password_reset_user_created (user_id, created_at),
    KEY idx_password_reset_expiry (expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep the old provider value only so historical rows remain valid after switching.
ALTER TABLE loan_payments
    MODIFY COLUMN payment_method ENUM('simulation','cash','bank','gcash','check','xendit','paymongo') NOT NULL DEFAULT 'cash';

ALTER TABLE share_capital_transactions
    MODIFY COLUMN payment_method ENUM('simulation','cash','bank','gcash','check','xendit','paymongo') NULL;
