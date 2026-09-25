-- Internet Banking Platform — Phase 1 schema (MySQL 5.7+/MariaDB 10.3+)
-- All monetary values are stored as BIGINT in minor units (e.g. cents).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── Identity & access ────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_type       ENUM('staff','customer') NOT NULL,
    username        VARCHAR(60)  NOT NULL,
    email           VARCHAR(190) NOT NULL,
    phone           VARCHAR(40)  NULL,
    full_name       VARCHAR(150) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    status          ENUM('pending','active','suspended','locked','closed') NOT NULL DEFAULT 'pending',
    status_reason   VARCHAR(255) NULL,
    email_verified_at DATETIME NULL,
    last_login_at   DATETIME NULL,
    last_login_ip   VARCHAR(45) NULL,
    password_changed_at DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_type_status (user_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(60) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_sessions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    ip_address   VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at   DATETIME NULL,
    UNIQUE KEY uq_sessions_token (token_hash),
    KEY idx_sessions_user (user_id, revoked_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_identifier (identifier, created_at),
    KEY idx_la_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Customers ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS customers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    customer_number VARCHAR(20) NOT NULL,
    date_of_birth   DATE NULL,
    address         VARCHAR(255) NULL,
    city            VARCHAR(100) NULL,
    country         VARCHAR(100) NULL,
    profile_photo   VARCHAR(255) NULL,
    id_type         VARCHAR(50) NULL,
    id_number       VARCHAR(80) NULL,
    kyc_status      ENUM('not_submitted','pending','verified','rejected') NOT NULL DEFAULT 'not_submitted',
    terms_accepted_at DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_user (user_id),
    UNIQUE KEY uq_customers_number (customer_number),
    CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_documents (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    doc_type    VARCHAR(50) NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cd_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Accounts ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS account_types (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(40) NOT NULL,
    name        VARCHAR(80) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    daily_transfer_limit   BIGINT NULL,
    daily_withdrawal_limit BIGINT NULL,
    monthly_limit          BIGINT NULL,
    UNIQUE KEY uq_account_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_number  VARCHAR(34) NOT NULL,
    customer_id     INT UNSIGNED NULL,          -- NULL for internal system (GL) accounts
    account_type_id INT UNSIGNED NULL,
    nickname        VARCHAR(80) NULL,
    currency        CHAR(3) NOT NULL,
    -- Cached balance; the ledger (ledger_entries) is the source of truth.
    -- Only LedgerService may change this column, always together with a ledger entry.
    balance         BIGINT NOT NULL DEFAULT 0,
    held_amount     BIGINT NOT NULL DEFAULT 0,  -- funds reserved by pending withdrawals/transfers
    status          ENUM('active','restricted','frozen','locked','suspended','closed') NOT NULL DEFAULT 'active',
    status_reason   VARCHAR(255) NULL,
    daily_transfer_limit   BIGINT NULL,
    daily_withdrawal_limit BIGINT NULL,
    monthly_limit          BIGINT NULL,
    is_system       TINYINT(1) NOT NULL DEFAULT 0,
    system_code     VARCHAR(40) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounts_number (account_number),
    UNIQUE KEY uq_accounts_system_code (system_code),
    KEY idx_accounts_customer (customer_id),
    KEY idx_accounts_status (status),
    CONSTRAINT fk_accounts_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_accounts_type FOREIGN KEY (account_type_id) REFERENCES account_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_managers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    manager_id  INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_am_customer_manager (customer_id, manager_id),
    KEY idx_am_manager (manager_id),
    CONSTRAINT fk_am_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_am_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_restrictions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED NOT NULL,
    restriction ENUM('transfers','withdrawals','deposits','cards') NOT NULL,
    reason      VARCHAR(255) NOT NULL,
    created_by  INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NULL,
    lifted_at   DATETIME NULL,
    lifted_by   INT UNSIGNED NULL,
    KEY idx_ar_account (account_id, lifted_at),
    CONSTRAINT fk_ar_account FOREIGN KEY (account_id) REFERENCES accounts(id),
    CONSTRAINT fk_ar_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Ledger ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS transactions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(32) NOT NULL,
    type            ENUM('deposit','withdrawal','transfer','fee','refund','adjustment','card','investment') NOT NULL,
    status          ENUM('pending','completed','failed','reversed','cancelled') NOT NULL DEFAULT 'pending',
    amount          BIGINT NOT NULL,
    currency        CHAR(3) NOT NULL,
    from_account_id INT UNSIGNED NULL,
    to_account_id   INT UNSIGNED NULL,
    description     VARCHAR(255) NULL,
    customer_reference VARCHAR(100) NULL,
    initiated_by    INT UNSIGNED NULL,
    approved_by     INT UNSIGNED NULL,
    fee_amount      BIGINT NOT NULL DEFAULT 0,   -- fee charged alongside (posted as a linked fee transaction)
    parent_id       BIGINT UNSIGNED NULL,        -- fee/reversal linked to originating transaction
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    UNIQUE KEY uq_transactions_reference (reference),
    KEY idx_tx_from (from_account_id, created_at),
    KEY idx_tx_to (to_account_id, created_at),
    KEY idx_tx_type_status (type, status),
    KEY idx_tx_created (created_at),
    CONSTRAINT fk_tx_from FOREIGN KEY (from_account_id) REFERENCES accounts(id),
    CONSTRAINT fk_tx_to FOREIGN KEY (to_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id  BIGINT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NULL,
    entry_type      ENUM('debit','credit') NOT NULL,
    amount          BIGINT NOT NULL,
    currency        CHAR(3) NOT NULL,
    balance_before  BIGINT NOT NULL,
    balance_after   BIGINT NOT NULL,
    description     VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_le_account (account_id, id),
    KEY idx_le_tx (transaction_id),
    CONSTRAINT fk_le_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    CONSTRAINT fk_le_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS withdrawal_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(32) NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    amount          BIGINT NOT NULL,
    currency        CHAR(3) NOT NULL,
    method          VARCHAR(60) NULL,
    details         VARCHAR(500) NULL,
    status          ENUM('pending','approved','rejected','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
    requested_by    INT UNSIGNED NOT NULL,
    reviewed_by     INT UNSIGNED NULL,
    review_note     VARCHAR(255) NULL,
    transaction_id  BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     DATETIME NULL,
    UNIQUE KEY uq_wr_reference (reference),
    KEY idx_wr_status (status, created_at),
    CONSTRAINT fk_wr_account FOREIGN KEY (account_id) REFERENCES accounts(id),
    CONSTRAINT fk_wr_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add-funds requests: created by staff (or customers, if enabled), approved by a second person.
CREATE TABLE IF NOT EXISTS deposit_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(32) NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    amount          BIGINT NOT NULL,
    currency        CHAR(3) NOT NULL,
    kind            ENUM('deposit','adjustment_credit','adjustment_debit') NOT NULL DEFAULT 'deposit',
    reason          VARCHAR(255) NOT NULL,
    external_reference VARCHAR(100) NULL,
    status          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requested_by    INT UNSIGNED NOT NULL,
    reviewed_by     INT UNSIGNED NULL,
    review_note     VARCHAR(255) NULL,
    transaction_id  BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     DATETIME NULL,
    UNIQUE KEY uq_dr_reference (reference),
    KEY idx_dr_status (status, created_at),
    CONSTRAINT fk_dr_account FOREIGN KEY (account_id) REFERENCES accounts(id),
    CONSTRAINT fk_dr_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transaction_approvals (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type   VARCHAR(40) NOT NULL,   -- withdrawal_request | deposit_request | transaction
    subject_id     BIGINT UNSIGNED NOT NULL,
    decision       ENUM('approved','rejected') NOT NULL,
    decided_by     INT UNSIGNED NOT NULL,
    note           VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ta_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Settings, audit, notifications ───────────────────────────────────

CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(80) NOT NULL PRIMARY KEY,
    `value`    TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) NULL,
    target_id   VARCHAR(40) NULL,
    old_value   TEXT NULL,
    new_value   TEXT NULL,
    reason      VARCHAR(255) NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_action (action, created_at),
    KEY idx_audit_target (target_type, target_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(150) NOT NULL,
    body       VARCHAR(500) NULL,
    link       VARCHAR(255) NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user (user_id, read_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
