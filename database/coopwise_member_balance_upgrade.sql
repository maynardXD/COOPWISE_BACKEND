-- Back up the database before running this migration.
-- Adds previously confirmed Paluwagan payouts to each recipient's demo balance.

DROP TEMPORARY TABLE IF EXISTS coopwise_legacy_paluwagan_credits;
CREATE TEMPORARY TABLE coopwise_legacy_paluwagan_credits (
    payout_id INT UNSIGNED NOT NULL PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_no VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

START TRANSACTION;

INSERT INTO coopwise_legacy_paluwagan_credits
    (payout_id, member_id, amount, balance_after, reference_no)
SELECT eligible.id,
       eligible.member_id,
       eligible.amount,
       COALESCE(balances.current_balance, 0)
         + SUM(eligible.amount) OVER (
             PARTITION BY eligible.member_id
             ORDER BY eligible.id
             ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
           ),
       CONCAT('CW-PALUWAGAN-', UPPER(eligible.reference_no))
FROM cooperative_payouts AS eligible
LEFT JOIN (
    SELECT member_id,
           COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) AS current_balance
    FROM savings_transactions
    GROUP BY member_id
) AS balances ON balances.member_id = eligible.member_id
LEFT JOIN savings_transactions AS existing_credit
    ON existing_credit.member_id = eligible.member_id
   AND existing_credit.type = 'deposit'
   AND existing_credit.reference_no = CONCAT('CW-PALUWAGAN-', UPPER(eligible.reference_no))
WHERE eligible.status = 'paid'
  AND existing_credit.id IS NULL;

INSERT INTO savings_transactions
    (member_id, type, amount, balance_after, reference_no, description, created_by_user_id)
SELECT member_id,
       'deposit',
       amount,
       balance_after,
       reference_no,
       'Previously confirmed Paluwagan payout credited to available member balance',
       NULL
FROM coopwise_legacy_paluwagan_credits
ORDER BY member_id, payout_id;

COMMIT;

DROP TEMPORARY TABLE coopwise_legacy_paluwagan_credits;
