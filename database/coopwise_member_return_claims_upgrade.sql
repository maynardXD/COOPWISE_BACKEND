USE coopwise_db;

-- Apply after coopwise_cooperative_finance_upgrade.sql.
-- Adds one-time member choices for approved dividend/member-return allocations.

SET @claim_column = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cooperative_member_returns'
      AND column_name = 'claim_status'
);
SET @claim_upgrade = IF(@claim_column = 0,
    'ALTER TABLE cooperative_member_returns ADD COLUMN claim_status ENUM(''available'',''paid_to_savings'',''reinvested'') NOT NULL DEFAULT ''available'' AFTER total_return',
    'SELECT 1');
PREPARE coopwise_return_claim_status FROM @claim_upgrade;
EXECUTE coopwise_return_claim_status;
DEALLOCATE PREPARE coopwise_return_claim_status;

SET @claim_column = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cooperative_member_returns'
      AND column_name = 'claimed_at'
);
SET @claim_upgrade = IF(@claim_column = 0,
    'ALTER TABLE cooperative_member_returns ADD COLUMN claimed_at DATETIME NULL AFTER claim_status',
    'SELECT 1');
PREPARE coopwise_return_claimed_at FROM @claim_upgrade;
EXECUTE coopwise_return_claimed_at;
DEALLOCATE PREPARE coopwise_return_claimed_at;

SET @claim_column = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cooperative_member_returns'
      AND column_name = 'claim_reference'
);
SET @claim_upgrade = IF(@claim_column = 0,
    'ALTER TABLE cooperative_member_returns ADD COLUMN claim_reference VARCHAR(100) NULL AFTER claimed_at',
    'SELECT 1');
PREPARE coopwise_return_claim_reference FROM @claim_upgrade;
EXECUTE coopwise_return_claim_reference;
DEALLOCATE PREPARE coopwise_return_claim_reference;
