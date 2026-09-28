# PayGate — Database Setup for Staging & Production

How to create the PayGate database on a **new** staging or production server the first time, and how to run migrations on every later release.
For your local machine, use the [Developer Guide](Developer-Guide.md) instead (`make setup` does everything).

> Every command in §3–§5 was tested on 2026-09-27 against PostgreSQL 18, using a non-superuser owner exactly as described here.

---

## 1. What you need before starting

| Item            | Requirement                                               | Notes                                                                                                                                                    |
| --------------- | --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PostgreSQL      | **Version 15 or newer** (18 recommended)                  | Managed service preferred (AWS RDS / DigitalOcean / etc., India region). Older versions fail on `NULLS NOT DISTINCT`                                     |
| Extension       | `btree_gist` must be **allowed**                          | It is a "trusted" extension: the database owner can create it, no superuser needed. Most managed services allow it; check your provider's extension list |
| Redis           | Version 7+                                                | Queues, cache, sessions                                                                                                                                  |
| App server      | PHP 8.5 image from this repository, with `psql` available | The Docker image already includes `postgresql-client`                                                                                                    |
| Admin access    | A PostgreSQL admin/master login                           | Only used once, in §3                                                                                                                                    |
| Secrets storage | A password manager or secret manager                      | For the two DB passwords and **`APP_KEY`** (§2)                                                                                                          |

---

## 2. Before the first deploy: the `APP_KEY` (critical)

`APP_KEY` encrypts **bank account numbers, UPI IDs and partner API secrets** in the database.

- Generate it **once** per environment: `php artisan key:generate --show`, and store it in your secret manager.
- **Never change or lose it.** If the key is lost or changed, every encrypted value becomes unreadable.
- Staging and production must have **different** keys.
- Back it up separately from the database backups.

### 2.1 `PAYGATE_HASH_KEY` (critical, same rules)

Bank account numbers and UPI IDs are also stored as an HMAC "blind index" (`*_hash` columns), so the database can refuse the same account being added twice without storing the number in clear. The HMAC key is `PAYGATE_HASH_KEY`.

- Generate it **once** per environment: `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`, store it next to `APP_KEY`.
- **Never change or lose it.** With a different key, duplicate accounts are no longer detected and account search stops matching.
- Different in staging and production. The app refuses to save accounts when it is missing.

---

## 3. First-time database setup (run once per environment)

### 3.1 Create two database users and the database

PayGate uses **two** database users:

| User            | Used by                                          | Can do                                                                                                  |
| --------------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| `paygate_owner` | Migrations only (`php artisan migrate`)          | Owns the schema: create and alter tables                                                                |
| `paygate_app`   | The running application (web, queues, scheduler) | Read and write data only. **Cannot** alter the schema, remove safety triggers, or change history tables |

Connect as the PostgreSQL admin/master user and run (replace the passwords):

```sql
CREATE ROLE paygate_owner LOGIN PASSWORD 'CHANGE-ME-owner' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE paygate_app   LOGIN PASSWORD 'CHANGE-ME-app'   NOSUPERUSER NOCREATEDB NOCREATEROLE;

CREATE DATABASE paygate OWNER paygate_owner ENCODING 'UTF8' TEMPLATE template0;
ALTER DATABASE paygate SET timezone TO 'UTC';
```

Then connect to the new `paygate` database (still as admin) and run:

```sql
ALTER SCHEMA public OWNER TO paygate_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
```

> On AWS RDS the admin user must be a member of `paygate_owner` to run `CREATE DATABASE … OWNER paygate_owner`: first run `GRANT paygate_owner TO <your_admin_user>;`.

### 3.2 Configure the application `.env`

The app always connects as **`paygate_app`**:

```dotenv
APP_ENV=production            # staging: APP_ENV=staging
APP_DEBUG=false
APP_KEY=base64:...            # from §2
PAYGATE_HASH_KEY=...          # from §2.1
APP_URL=https://paygate.example.com

APP_DOMAIN=paygate.example.com
API_DOMAIN=api.paygate.example.com
PAY_DOMAIN=pay.paygate.example.com
HORIZON_DOMAIN=paygate.example.com

DB_CONNECTION=pgsql
DB_HOST=<database host>
DB_PORT=5432
DB_DATABASE=paygate
DB_USERNAME=paygate_app
DB_PASSWORD=<paygate_app password>
DB_SSLMODE=require            # managed databases: always require TLS

REDIS_HOST=<redis host>
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true

PAYGATE_ENFORCE_2FA=true      # never false outside a developer's own machine
```

