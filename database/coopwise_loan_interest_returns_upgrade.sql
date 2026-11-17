USE coopwise_db;

-- Apply after the cooperative-finance and loan-overdue-interest upgrades.
-- Existing loan payments are intentionally not backfilled.

CREATE TABLE IF NOT EXISTS cooperative_loan_interest_distributions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_payment_id INT UNSIGNED NOT NULL,
    interest_amount DECIMAL(12,2) NOT NULL,
    eligible_investor_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('allocated','retained') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loan_interest_distribution_payment (loan_payment_id),
    KEY idx_loan_interest_distribution_status_created (status, created_at),
    CONSTRAINT fk_loan_interest_distribution_payment
        FOREIGN KEY (loan_payment_id) REFERENCES loan_payments(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooperative_loan_interest_returns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    distribution_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    claim_status ENUM('available','paid_to_savings','reinvested') NOT NULL DEFAULT 'available',
    claimed_at DATETIME NULL,
    claim_reference VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loan_interest_return_distribution_member (distribution_id, member_id),
    KEY idx_loan_interest_return_member_status (member_id, claim_status, id),
    CONSTRAINT fk_loan_interest_return_distribution
        FOREIGN KEY (distribution_id) REFERENCES cooperative_loan_interest_distributions(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_loan_interest_return_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
