USE coopwise_db;

-- Apply after coopwise_policies_upgrade.sql and the existing loan/share-capital upgrades.
-- Adds structured demo loan rules, a separate lending pool, repayment splits,
-- and draft/approved member-return records.

SET @finance_columns = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cooperative_policies'
      AND column_name = 'minimum_membership_days'
);
SET @finance_upgrade = IF(@finance_columns = 0,
    'ALTER TABLE cooperative_policies
       ADD COLUMN minimum_membership_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
       ADD COLUMN minimum_paid_up_share_capital DECIMAL(12,2) NOT NULL DEFAULT 5000.00,
       ADD COLUMN minimum_savings_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
       ADD COLUMN loan_to_paid_up_share_capital_multiplier DECIMAL(6,2) NOT NULL DEFAULT 1.00,
       ADD COLUMN cooperative_registration_date DATE NULL',
    'SELECT 1');
PREPARE coopwise_finance_policy_columns FROM @finance_upgrade;
EXECUTE coopwise_finance_policy_columns;
DEALLOCATE PREPARE coopwise_finance_policy_columns;

SET @finance_columns = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_payments'
      AND column_name = 'principal_paid'
);
SET @finance_upgrade = IF(@finance_columns = 0,
    'ALTER TABLE loan_payments
       ADD COLUMN principal_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount_paid,
       ADD COLUMN interest_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER principal_paid,
       ADD COLUMN breakdown_recorded TINYINT(1) NOT NULL DEFAULT 0 AFTER interest_paid',
    'SELECT 1');
PREPARE coopwise_finance_payment_columns FROM @finance_upgrade;
EXECUTE coopwise_finance_payment_columns;
DEALLOCATE PREPARE coopwise_finance_payment_columns;

SET @finance_columns = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_schedules'
      AND column_name = 'principal_paid'
);
SET @finance_upgrade = IF(@finance_columns = 0,
    'ALTER TABLE loan_schedules
       ADD COLUMN principal_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER paid_amount,
       ADD COLUMN interest_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER principal_paid',
    'SELECT 1');
PREPARE coopwise_finance_schedule_columns FROM @finance_upgrade;
EXECUTE coopwise_finance_schedule_columns;
DEALLOCATE PREPARE coopwise_finance_schedule_columns;

UPDATE loan_schedules
SET interest_paid = LEAST(paid_amount, interest_due),
    principal_paid = LEAST(principal_due, GREATEST(paid_amount - LEAST(paid_amount, interest_due), 0))
WHERE paid_amount > 0 AND principal_paid = 0 AND interest_paid = 0;

CREATE TABLE IF NOT EXISTS cooperative_loan_fund (
    id TINYINT UNSIGNED NOT NULL,
    available_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    updated_by_user_id INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_cooperative_loan_fund_updater FOREIGN KEY (updated_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cooperative_loan_fund (id, available_balance) VALUES (1, 0.00);

CREATE TABLE IF NOT EXISTS cooperative_loan_fund_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_type ENUM('funding','loan_disbursement','loan_repayment') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_no VARCHAR(100) NOT NULL,
    loan_id INT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cooperative_loan_fund_reference (reference_no),
    KEY idx_cooperative_loan_fund_created (created_at, id),
    CONSTRAINT fk_cooperative_loan_fund_transaction_loan FOREIGN KEY (loan_id)
        REFERENCES loans(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_cooperative_loan_fund_transaction_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooperative_surplus_distributions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fiscal_year SMALLINT UNSIGNED NOT NULL,
    net_surplus DECIMAL(12,2) NOT NULL,
    reserve_allocation DECIMAL(12,2) NOT NULL,
    education_allocation DECIMAL(12,2) NOT NULL,
    community_allocation DECIMAL(12,2) NOT NULL,
    optional_allocation DECIMAL(12,2) NOT NULL,
    share_capital_pool DECIMAL(12,2) NOT NULL,
    patronage_pool DECIMAL(12,2) NOT NULL,
    status ENUM('draft','approved','cancelled') NOT NULL DEFAULT 'draft',
    created_by_user_id INT UNSIGNED NOT NULL,
    approved_by_user_id INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_surplus_distribution_year_status (fiscal_year, status),
    CONSTRAINT fk_surplus_distribution_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_surplus_distribution_approver FOREIGN KEY (approved_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooperative_member_returns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    distribution_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    paid_up_share_capital DECIMAL(12,2) NOT NULL,
    patronage_basis DECIMAL(12,2) NOT NULL,
    interest_on_share_capital DECIMAL(12,2) NOT NULL,
    patronage_refund DECIMAL(12,2) NOT NULL,
    total_return DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_surplus_member_return (distribution_id, member_id),
    KEY idx_member_return_member_created (member_id, created_at, id),
    CONSTRAINT fk_member_return_distribution FOREIGN KEY (distribution_id)
        REFERENCES cooperative_surplus_distributions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_member_return_member FOREIGN KEY (member_id)
        REFERENCES members(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
