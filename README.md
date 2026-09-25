# Internet Banking Platform

This is a closed-loop internet banking portal and internal ledger. It is written in plain PHP 8.2+ with MySQL/MariaDB and runs on ordinary cPanel hosting. It does not need Docker, Node, Redis or a framework.

> **Sandbox notice:** No real money moves. Deposits and withdrawals are simulated and handled by staff. Every balance change comes from ledger postings. Do not present this as a real banking service until the compliance items in §38 of the spec are addressed.

## What's included

### Phase 1: Core

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

### Phase 2: Banking experience

| Area | Details |
|---|---|
| **Support tickets** | Customers pick a category, write a subject and message, and can attach a file. Statuses: open, pending, assigned, escalated, resolved, closed. Staff can assign tickets, set priority, escalate, add internal notes the customer never sees, open cases for a customer, and filter by SLA-overdue, mine or unassigned. Response-time target and categories are configurable. |
| **Live chat** | Customer ⇄ support chat that works on any shared host. It uses AJAX polling instead of WebSockets. Supports read/unread tracking, agent assignment, internal notes, attachments and conversation search. |
| **Attachments** | Allowed types are PNG, JPG, WEBP, PDF and TXT, checked by content rather than extension, up to 5 MB. Files are stored **outside** the web root under random names and served only after an access check, with a sandboxing CSP. |
| **Notifications** | Twenty event templates, editable in **Templates**, using `{{placeholders}}`, delivered in-app and by email. Covers login from a new device, password and security changes, transfers, deposits, withdrawals, fees, support replies and more. |
| **Email** | `mail.driver` can be `log` (written to `storage/logs/mail.log`, the safe default) or `mail` (the server's sendmail through PHP `mail()`, available on cPanel). |
| **Security** | Two-step verification using authenticator-app codes, with 8 single-use recovery codes. Staff can reset a customer's 2FA with a reason, and 2FA can optionally be required for all staff. Password reset links are single-use, expire after 60 minutes and don't reveal whether an account exists. Email verification can optionally be required before sign-in. Existing sessions are revoked when a password is reset. |
| **Statements** | PDF download for any date range, generated from the ledger with a built-in writer (no libraries needed). Available to customers and staff. Printable HTML view too. |
| **Fees** | Monthly account fee per account type or global default, with an optional waiver above a minimum balance. The monthly cron job is **idempotent**. Staff can charge a custom fee with maker-checker approval. Fees report by type and month. Every fee is a ledger transaction. |
| **Limits** | Resolved as individual account override → account type (**Account types** page) → global default. |

### Phase 3: Cards (simulated; not connected to any card network)

| Area | Details |
|---|---|
| **Card products** | Debit, credit or prepaid; virtual or physical. Admins set the card-number prefix, daily/monthly/ATM limits, which of online, ATM and international use are allowed, issuance and replacement fees, the international fee, and expiry. Credit products also set the limit, APR, minimum payment (percentage plus floor), statement day, days to pay, and late fee. |
| **Lifecycle** | Customer or staff request → review → **issue**, which must be done by a different person from the requester. Statuses: pending, active, frozen, blocked, expired, cancelled, rejected. Customers can freeze/unfreeze and report a card lost or stolen, which blocks it immediately and queues a replacement. Staff can block, cancel and replace. Expiry is handled by cron. |
| **Card data security** | Card numbers are Luhn-valid and unique. The PAN and CVV are stored only **AES-256-GCM encrypted**, with a key derived from `app.key`, plus a keyed hash for uniqueness and the last 4 digits for display. Full details are shown only for virtual cards, after the customer re-enters their password; every reveal is audited, failed attempts are throttled, and the details hide again after 30 s. |
| **Controls** | Per-card switches for online, ATM and international use (never beyond what the product allows), and a personal daily limit capped at the product limit. An account-level `cards` restriction declines all card use on that account. |
| **Authorisation** | `CardService::authorize()` checks, in order: card status, expiry, account status and restrictions, channel and international switches, daily, monthly and ATM limits, then available funds or credit. Approved payments post to the ledger against `SYS-CARDS` (plus any international fee). Declines are recorded and the customer is notified. Reversals and refunds give back the amount and the fee. A **simulator** on the admin card page stands in for a real processor. |
| **Credit card accounts** | A dedicated account whose balance goes negative up to the credit limit; the ledger enforces the limit, and only interest and penalty fees may exceed it. Customers pay from their deposit accounts: full balance, minimum due, or another amount. The daily cron issues statements with a minimum payment and due date, charges **interest only when the previous statement wasn't paid in full**, charges a late fee **once** if the minimum is missed, and marks statements paid, minimum paid or overdue. Payments are matched to statements by ledger position rather than timestamp, so they are counted exactly. |

### Phase 5: Crypto (simulated; no blockchain)

| Area | Details |
|---|---|
| **Module** | **Off by default.** Turn it on in Settings → Cards & crypto. Customers must accept a risk notice (editable) before trading. Every screen is labelled "Simulated", and there are no deposit or withdrawal addresses anywhere. |
| **Assets** | BTC, ETH, USDT or any custom asset, each with a symbol, name, decimal precision (0–8, fixed once created), price, trading fee, minimum trade and status (active, halted, inactive). Prices are set manually or moved by an optional random-walk simulator (`cron/crypto-prices.php`). Full price history is kept, which drives the 24h change and the chart. |
| **Trading** | Buy by amount, sell by quantity or "sell all". A server-priced review step shows the price, quantity, fee and total; confirming checks the price hasn't changed since the review. Each trade posts balanced ledger entries against the bank's `SYS-CRYPTO` desk account, with fees going to `SYS-FEES`. Checks cover account ownership, restrictions, available balance, minimum and maximum trade size, and quantity held. Credit card accounts can't be used. |
| **Maths** | Exact integer arithmetic: quantities are stored in the smallest unit (e.g. satoshis) and money in cents, with an overflow-safe multiply-divide, so no `bcmath` or `gmp` is needed. Rounding never charges more than the amount entered, and proceeds round down. |
| **Portfolio** | Holdings, quantity, value, average cost (average-cost method, fees included), unrealised and realised profit/loss. `crypto_transactions` is the source of truth; holdings are a cache that the admin page reconciles against it. |
| **Admin** | Asset management, manual prices, halting trading, customer exposure, trading desk balance, 30-day volume and fees, and a searchable trade blotter linked to the ledger. Permissions: `crypto.view` and `crypto.manage`. |

### Admin settings

Everything below is configured in the back office under **Settings**. Nothing needs a file edit except database credentials and `app.key`.

| Tab | What you control |
|---|---|
| General & branding | Name, logo, favicon, colours, currency, contacts, the demonstration banner |
| **Features** | Switch on or off: account opening, auto-activation, email verification, maintenance mode, transfers, withdrawals, add-funds requests, transfer password confirmation, cards, credit cards, crypto (with trade cap and risk notice), support tickets, live chat |
| Accounts | Account number format, default account type, global limits |
| Transactions | Fees, approval threshold, maker-checker, monthly fee and waiver, fraud velocity limit |
| Security | Password policy, lockout, session timeout, staff two-step verification |
| **Email** | On/off, sending method (server mail or log only), sender address and name, test email |
| Support | Response-time target, ticket categories |
| Legal & messages | Terms, privacy, sign-in message, footer, maintenance message |

Other admin pages cover account types, card products, crypto assets, notification templates, roles and permissions.

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
database/           ← schema.sql, migrations/, install.php
cron/               ← scheduled jobs (monthly-fees.php, cards-daily.php, crypto-prices.php)
storage/            ← logs/, branding, attachments (outside web root)
```

**Core principle:** `accounts.balance` is a cache. Only `LedgerService::postEntries()` writes to it, and it does so in the same DB transaction that inserts the balanced ledger rows. Money coming into or leaving the platform goes through internal system accounts (`SYS-FUNDING`, `SYS-SETTLEMENT`, `SYS-FEES`, `SYS-ADJUST`). As a result, the sum of all balances is always zero.

## Install on cPanel

1. Upload the repository **above** `public_html` (for example `/home/USER/bank/`). Point the domain or subdomain document root at `bank/public_html`. Alternatively, move the contents of `public_html/` into your existing `public_html` and adjust the `require` path in `index.php`.
2. Create a MySQL database and user in cPanel, and grant **ALL PRIVILEGES** (TRIGGER is needed for the ledger immutability triggers).
3. Copy `config/config.example.php` to `config/config.php` and fill in the DB credentials, the URL and a random `app.key`. The key must be at least 32 characters (for example, from `php -r 'echo bin2hex(random_bytes(32));'`). It encrypts card data, so **back it up**: if it changes, stored card numbers can no longer be read. Keep `debug => false` and `session.secure => true` (HTTPS).
4. From **Terminal** (or SSH), run the command below. Re-run it after every upgrade: it applies new migrations from `database/migrations/` automatically.
   ```
   php database/install.php
   ```
   This creates the tables, roles, permissions, settings and system accounts, then prompts for the first Super Admin. It is safe to re-run.
5. Make sure `storage/` is writable by PHP. Enable SSL, then uncomment the HTTPS redirect in `public_html/.htaccess`.
6. Add the monthly fee cron in cPanel → **Cron Jobs**:
   ```
   15 2 1 * * /usr/local/bin/php /home/USER/bank/cron/monthly-fees.php
   ```
   (It charges the previous month. Running it again for the same month never charges twice.)

   Optionally, the simulated crypto price feed (every 15 minutes):
   ```
   */15 * * * * /usr/local/bin/php /home/USER/bank/cron/crypto-prices.php
   ```

   And the daily card job (statements, interest, late fees, expiry):
   ```
   10 1 * * * /usr/local/bin/php /home/USER/bank/cron/cards-daily.php
   ```
7. Sign in at `/login`. Then open **Settings** to set the bank name and branding, and **Staff** to add a second approver. Maker-checker means an approver cannot approve their own add-funds, adjustment or withdrawal requests; there is a setting to relax this, but it is not recommended.

## Integration points (disabled by default)

- **Email/SMS:** `Mailer` (add an API/SMTP provider driver; `mail.enabled` and `mail.driver` in config)
- **Payment/payout providers:** `FundingService` (deposits) and `WithdrawalService::approve()` (payouts)
- **Fraud/risk:** `RiskService`
- **Card processor / network:** replace the simulator with calls into `CardService::authorize()` / `reverse()`; swap `CardVault` for an HSM or tokenisation service before handling real cards (PCI DSS)
- **Crypto:** replace `CryptoService::simulatePrices()` with a market-data feed; real custody or blockchain transfers need a licensed provider and compliance controls
- **KYC:** `customers.kyc_status` and `customer_documents`

## Roadmap

Phase 4 (investments) was skipped at the owner's request. Phase 6 covers external integrations. The schema, permissions and service layer are designed so these modules can be added without reworking the core.
