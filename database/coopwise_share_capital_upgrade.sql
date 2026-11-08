USE coopwise_db;

CREATE TABLE IF NOT EXISTS share_capital_subscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    subscription_number VARCHAR(100) NOT NULL,
    subscribed_shares INT UNSIGNED NOT NULL,
    par_value DECIMAL(12,2) NOT NULL,
    subscribed_amount DECIMAL(12,2) NOT NULL,
    subscription_date DATE NOT NULL,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_share_capital_subscription_number (subscription_number),
    KEY idx_share_capital_subscription_member (member_id),
    KEY idx_share_capital_subscription_status (status),
    CONSTRAINT fk_share_capital_subscription_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_share_capital_subscription_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS share_capital_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    subscription_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('payment','reversal') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    transaction_reference VARCHAR(100) NOT NULL,
    payment_method ENUM('simulation','cash','bank','gcash','check') NULL,
    reverses_transaction_id INT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_share_capital_transaction_reference (transaction_reference),
    UNIQUE KEY uq_share_capital_reverses_transaction (reverses_transaction_id),
    KEY idx_share_capital_transaction_member (member_id),
    KEY idx_share_capital_transaction_subscription (subscription_id),
    KEY idx_share_capital_transaction_type (transaction_type),
    CONSTRAINT fk_share_capital_transaction_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_share_capital_transaction_subscription FOREIGN KEY (subscription_id) REFERENCES share_capital_subscriptions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_share_capital_transaction_reversal FOREIGN KEY (reverses_transaction_id) REFERENCES share_capital_transactions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_share_capital_transaction_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @loan_payment_method_type = (
    SELECT column_type
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'loan_payments'
      AND column_name = 'payment_method'
);

SET @loan_payment_method_upgrade = IF(
    @loan_payment_method_type IS NOT NULL
      AND LOCATE('simulation', @loan_payment_method_type) = 0,
    'ALTER TABLE loan_payments MODIFY COLUMN payment_method ENUM(''simulation'',''cash'',''bank'',''gcash'',''check'') NOT NULL DEFAULT ''cash''',
    'SELECT 1'
);
PREPARE coopwise_loan_payment_method_statement FROM @loan_payment_method_upgrade;
EXECUTE coopwise_loan_payment_method_statement;
DEALLOCATE PREPARE coopwise_loan_payment_method_statement;
