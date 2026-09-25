-- Phase 5: simulated crypto. Units are internal records only. There are no blockchain deposits or withdrawals.

ALTER TABLE transactions
    MODIFY type ENUM('deposit','withdrawal','transfer','fee','refund','adjustment','card','investment','crypto') NOT NULL;

ALTER TABLE customers
    ADD COLUMN crypto_risk_ack_at DATETIME NULL AFTER terms_accepted_at;

CREATE TABLE IF NOT EXISTS crypto_assets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol          VARCHAR(12) NOT NULL,
    name            VARCHAR(80) NOT NULL,
    decimals        TINYINT UNSIGNED NOT NULL DEFAULT 8,   -- quantity precision; quantities are stored in 10^-decimals units
    price           BIGINT NOT NULL,                       -- price of ONE whole unit, in currency minor units
    previous_price  BIGINT NOT NULL,                       -- reference price for the 24h change
    trade_fee_bps   INT NOT NULL DEFAULT 100,
    min_trade       BIGINT NOT NULL DEFAULT 100,           -- minimum trade value (minor units)
    volatility_bps  INT NOT NULL DEFAULT 0,                -- random-walk step for the price simulator (0 = manual prices only)
    status          ENUM('active','halted','inactive') NOT NULL DEFAULT 'active',
    sort_order      INT NOT NULL DEFAULT 0,
    price_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crypto_symbol (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crypto_price_history (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id    INT UNSIGNED NOT NULL,
    price       BIGINT NOT NULL,
    source      ENUM('manual','simulator','initial') NOT NULL,
    set_by      INT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cph_asset (asset_id, recorded_at),
    CONSTRAINT fk_cph_asset FOREIGN KEY (asset_id) REFERENCES crypto_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Current position per customer and asset. Derived from crypto_transactions, which is the source of truth.
CREATE TABLE IF NOT EXISTS crypto_holdings (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id  INT UNSIGNED NOT NULL,
    asset_id     INT UNSIGNED NOT NULL,
    quantity     BIGINT NOT NULL DEFAULT 0,       -- in 10^-decimals units
    cost_basis   BIGINT NOT NULL DEFAULT 0,       -- total cost of the open quantity (minor units, incl. buy fees)
    realized_pnl BIGINT NOT NULL DEFAULT 0,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_holding (customer_id, asset_id),
    CONSTRAINT fk_ch_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_ch_asset FOREIGN KEY (asset_id) REFERENCES crypto_assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crypto_transactions (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference      VARCHAR(32) NOT NULL,
    customer_id    INT UNSIGNED NOT NULL,
    asset_id       INT UNSIGNED NOT NULL,
    account_id     INT UNSIGNED NOT NULL,
    side           ENUM('buy','sell') NOT NULL,
    quantity       BIGINT NOT NULL,
    price          BIGINT NOT NULL,                  -- execution price per whole unit
    gross          BIGINT NOT NULL,                  -- quantity x price (minor units)
    fee            BIGINT NOT NULL,
    net            BIGINT NOT NULL,                  -- buy: gross + fee paid, sell: gross - fee received
    realized_pnl   BIGINT NULL,                      -- sells only
    transaction_id BIGINT UNSIGNED NOT NULL,         -- money ledger transaction
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ctx_ref (reference),
    KEY idx_ctx_customer (customer_id, created_at),
    KEY idx_ctx_asset (asset_id, created_at),
    CONSTRAINT fk_ctx_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_ctx_asset FOREIGN KEY (asset_id) REFERENCES crypto_assets(id),
    CONSTRAINT fk_ctx_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
