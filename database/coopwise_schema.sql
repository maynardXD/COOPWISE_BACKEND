CREATE DATABASE IF NOT EXISTS coopwise_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE coopwise_db;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin','officer','member') NOT NULL DEFAULT 'member',
    status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    member_number VARCHAR(20) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    contact_number VARCHAR(30) NULL,
    birth_date DATE NULL,
    gender ENUM('male','female','other') NULL,
    status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_members_member_number (member_number),
    UNIQUE KEY uq_members_user_id (user_id),
    UNIQUE KEY uq_members_contact_number (contact_number),
    KEY idx_members_status (status),
    CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS savings_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    type ENUM('deposit','withdrawal') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_no VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_savings_member (member_id),
    KEY idx_savings_type (type),
    CONSTRAINT fk_savings_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_savings_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    max_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    max_term_months INT UNSIGNED NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loan_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT UNSIGNED NOT NULL,
    loan_type_id INT UNSIGNED NOT NULL,
    loan_code VARCHAR(50) NOT NULL,
    principal_amount DECIMAL(12,2) NOT NULL,
    approved_amount DECIMAL(12,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL,
    term_months INT UNSIGNED NOT NULL,
    purpose VARCHAR(255) NULL,
    status ENUM('pending','approved','active','completed','rejected') NOT NULL DEFAULT 'pending',
    current_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    approved_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loans_code (loan_code),
    KEY idx_loans_member (member_id),
    KEY idx_loans_status (status),
    CONSTRAINT fk_loans_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_loans_type FOREIGN KEY (loan_type_id) REFERENCES loan_types(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_loans_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id INT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    principal_due DECIMAL(12,2) NOT NULL,
    interest_due DECIMAL(12,2) NOT NULL,
    total_due DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','paid','overdue') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_schedules_loan (loan_id),
    KEY idx_schedules_status (status),
    CONSTRAINT fk_schedules_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    paid_by_user_id INT UNSIGNED NULL,
    amount_paid DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash','bank','gcash','check') NOT NULL DEFAULT 'cash',
    reference_no VARCHAR(100) NOT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loan_payments_reference (reference_no),
    KEY idx_payments_loan (loan_id),
    KEY idx_payments_member (member_id),
    CONSTRAINT fk_payments_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payments_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payments_user FOREIGN KEY (paid_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    type ENUM('sms','system','alert') NOT NULL DEFAULT 'system',
    title VARCHAR(120) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user (user_id),
    KEY idx_notifications_type (type),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    module VARCHAR(100) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_module (module),
    KEY idx_activity_user (user_id),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
