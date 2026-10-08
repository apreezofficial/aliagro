# AliAgro API — vanilla PHP

The AliAgro farm-to-consumer marketplace backend, ported from Laravel 13 to plain PHP 8.3.
**No Composer, no framework, no third-party packages** — just PDO, cURL and the standard library.
Same routes, same JSON shapes, same database tables, and tokens already issued by the Laravel app keep working
(Sanctum-compatible `id|token` bearer tokens, bcrypt hashes).

## Requirements
PHP 8.3+ with `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `openssl`; MySQL 5.7+ / MariaDB 10.3+.

## Setup
### Easiest: the web installer
Deploy the files, point the domain at `public/`, and open **`/setup`** (visiting `/` in a browser redirects there on a fresh install).
Enter the MySQL host/port/database/user/password, your site URLs and the first admin account. It then connects to MySQL
(optionally creating the database), builds the schema, seeds categories/badges/a sample coupon, creates your admin,
writes `.env` (with a fresh `APP_KEY`) and **locks itself** by creating `storage/installed.lock`, after which `/setup` returns 403.
It needs write access to the project folder (for `.env`) and `storage/`. Optionally set `SETUP_KEY=something` in the server
environment first and the form will demand it, so nobody else can claim a freshly deployed site. If an install is interrupted
just submit again; every step is idempotent.

### Or from the command line
```bash
cp .env.example .env          # then edit DB_*, APP_URL, FRONTEND_URL, mail, gateway keys
php bin/key-generate.php      # writes APP_KEY (used to sign email-verification links)
php bin/migrate.php           # idempotent; safe on a DB the Laravel app already migrated
php bin/seed.php              # categories, badges, coupon + demo users
php -S 127.0.0.1:8000 -t public
```
In production use `php bin/seed.php --reference-only` — the demo account passwords
(`admin@aliagro.com / Admin@1234` …) are public.

## Layout
```
public/index.php        front controller (document root)       routes/api.php   all routes
bootstrap/app.php       autoloader (App\ => src/), .env         config/          app, database, mail, services
src/Core/               Router, Request, Response, Kernel, DB, Query, Model, Validator, Auth, Mailer, ...
src/Controllers/        one per resource (same names as before)  src/Models/      table metadata, casts, relations
src/Services/           Paystack, Flutterwave, Loyalty, Badge, ImageUpload
src/Notifications/      order / product / auth emails           database/schema.sql   full MySQL schema
bin/                    migrate.php, seed.php, key-generate.php  tests/ApiTest.php     end-to-end tests
```

## Deploying on cPanel
1. Upload the project, point the domain's document root at `public/` (or keep the root `.htaccess`, which forwards to `public/`).
2. Create the MySQL DB/user, fill `.env`, run `php bin/key-generate.php`, `php bin/migrate.php`, `php bin/seed.php --reference-only` in Terminal.
3. `public/storage/` and `storage/logs/` must be writable (775). Uploads are saved straight into `public/storage/…`; there is no `storage:link` step.
4. No cron or queue worker is needed any more.

## Differences from the Laravel version
* **Mail is sent inline** (no queue). A failing SMTP server is logged to `storage/logs` and never breaks the request. `MAIL_MAILER` = `log` | `smtp` | `mail`.
* **Removed `/run-migrations`** (an unauthenticated web endpoint that ran migrations). Use `php bin/migrate.php`.
* Password-reset emails link to `FRONTEND_URL/reset-password?token=…&email=…`.
* Email-verification links are signed (HMAC with `APP_KEY`, 60 min) and the signature is now enforced.
* New table `rate_limits` backs the auth throttle (10 req/min/IP on `/api/auth/*`).
* Uploaded file URLs are `/storage/{folder}/{uuid}.{ext}`; the extension comes from the file's real MIME type.

### Bugs fixed on the way
* `GET /api/products/trending` was shadowed by `products/{product}` (always 404) — now routed first.
* `addresses/{id}` update/delete never matched the model (route param was `address`, method took `$deliveryAddress`), and `show` did not exist.
* Paystack amount used `(int)($amount*100)` (₦19.99 → 1998 kobo); now rounded.
* Flutterwave verify used a gateway id that was never stored; now falls back to `verify_by_reference`.
* Webhooks/verification are idempotent (row locks) so a webhook + manual verify can't double-credit; stock, coupon usage, wallet and loyalty updates are done under locks/transactions; duplicate order lines are merged before the stock check.
* Suspending a user now revokes their tokens; Google sign-in requires a verified Google email before linking by email.
* `order-items/{id}/status` no longer re-sends "delivered" emails/bonuses when called again on an already delivered order.

### Not implemented in the original either
`products.total_sold`, `farmer_profiles.total_sales/rating` are never updated; cancelling a *paid* order does not refund;
the `/api/payments/{gateway}/callback` URLs sent to the gateways have no route.

## Tests
```bash
# empty test DB configured in .env, then:
php bin/migrate.php && php bin/seed.php
php -S 127.0.0.1:8000 -t public &
php tests/ApiTest.php            # 76 checks across auth, products, orders, payments, KYC, admin, throttling
```
