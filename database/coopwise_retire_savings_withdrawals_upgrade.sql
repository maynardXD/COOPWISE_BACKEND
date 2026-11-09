-- Back up the database before running this migration.
-- Refunds old pending simulations once and keeps the request rows as history.
-- Run after coopwise_financial_workflows_upgrade.sql on an existing database.

DROP TEMPORARY TABLE IF EXISTS coopwise_legacy_withdrawal_refunds;
CREATE TEMPORARY TABLE coopwise_legacy_withdrawal_refunds (
    request_id INT UNSIGNED NOT NULL PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL
) ENGINE=InnoDB;

START TRANSACTION;

INSERT INTO coopwise_legacy_withdrawal_refunds (request_id, member_id, amount, balance_after)
SELECT pending.id,
       pending.member_id,
       pending.amount,
       balances.current_balance
         + SUM(pending.amount) OVER (
             PARTITION BY pending.member_id
             ORDER BY pending.id
             ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
           )
FROM savings_withdrawal_requests AS pending
INNER JOIN (
    SELECT member_id,
           COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) AS current_balance
    FROM savings_transactions
    GROUP BY member_id
) AS balances ON balances.member_id = pending.member_id
WHERE pending.status = 'pending';

INSERT INTO savings_transactions
    (member_id, type, amount, balance_after, reference_no, description, created_by_user_id)
SELECT refunds.member_id,
       'deposit',
       refunds.amount,
       refunds.balance_after,
       CONCAT('CW-LEGACY-WD-REFUND-', refunds.request_id),
       CONCAT('Refund of retired withdrawal request ', refunds.request_id),
       NULL
FROM coopwise_legacy_withdrawal_refunds AS refunds;

UPDATE savings_transactions AS transaction_row
INNER JOIN savings_withdrawal_requests AS pending
    ON pending.member_id = transaction_row.member_id
   AND pending.savings_reference_no = transaction_row.reference_no
INNER JOIN coopwise_legacy_withdrawal_refunds AS refunds ON refunds.request_id = pending.id
SET transaction_row.description = 'Legacy simulated withdrawal refunded when staff review was retired';

UPDATE savings_withdrawal_requests AS pending
INNER JOIN coopwise_legacy_withdrawal_refunds AS refunds ON refunds.request_id = pending.id
SET pending.status = 'rejected',
    pending.admin_note = 'Automatically refunded when staff-reviewed withdrawals were retired.',
    pending.completed_at = CURRENT_TIMESTAMP;

COMMIT;

DROP TEMPORARY TABLE coopwise_legacy_withdrawal_refunds;