### 3.3 Run the migrations as the owner

Migrations run with the **owner** credentials, passed only for this one command (they are not stored in `.env`):

```bash
DB_USERNAME=paygate_owner DB_PASSWORD='<owner password>' php artisan migrate --force
```

This creates all **52 tables**, the `btree_gist` extension, every constraint and trigger, and the built-in system data:

- 9 system roles with their permissions
- 3 platform ledger accounts
- 10 default reject reasons

It takes a few seconds.

### 3.4 Give the app user its privileges

Run the privileges script as the **owner** (the script is in the repository):

```bash
psql "host=<db host> dbname=paygate user=paygate_owner sslmode=require" \
     -v app_role=paygate_app \
     -f database/sql/app-privileges.sql
```

The script:

- grants read and write on all tables to `paygate_app`;
- revokes UPDATE/DELETE on the history tables (`audit_logs`, `security_logs`, `transaction_events`, `ledger_journals`, `ledger_entries`, `webhook_attempts`) and on `migrations`.

It's safe to run more than once.

### 3.5 Verify

Run as `paygate_owner` (or any user):

```sql
SELECT count(*) AS tables FROM information_schema.tables WHERE table_schema = 'public';       -- 48
SELECT count(*) AS system_roles FROM roles WHERE is_system;                                    -- 9
SELECT count(*) AS platform_accounts FROM ledger_accounts WHERE partner_id IS NULL;            -- 3
SELECT count(*) AS reason_codes FROM reason_codes;                                             -- 10
SELECT extversion FROM pg_extension WHERE extname = 'btree_gist';                              -- a version number
```

And as `paygate_app`, this must **fail** with `permission denied`:

```sql
UPDATE ledger_entries SET amount = amount WHERE false;
```

Also run `php artisan migrate:status`: every migration must show **Ran**.

### 3.6 Create the first admin

```bash
php artisan paygate:create-admin
```

It asks for a name, email and password (min. 12 characters with mixed case, numbers and symbols in production) and creates a **Super admin**. Two-factor authentication is set up at first login. All other users are created from the admin panel.

> **Never run `php artisan db:seed` on staging or production.** The demo seeder refuses to run outside `APP_ENV=local`, and there is nothing to seed: all required system data comes from the migrations.

### 3.7 First-time checklist

- [ ] PostgreSQL ≥ 15, `btree_gist` allowed, TLS on
- [ ] `APP_KEY` generated, stored in the secret manager, backed up
- [ ] `PAYGATE_HASH_KEY` generated, stored and backed up the same way
- [ ] `paygate_owner` and `paygate_app` created; the app `.env` uses `paygate_app`
- [ ] `migrate --force` run as `paygate_owner`: all migrations "Ran"
- [ ] `database/sql/app-privileges.sql` applied
- [ ] Verification queries in §3.5 match; the app user can't update `ledger_entries`
- [ ] First Super admin created; 2FA set up
- [ ] Automated backups + point-in-time recovery switched on (§5)

---

## 4. Every later release that includes migrations

```bash
# 1. Take a snapshot / confirm last night's backup succeeded
# 2. Deploy the new code
# 3. Run migrations as the owner
DB_USERNAME=paygate_owner DB_PASSWORD='<owner password>' php artisan migrate --force
# 4. Re-apply app privileges (covers any new tables)
psql "host=<db host> dbname=paygate user=paygate_owner sslmode=require" -v app_role=paygate_app -f database/sql/app-privileges.sql
# 5. Restart long-running processes so they load the new code
php artisan horizon:terminate     # the process supervisor restarts Horizon
php artisan octane:reload         # only once Octane is enabled (planned for go-live; not installed yet)
# 6. Check
php artisan migrate:status
```

### Rules for writing migrations

