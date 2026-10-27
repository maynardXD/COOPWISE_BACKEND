USE coopwise_db;

SET @loan_type_max_amount_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'loan_types'
      AND column_name = 'max_amount'
);

SET @loan_type_max_amount_upgrade = IF(
    @loan_type_max_amount_exists = 0,
    'ALTER TABLE loan_types ADD COLUMN max_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER interest_rate',
    'SELECT 1'
);

PREPARE coopwise_loan_type_max_amount_statement FROM @loan_type_max_amount_upgrade;
EXECUTE coopwise_loan_type_max_amount_statement;
DEALLOCATE PREPARE coopwise_loan_type_max_amount_statement;

ALTER TABLE loan_types ALTER COLUMN max_amount SET DEFAULT 0.00;

CREATE TABLE IF NOT EXISTS cooperative_cycles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    daily_contribution DECIMAL(12,2) NOT NULL,
    start_date DATE NOT NULL,
    status ENUM('draft','active','completed','cancelled') NOT NULL DEFAULT 'draft',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_coop_cycles_status (status),
    CONSTRAINT fk_coop_cycles_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooperative_cycle_members (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cycle_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    payout_order SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coop_cycle_member (cycle_id, member_id),
    UNIQUE KEY uq_coop_cycle_order (cycle_id, payout_order),
    CONSTRAINT fk_coop_cycle_members_cycle FOREIGN KEY (cycle_id) REFERENCES cooperative_cycles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coop_cycle_members_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooperative_contributions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cycle_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    contribution_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference_no VARCHAR(100) NOT NULL,
    recorded_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coop_daily_contribution (cycle_id, member_id, contribution_date),
    UNIQUE KEY uq_coop_contribution_reference (reference_no),
    CONSTRAINT fk_coop_contributions_cycle FOREIGN KEY (cycle_id) REFERENCES cooperative_cycles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coop_contributions_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coop_contributions_recorder FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooperative_payouts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cycle_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    payout_order SMALLINT UNSIGNED NOT NULL,
    payout_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference_no VARCHAR(100) NOT NULL,
    status ENUM('ready','paid') NOT NULL DEFAULT 'ready',
    paid_by_user_id INT UNSIGNED NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coop_cycle_payout_member (cycle_id, member_id),
    UNIQUE KEY uq_coop_cycle_payout_order (cycle_id, payout_order),
    UNIQUE KEY uq_coop_payout_reference (reference_no),
    CONSTRAINT fk_coop_payouts_cycle FOREIGN KEY (cycle_id) REFERENCES cooperative_cycles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coop_payouts_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coop_payouts_recorder FOREIGN KEY (paid_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
