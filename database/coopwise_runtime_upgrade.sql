-- Apply after backing up an existing CoopWise database.
-- Adds refresh-session storage and durable daily auto-pay run monitoring.

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    jti TEXT NOT NULL,
    PRIMARY KEY (id),
    KEY user_id_idx (user_id),
    KEY idx_refresh_tokens_expiry (expires_at),
    CONSTRAINT fk_refresh_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auto_pay_runs (
    run_id CHAR(32) NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    members_checked INT UNSIGNED NOT NULL DEFAULT 0,
    loan_installments INT UNSIGNED NOT NULL DEFAULT 0,
    paluwagan_contributions INT UNSIGNED NOT NULL DEFAULT 0,
    partial_loan_installments INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_loan_installments INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_paluwagan_contributions INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    details TEXT NULL,
    PRIMARY KEY (run_id),
    KEY idx_auto_pay_runs_started (started_at),
    KEY idx_auto_pay_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_savings_member_created ON savings_transactions (member_id, created_at, id);
CREATE INDEX idx_savings_created ON savings_transactions (created_at, id);
CREATE INDEX idx_share_capital_transaction_member_created
    ON share_capital_transactions (member_id, created_at, id);
CREATE INDEX idx_share_capital_transaction_created ON share_capital_transactions (created_at, id);
CREATE INDEX idx_payments_member_date ON loan_payments (member_id, payment_date, id);
CREATE INDEX idx_payments_date ON loan_payments (payment_date, id);
CREATE INDEX idx_coop_payout_member_date ON cooperative_payouts (member_id, payout_date, id);
CREATE INDEX idx_coop_payout_date ON cooperative_payouts (payout_date, id);
CREATE INDEX idx_coop_contribution_member_date
    ON cooperative_contributions (member_id, contribution_date, id);
CREATE INDEX idx_coop_contribution_date ON cooperative_contributions (contribution_date, id);
CREATE INDEX idx_payment_transaction_created ON payment_transactions (created_at, id);
