-- Phase 6: external integrations framework. All providers are disabled by default.

CREATE TABLE IF NOT EXISTS integrations (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider         VARCHAR(40) NOT NULL,
    enabled          TINYINT(1) NOT NULL DEFAULT 0,
    mode             ENUM('test','live') NOT NULL DEFAULT 'test',
    config_encrypted TEXT NULL,                       -- JSON, AES-256-GCM (key derived from app.key)
    last_tested_at   DATETIME NULL,
    last_test_ok     TINYINT(1) NULL,
    last_test_message VARCHAR(255) NULL,
    updated_by       INT UNSIGNED NULL,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_integrations_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_logs (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider         VARCHAR(40) NOT NULL,
    direction        ENUM('out','in') NOT NULL,
    action           VARCHAR(80) NOT NULL,
    http_status      SMALLINT NULL,
    ok               TINYINT(1) NOT NULL,
    duration_ms      INT NULL,
    summary          VARCHAR(1000) NULL,              -- secrets and card data are never logged
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_il_provider (provider, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inbound webhook deduplication: each provider event is processed at most once.
CREATE TABLE IF NOT EXISTS webhook_events (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider     VARCHAR(40) NOT NULL,
    event_id     VARCHAR(120) NOT NULL,
    event_type   VARCHAR(80) NOT NULL,
    status       ENUM('processed','ignored','failed') NOT NULL,
    message      VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_webhook_event (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Card top-ups through an external payment gateway (hosted checkout; card data never touches this server).
CREATE TABLE IF NOT EXISTS gateway_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(32) NOT NULL,
    provider        VARCHAR(40) NOT NULL,
    provider_ref    VARCHAR(190) NULL,                -- e.g. Stripe Checkout Session id
    customer_id     INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    amount          BIGINT NOT NULL,
    currency        CHAR(3) NOT NULL,
    status          ENUM('created','pending','completed','failed','expired','cancelled') NOT NULL DEFAULT 'created',
    failure_reason  VARCHAR(255) NULL,
    transaction_id  BIGINT UNSIGNED NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    UNIQUE KEY uq_gp_reference (reference),
    UNIQUE KEY uq_gp_provider_ref (provider, provider_ref),
    KEY idx_gp_customer (customer_id, created_at),
    KEY idx_gp_status (status, created_at),
    CONSTRAINT fk_gp_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_gp_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE notification_templates
    ADD COLUMN send_sms TINYINT(1) NOT NULL DEFAULT 0 AFTER send_inapp;
