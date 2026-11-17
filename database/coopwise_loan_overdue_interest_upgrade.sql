-- This upgrade adds overdue loan-interest tracking.
USE coopwise_db;

SET @loan_type_overdue_interest_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_types' AND column_name = 'overdue_interest_rate'
);
SET @loan_type_overdue_interest_upgrade = IF(
    @loan_type_overdue_interest_exists = 0,
    'ALTER TABLE loan_types ADD COLUMN overdue_interest_rate DECIMAL(5,2) NOT NULL DEFAULT 6.00 AFTER interest_rate',
    'SELECT 1'
);
PREPARE coopwise_loan_type_overdue_interest_statement FROM @loan_type_overdue_interest_upgrade;
EXECUTE coopwise_loan_type_overdue_interest_statement;
DEALLOCATE PREPARE coopwise_loan_type_overdue_interest_statement;

SET @loan_overdue_interest_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loans' AND column_name = 'overdue_interest_rate'
);
SET @loan_overdue_interest_upgrade = IF(
    @loan_overdue_interest_exists = 0,
    'ALTER TABLE loans ADD COLUMN overdue_interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER interest_rate',
    'SELECT 1'
);
PREPARE coopwise_loan_overdue_interest_statement FROM @loan_overdue_interest_upgrade;
EXECUTE coopwise_loan_overdue_interest_statement;
DEALLOCATE PREPARE coopwise_loan_overdue_interest_statement;

SET @schedule_overdue_interest_accrued_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_schedules' AND column_name = 'overdue_interest_accrued'
);
SET @schedule_overdue_interest_accrued_upgrade = IF(
    @schedule_overdue_interest_accrued_exists = 0,
    'ALTER TABLE loan_schedules ADD COLUMN overdue_interest_accrued DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER interest_paid',
    'SELECT 1'
);
PREPARE coopwise_schedule_overdue_interest_accrued_statement FROM @schedule_overdue_interest_accrued_upgrade;
EXECUTE coopwise_schedule_overdue_interest_accrued_statement;
DEALLOCATE PREPARE coopwise_schedule_overdue_interest_accrued_statement;

SET @schedule_overdue_interest_paid_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_schedules' AND column_name = 'overdue_interest_paid'
);
SET @schedule_overdue_interest_paid_upgrade = IF(
    @schedule_overdue_interest_paid_exists = 0,
    'ALTER TABLE loan_schedules ADD COLUMN overdue_interest_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overdue_interest_accrued',
    'SELECT 1'
);
PREPARE coopwise_schedule_overdue_interest_paid_statement FROM @schedule_overdue_interest_paid_upgrade;
EXECUTE coopwise_schedule_overdue_interest_paid_statement;
DEALLOCATE PREPARE coopwise_schedule_overdue_interest_paid_statement;

SET @schedule_overdue_interest_remainder_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_schedules' AND column_name = 'overdue_interest_remainder_units'
);
SET @schedule_overdue_interest_remainder_upgrade = IF(
    @schedule_overdue_interest_remainder_exists = 0,
    'ALTER TABLE loan_schedules ADD COLUMN overdue_interest_remainder_units INT NOT NULL DEFAULT 0 AFTER overdue_interest_paid',
    'SELECT 1'
);
PREPARE coopwise_schedule_overdue_interest_remainder_statement FROM @schedule_overdue_interest_remainder_upgrade;
EXECUTE coopwise_schedule_overdue_interest_remainder_statement;
DEALLOCATE PREPARE coopwise_schedule_overdue_interest_remainder_statement;

SET @schedule_overdue_interest_remainder_unsigned = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_schedules'
      AND column_name = 'overdue_interest_remainder_units'
      AND column_type LIKE '%unsigned%'
);
SET @schedule_overdue_interest_remainder_signed_upgrade = IF(
    @schedule_overdue_interest_remainder_unsigned > 0,
    'ALTER TABLE loan_schedules MODIFY COLUMN overdue_interest_remainder_units INT NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE coopwise_schedule_overdue_interest_remainder_signed_statement FROM @schedule_overdue_interest_remainder_signed_upgrade;
EXECUTE coopwise_schedule_overdue_interest_remainder_signed_statement;
DEALLOCATE PREPARE coopwise_schedule_overdue_interest_remainder_signed_statement;

SET @schedule_overdue_interest_calculated_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_schedules' AND column_name = 'overdue_interest_calculated_at'
);
SET @schedule_overdue_interest_calculated_upgrade = IF(
    @schedule_overdue_interest_calculated_exists = 0,
    'ALTER TABLE loan_schedules ADD COLUMN overdue_interest_calculated_at DATE NULL AFTER overdue_interest_paid',
    'SELECT 1'
);
PREPARE coopwise_schedule_overdue_interest_calculated_statement FROM @schedule_overdue_interest_calculated_upgrade;
EXECUTE coopwise_schedule_overdue_interest_calculated_statement;
DEALLOCATE PREPARE coopwise_schedule_overdue_interest_calculated_statement;

SET @payment_overdue_interest_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'loan_payments' AND column_name = 'overdue_interest_paid'
);
SET @payment_overdue_interest_upgrade = IF(
    @payment_overdue_interest_exists = 0,
    'ALTER TABLE loan_payments ADD COLUMN overdue_interest_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER interest_paid',
    'SELECT 1'
);
PREPARE coopwise_payment_overdue_interest_statement FROM @payment_overdue_interest_upgrade;
EXECUTE coopwise_payment_overdue_interest_statement;
DEALLOCATE PREPARE coopwise_payment_overdue_interest_statement;
