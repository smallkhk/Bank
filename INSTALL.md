# Installing on cPanel (no terminal needed)

You need: a cPanel hosting account with **PHP 8.2 or newer** and **MySQL/MariaDB**, and a domain or subdomain.

## 1. Turn on SSL (the padlock)
cPanel → **SSL/TLS Status** → select your domain → **Run AutoSSL**. Wait until it shows a green padlock.

## 2. Choose PHP 8.2+
cPanel → **MultiPHP Manager** (or **Select PHP Version**) → set your domain to **PHP 8.2** or newer.
If you see "Select PHP Version", also tick the extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`.

## 3. Create the database
cPanel → **MySQL® Databases**:
1. **Create New Database**, e.g. `bank`. cPanel adds your prefix, so it becomes something like `myuser_bank`.
2. **Add New User**, e.g. `bankuser`, with a strong password. Write down the full name (`myuser_bankuser`) and the password.
3. **Add User To Database**: pick that user and that database, tick **ALL PRIVILEGES**, then save.

## 4. Upload the files
cPanel → **File Manager** → open your **home folder** (the folder that *contains* `public_html`, not `public_html` itself).
1. **Upload** `bank-release.zip`.
2. Right-click it → **Extract** → extract to your home folder.

You now have a `bank` folder (the private app files) next to `public_html` (the website).
If `public_html` had a default `index.html` from your host, delete it.

> **Using a subdomain** (e.g. `bank.yourdomain.com`)? cPanel → **Domains** → create the subdomain and set its
> document root to `public_html` (or move the contents of the extracted `public_html` into the subdomain's folder,
> and move the `bank` folder next to that folder).

## 5. Run the installer
Open **https://yourdomain.com/install.php** in your browser and fill in:
- your website address (starting with `https://`), bank name, currency and time zone;
- the database name, user and password from step 3 (host stays `localhost`);
- your administrator username, email and password.

Click **Install now**. When it says *"… is ready"*, click **Sign in**.

Then, in File Manager, **delete `public_html/install.php`**. It is already locked, but deleting it is tidier.

## 6. Set up the scheduled jobs
cPanel → **Cron Jobs** → add these three (replace `myuser` with your cPanel username; the PHP path may be
`/usr/local/bin/php` or `/usr/bin/php`, which your host's cron page usually shows):

| When | Command |
|---|---|
| Every 5 minutes | `/usr/local/bin/php /home/myuser/bank/cron/crypto-prices.php` |
| Daily, 01:10 | `/usr/local/bin/php /home/myuser/bank/cron/cards-daily.php` |
| Monthly, 1st at 02:15 | `/usr/local/bin/php /home/myuser/bank/cron/monthly-fees.php` |

## 7. First things to do after signing in
1. **Settings → General & branding:** logo, colours, contact details. Untick the demonstration banner when you're ready.
2. **Settings → Email:** turn on email notifications and send a test email.
3. **Staff:** add at least one more staff member, because money movements need a second person to approve them.
4. **Settings → Features:** switch on only what you want to offer.
5. **Back up `bank/config/config.php`** somewhere safe. The `key` inside it encrypts card numbers and API keys; if it's lost, those can't be recovered.

## Updating later
Upload the new release the same way (it doesn't include your `config.php`), then open
cPanel → **Terminal** and run `php ~/bank/database/install.php` if you have Terminal. If you don't, ask your developer
for the upgrade: new database changes are applied by that script.
