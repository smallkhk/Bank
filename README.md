# Internet Banking Platform: Phase 1 (Core)

This is a closed-loop internet banking portal and internal ledger. It is written in plain PHP 8.2+ with MySQL/MariaDB and runs on ordinary cPanel hosting. It does not need Docker, Node, Redis or a framework.

> **Sandbox notice:** No real money moves. Deposits and withdrawals are simulated and handled by staff. Every balance change comes from ledger postings. Do not present this as a real banking service until the compliance items in §38 of the spec are addressed.

## What's included (Phase 1)

| Area | Details |
|---|---|
| **Authentication** | Hashed passwords (`password_hash`). DB-backed sessions that can be revoked, with idle timeout and "sign out other devices". Login throttling by user and IP. CSRF on every POST. Strict security headers and CSP. |
| **Registration** | Customer self-registration with review/activation, or auto-activation. Staff can also create customers. |
| **RBAC** | Roles: Super Admin, Director, Account Manager, Assistant, Support, Customer. 29 granular permissions, editable in **Roles**. Managers and assistants see only the customers **assigned** to them unless they hold `customers.view_all`. |
| **Accounts** | Multiple accounts per customer, 6 account types. Unique account numbers of configurable length, with optional prefix and branch code and a Luhn check digit. Statuses: active, restricted, frozen, locked, suspended, closed. Every status change needs a reason. |
| **Ledger** | Double-entry `ledger_entries` recording balance before and after, linked to `transactions`. MySQL triggers make ledger rows **immutable**. Every posting runs inside a DB transaction with row locks (`SELECT … FOR UPDATE`), so a partial transfer cannot happen. A reconciliation check appears on the admin dashboard. |
| **Internal transfers** | Checks account status, restrictions, available balance, daily and monthly limits, same currency, recipient, a velocity risk rule and an optional approval threshold (funds held until approved). Supports fixed and percentage fees and password confirmation. |
| **Add funds / adjustments** | Staff raise a request and a **different** staff member approves it (maker-checker). Posting goes against the internal `SYS-FUNDING` / `SYS-ADJUST` accounts. Customers can also submit add-funds requests. |
| **Withdrawals** | Customer or staff request → funds held → review → approval posts the ledger entry → completed. Rejecting or cancelling releases the hold. |
| **Restrictions** | Block transfers, withdrawals, deposits or cards, each with a reason, the staff member, a timestamp and an optional expiry. Limits can be set per account, per account type or globally. |
| **Audit log** | Covers logins, failures, status changes, restrictions, funds, transfers, role and permission changes, settings and more. Stores old and new values, IP, user agent and reason. Searchable and filterable. |
| **Admin** | Dashboard with KPIs, a 14-day volume chart, security alerts and recent activity. Customers, accounts, transactions (with filters and CSV export), approval queues, staff, roles and settings. |
| **Branding** | Bank name (defaults to `{{BANK_NAME}}`), logo, favicon, colors, currency, contacts, terms and privacy text, login message, footer and maintenance mode. All set from **Settings**. |
| **Customer portal** | Dashboard, accounts, statements by date range (printable), transaction receipts, transfers, withdrawals, add funds, notifications, profile and security. Mobile-first. |

Money is stored as integer minor units (cents) everywhere. Floats are never used.

## Architecture

```
public_html/        ← document root (index.php, .htaccess, assets/, uploads/ [non-executable])
app/
  core/             ← Router, Db, Auth, Csrf, View, Middleware, ErrorHandler
  services/         ← LedgerService, TransferService, WithdrawalService, FundingService,
                      AccountService, ApprovalService, RiskService, AuditService, ...
  controllers/      ← customer + admin/ controllers
  views/            ← layouts, partials, customer/, admin/
config/             ← config.php (not web accessible, gitignored)
database/           ← schema.sql, install.php
storage/            ← logs/, branding uploads (outside web root)
```

**Core principle:** `accounts.balance` is a cache. Only `LedgerService::postEntries()` writes to it, and it does so in the same DB transaction that inserts the balanced ledger rows. Money coming into or leaving the platform goes through internal system accounts (`SYS-FUNDING`, `SYS-SETTLEMENT`, `SYS-FEES`, `SYS-ADJUST`). As a result, the sum of all balances is always zero.

## Install on cPanel

1. Upload the repository **above** `public_html` (for example `/home/USER/bank/`). Point the domain or subdomain document root at `bank/public_html`. Alternatively, move the contents of `public_html/` into your existing `public_html` and adjust the `require` path in `index.php`.
2. Create a MySQL database and user in cPanel, and grant **ALL PRIVILEGES** (TRIGGER is needed for the ledger immutability triggers).
3. Copy `config/config.example.php` to `config/config.php` and fill in the DB credentials, the URL and a random `app.key`. Keep `debug => false` and `session.secure => true` (HTTPS).
4. From **Terminal** (or SSH), run:
   ```
   php database/install.php
   ```
   This creates the tables, roles, permissions, settings and system accounts, then prompts for the first Super Admin. It is safe to re-run.
5. Make sure `storage/` is writable by PHP. Enable SSL, then uncomment the HTTPS redirect in `public_html/.htaccess`.
6. Sign in at `/login`. Then open **Settings** to set the bank name and branding, and **Staff** to add a second approver. Maker-checker means an approver cannot approve their own add-funds, adjustment or withdrawal requests; there is a setting to relax this, but it is not recommended.

## Integration points (disabled by default)

- **Email/SMS:** `NotificationService::deliverExternal()` (`mail.enabled` in config)
- **Payment/payout providers:** `FundingService` (deposits) and `WithdrawalService::approve()` (payouts)
- **Fraud/risk:** `RiskService`
- **KYC:** `customers.kyc_status` and `customer_documents`

## Roadmap

Phase 2 covers support tickets and chat, statements as PDF, a notification template editor and 2FA. Phase 3 covers cards. Phases 4–5 cover simulated investments and crypto. Phase 6 covers external integrations. The schema, permissions and service layer are designed so these modules can be added without reworking the core.