| Rule                                                                                                                                                    | Why                                               |
| ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| **Never edit a migration that has run on staging or production.** Add a new one                                                                         | Environments would otherwise differ               |
| **Expand → migrate → contract** for changes to existing columns: add the new column → release code that uses it → remove the old one in a later release | Old and new code run side by side during a deploy |
| **Never drop or rewrite financial data** (`transactions`, `ledger_*`, `settlements`, `*_logs`)                                                          | Financial history must stay complete              |
| New system data (roles, reason codes, …) goes **inside a migration**, never a seeder                                                                    | Staging and production must get it automatically  |
| New append-only tables: add them to `database/sql/app-privileges.sql` and call `Pg::appendOnly()` in the migration                                      | Keeps both protection layers                      |
| Test on a copy of staging data before production                                                                                                        | Catches slow or locking migrations                |

### Rolling back

- Prefer **fixing forward** with a new migration.
- `php artisan migrate:rollback --step=1` is acceptable only for a migration that **added** structure and has **no data yet**. Each PayGate migration has a tested `down()`.
- Never roll back past a migration whose tables already hold financial data. Restore from backup instead (§5).

---

## 5. Backups and restore

| What                                 | How                                                                                                                         |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| Daily automated backups              | Managed-database setting, **≥ 30 days** retention                                                                           |
| Point-in-time recovery               | Switch on (lets you restore to any second, e.g. just before a bad release)                                                  |
| Before every release with migrations | Manual snapshot                                                                                                             |
| `APP_KEY`                            | Stored separately in the secret manager; a restored database is useless without it                                          |
| **Restore test**                     | **Monthly**: restore the latest backup into a scratch database and run the §3.5 queries. An untested backup is not a backup |

---

## 6. Troubleshooting

| Error                                                      | Cause                                                        | Fix                                                                                          |
| ---------------------------------------------------------- | ------------------------------------------------------------ | -------------------------------------------------------------------------------------------- |
| `permission denied to create extension "btree_gist"`       | Provider doesn't allow it, or migrations ran as the app user | Run migrations as `paygate_owner`; enable/allow-list `btree_gist` in the provider's settings |
| `syntax error at or near "NULLS"`                          | PostgreSQL older than 15                                     | Upgrade PostgreSQL                                                                           |
| `permission denied for table …` in the app after a release | New tables have no grants yet                                | Run `database/sql/app-privileges.sql` (§4 step 4)                                            |
| `permission denied for schema public` during migrate       | Migrations ran as `paygate_app`                              | Use the owner credentials (§3.3)                                                             |
| `The MAC is invalid` / decryption errors                   | `APP_KEY` differs from the one used to encrypt the data      | Restore the correct `APP_KEY` from the secret manager                                        |
| `ledger journal … is unbalanced`                           | Application bug: a journal's entries don't sum to zero       | The database blocked it and nothing was saved; investigate the code path                     |
| `… is append-only`                                         | Something tried to edit or delete a history row              | Expected protection; corrections must be new entries                                         |

---

## 7. Local vs staging/production at a glance

|                | Local (`make setup`)                                | Staging / production                                                                                                                                                                                                                                                                                                       |
| -------------- | --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Database users | One superuser `paygate`                             | `paygate_owner` (migrations) + `paygate_app` (runtime)                                                                                                                                                                                                                                                                     |
| Migrations     | `make migrate` / `make fresh`                       | `migrate --force` as owner, then the privileges script                                                                                                                                                                                                                                                                     |
| Demo data      | `make fresh` seeds demo partner, branch and 7 users | **Never** seeded                                                                                                                                                                                                                                                                                                           |
| First admin    | `admin@paygate.local` / `password`                  | `php artisan paygate:create-admin`                                                                                                                                                                                                                                                                                         |
| 2FA            | Optional (`PAYGATE_ENFORCE_2FA=false` allowed)      | Always on                                                                                                                                                                                                                                                                                                                  |
| `APP_KEY`      | Generated by `make setup`                           | Generated once, kept in the secret manager                                                                                                                                                                                                                                                                                 |
| QA Checklist   | On (`/admin/qa-checklist`, Horizon `qa` worker)     | **Staging:** on if the server has dev dependencies (`composer install` without `--no-dev`) and a separate `paygate_testing` database the app user may reset; otherwise the page works but _Re-run_ says why it can't. **Production:** always off (`PAYGATE_QA_CHECKLIST` is ignored; the page answers 404, no `qa` worker) |
