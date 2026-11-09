CREATE TABLE IF NOT EXISTS cooperative_policies (
    id TINYINT UNSIGNED NOT NULL,
    membership_policy TEXT NOT NULL,
    share_capital_policy TEXT NOT NULL,
    loan_policy TEXT NOT NULL,
    paluwagan_policy TEXT NOT NULL,
    demo_balance_policy TEXT NOT NULL,
    updated_by_user_id INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_cooperative_policy_updater FOREIGN KEY (updated_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cooperative_policies
    (id, membership_policy, share_capital_policy, loan_policy, paluwagan_policy, demo_balance_policy)
VALUES
    (1,
     'This classroom system demonstrates member participation. Staff should enter the membership requirements and process agreed for this sample cooperative.',
     'Share capital represents member ownership, not spendable savings. Staff should enter this sample cooperative’s approved subscription and contribution rules.',
     'Loan products, rates, limits, and terms shown here are classroom examples configured by staff. Members should review the estimated schedule before applying.',
     'Paluwagan is demonstrated as a rotating contribution cycle. The system tracks contributions and payout order; staff confirmation credits the recipient’s demo balance only.',
     'This is not a bank account. Demo balance credits come from approved loan proceeds and confirmed Paluwagan payouts. Simulated withdrawals update the ledger but do not transfer money.');
