-- Phase 2: support, chat, notification templates, 2FA, tokens, fees

ALTER TABLE users
    ADD COLUMN twofa_secret VARCHAR(64) NULL AFTER password_changed_at,
    ADD COLUMN twofa_enabled_at DATETIME NULL AFTER twofa_secret,
    ADD COLUMN twofa_recovery_codes TEXT NULL AFTER twofa_enabled_at;

CREATE TABLE IF NOT EXISTS user_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    purpose     ENUM('password_reset','email_verify') NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tokens_hash (token_hash),
    KEY idx_tokens_user (user_id, purpose),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_templates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event       VARCHAR(60) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    subject     VARCHAR(190) NOT NULL,
    body        TEXT NOT NULL,
    send_email  TINYINT(1) NOT NULL DEFAULT 1,
    send_inapp  TINYINT(1) NOT NULL DEFAULT 1,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nt_event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uploaded_by   INT UNSIGNED NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    stored_name   VARCHAR(100) NOT NULL,
    mime          VARCHAR(100) NOT NULL,
    size          INT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_att_stored (stored_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference    VARCHAR(20) NOT NULL,
    customer_id  INT UNSIGNED NOT NULL,
    category     VARCHAR(60) NOT NULL,
    subject      VARCHAR(190) NOT NULL,
    status       ENUM('open','pending','assigned','escalated','resolved','closed') NOT NULL DEFAULT 'open',
    priority     ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    assigned_to  INT UNSIGNED NULL,
    created_by   INT UNSIGNED NOT NULL,
    last_reply_at DATETIME NULL,
    last_reply_by ENUM('customer','staff') NULL,
    due_at       DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tickets_ref (reference),
    KEY idx_tickets_customer (customer_id),
    KEY idx_tickets_status (status, updated_at),
    KEY idx_tickets_assignee (assigned_to, status),
    CONSTRAINT fk_tickets_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id     INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    body          TEXT NOT NULL,
    is_internal   TINYINT(1) NOT NULL DEFAULT 0,
    attachment_id INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sm_ticket (ticket_id, id),
    CONSTRAINT fk_sm_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_sm_att FOREIGN KEY (attachment_id) REFERENCES attachments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_conversations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT UNSIGNED NOT NULL,
    subject       VARCHAR(190) NULL,
    status        ENUM('open','closed') NOT NULL DEFAULT 'open',
    assigned_to   INT UNSIGNED NULL,
    last_message_at DATETIME NULL,
    customer_last_read_id INT UNSIGNED NOT NULL DEFAULT 0,
    staff_last_read_id    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chat_customer (customer_id, status),
    KEY idx_chat_status (status, last_message_at),
    CONSTRAINT fk_chat_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    sender          ENUM('customer','staff') NOT NULL,
    body            TEXT NOT NULL,
    is_internal     TINYINT(1) NOT NULL DEFAULT 0,
    attachment_id   INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cm_conv (conversation_id, id),
    FULLTEXT KEY ft_cm_body (body),
    CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_att FOREIGN KEY (attachment_id) REFERENCES attachments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recurring fee runs; the unique key makes the monthly cron idempotent.
CREATE TABLE IF NOT EXISTS fee_runs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fee_code       VARCHAR(40) NOT NULL,
    account_id     INT UNSIGNED NOT NULL,
    period         CHAR(7) NOT NULL,             -- YYYY-MM
    amount         BIGINT NOT NULL,
    status         ENUM('charged','skipped') NOT NULL,
    note           VARCHAR(255) NULL,
    transaction_id BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fee_run (fee_code, account_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE account_types
    ADD COLUMN monthly_fee BIGINT NOT NULL DEFAULT 0 AFTER monthly_limit;

ALTER TABLE deposit_requests
    MODIFY kind ENUM('deposit','adjustment_credit','adjustment_debit','fee') NOT NULL DEFAULT 'deposit';
