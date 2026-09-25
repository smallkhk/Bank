-- Phase 3: cards (simulated/internal — not connected to any card network or processor)

ALTER TABLE accounts
    ADD COLUMN credit_limit BIGINT NOT NULL DEFAULT 0 AFTER held_amount;

CREATE TABLE IF NOT EXISTS card_products (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(100) NOT NULL,
    card_type             ENUM('debit','credit','prepaid') NOT NULL,
    form_factor           ENUM('virtual','physical') NOT NULL DEFAULT 'virtual',
    bin_prefix            VARCHAR(8) NOT NULL,          -- leading digits of generated card numbers
    daily_limit           BIGINT NOT NULL DEFAULT 0,    -- 0 = no card-level limit
    monthly_limit         BIGINT NOT NULL DEFAULT 0,
    atm_daily_limit       BIGINT NOT NULL DEFAULT 0,
    online_enabled        TINYINT(1) NOT NULL DEFAULT 1,
    atm_enabled           TINYINT(1) NOT NULL DEFAULT 1,
    international_enabled TINYINT(1) NOT NULL DEFAULT 0,
    issuance_fee          BIGINT NOT NULL DEFAULT 0,
    replacement_fee       BIGINT NOT NULL DEFAULT 0,
    international_fee_bps INT NOT NULL DEFAULT 0,
    expiry_months         INT NOT NULL DEFAULT 36,
    -- credit products
    credit_limit          BIGINT NOT NULL DEFAULT 0,
    interest_apr_bps      INT NOT NULL DEFAULT 0,
    min_payment_bps       INT NOT NULL DEFAULT 500,     -- 5% of the statement balance
    min_payment_floor     BIGINT NOT NULL DEFAULT 2500,
    statement_day         TINYINT NOT NULL DEFAULT 1,
    grace_days            TINYINT NOT NULL DEFAULT 21,
    late_fee              BIGINT NOT NULL DEFAULT 0,
    customer_requestable  TINYINT(1) NOT NULL DEFAULT 1,
    status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cards (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id            INT UNSIGNED NOT NULL,
    customer_id           INT UNSIGNED NOT NULL,
    account_id            INT UNSIGNED NULL,            -- funding account (debit/prepaid) or credit account
    cardholder_name       VARCHAR(100) NOT NULL,
    pan_encrypted         TEXT NULL,                    -- AES-256-GCM, key derived from app.key
    pan_hash              CHAR(64) NULL,                -- HMAC for uniqueness checks
    pan_last4             CHAR(4) NULL,
    expiry_month          TINYINT NULL,
    expiry_year           SMALLINT NULL,
    cvv_encrypted         TEXT NULL,                    -- AES-256-GCM; never stored in plaintext
    status                ENUM('pending','active','frozen','blocked','expired','cancelled','rejected') NOT NULL DEFAULT 'pending',
    status_reason         VARCHAR(255) NULL,
    online_enabled        TINYINT(1) NOT NULL DEFAULT 1,
    atm_enabled           TINYINT(1) NOT NULL DEFAULT 1,
    international_enabled TINYINT(1) NOT NULL DEFAULT 0,
    daily_limit           BIGINT NULL,                  -- customer/staff override (<= product limit)
    replaces_card_id      INT UNSIGNED NULL,
    requested_by          INT UNSIGNED NULL,
    issued_by             INT UNSIGNED NULL,
    issued_at             DATETIME NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cards_pan (pan_hash),
    KEY idx_cards_customer (customer_id, status),
    KEY idx_cards_status (status),
    CONSTRAINT fk_cards_product FOREIGN KEY (product_id) REFERENCES card_products(id),
    CONSTRAINT fk_cards_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_cards_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS card_transactions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id         INT UNSIGNED NOT NULL,
    transaction_id  BIGINT UNSIGNED NULL,               -- ledger transaction (approved only)
    merchant_name   VARCHAR(120) NOT NULL,
    merchant_category VARCHAR(60) NULL,
    channel         ENUM('pos','online','atm') NOT NULL,
    country         CHAR(2) NOT NULL,
    amount          BIGINT NOT NULL,
    fee_amount      BIGINT NOT NULL DEFAULT 0,
    currency        CHAR(3) NOT NULL,
    status          ENUM('approved','declined','reversed') NOT NULL,
    decline_reason  VARCHAR(120) NULL,
    reversal_transaction_id BIGINT UNSIGNED NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ct_card (card_id, created_at),
    KEY idx_ct_status (status, created_at),
    CONSTRAINT fk_ct_card FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_statements (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL,
    card_id          INT UNSIGNED NULL,
    period_start     DATE NOT NULL,
    period_end       DATE NOT NULL,
    statement_balance BIGINT NOT NULL,                 -- amount owed at statement date (positive)
    minimum_payment  BIGINT NOT NULL,
    due_date         DATE NOT NULL,
    interest_charged BIGINT NOT NULL DEFAULT 0,
    late_fee_charged BIGINT NOT NULL DEFAULT 0,
    last_entry_id    BIGINT UNSIGNED NOT NULL DEFAULT 0, -- ledger position at issue; later credits are payments
    status           ENUM('open','paid','minimum_paid','overdue') NOT NULL DEFAULT 'open',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cs_period (account_id, period_end),
    KEY idx_cs_due (status, due_date),
    CONSTRAINT fk_cs_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
