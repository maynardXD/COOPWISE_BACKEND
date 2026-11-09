ALTER TABLE loan_payments
    MODIFY COLUMN payment_method ENUM('simulation','cash','bank','gcash','check','xendit','paymongo','savings') NOT NULL DEFAULT 'cash';

ALTER TABLE share_capital_transactions
    MODIFY COLUMN payment_method ENUM('simulation','cash','bank','gcash','check','xendit','paymongo','savings') NULL;
