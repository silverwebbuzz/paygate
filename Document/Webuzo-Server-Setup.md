# PayGate on a Webuzo server (no Docker)

Step-by-step commands to run PayGate on an **AlmaLinux 9 server managed by Webuzo**, at `https://aidemo.in`.
Run the commands **one block at a time** and check each "Expected" line before moving on.

> Server used in this guide: AlmaLinux 9.8, Webuzo 4.8, IP `147.93.62.3`, domain `aidemo.in`.
> Replace these if yours differ.

---

## 0. What you are building (read once)

| Piece                 | What it is, in plain words                                                                                       | Where it runs                                         |
| --------------------- | ---------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| **PostgreSQL 18**     | The database (like MySQL, but PayGate needs PostgreSQL). Stores partners, transactions, ledger.                  | Service `postgresql-18`, port 5432, **this server only** |
| **Redis**             | A very fast in-memory store. PayGate uses it for the job queue, cache and login sessions.                        | Service `redis`, port 6379, **this server only**      |
| **PHP 8.5 (Remi)**    | PayGate needs PHP ≥ 8.4. Webuzo's PHP is 8.3, so we install 8.5 **side by side** (Webuzo's PHP is not touched).  | `/opt/remi/php85/...`                                 |
| **FrankenPHP**        | A small web server with PHP built in. It runs the PayGate website.                                               | Service `paygate-web`, `127.0.0.1:8000` (not public)  |
| **Horizon**           | Background workers (webhooks, emails, reports). Laravel's queue runner.                                          | Service `paygate-horizon`                             |
| **Scheduler**         | Laravel's "cron": expires payments, retries webhooks, sends alerts every minute.                                 | Service `paygate-scheduler`                           |
| **Webuzo web server** | Already on ports 80/443. Keeps doing SSL (Let's Encrypt) and simply **forwards** `aidemo.in` to :8000.              | Existing                                              |

For the demo everything runs on **one domain**, `aidemo.in`, split by path (no subdomains needed):

| Address                     | Used for                                   |
| --------------------------- | ------------------------------------------ |
| `https://aidemo.in/`        | Admin, partner and branch portals; Horizon |
| `https://aidemo.in/api/v1`  | Partner API                                |
| `https://aidemo.in/pay/p/…` | Public payment pages for payers            |

> Later, for production, you can move the API and payer pages to their own subdomains
> (`api.aidemo.in`, `pay.aidemo.in`) by changing four lines in `.env` (see the end of this guide). No code change needed.

```
Browser ──https──▶ Webuzo Apache (SSL) ──http──▶ FrankenPHP 127.0.0.1:8000 ──▶ PayGate (Laravel)
                                                                   │
                                     PostgreSQL 127.0.0.1:5432 ◀───┤───▶ Redis 127.0.0.1:6379
                                                                   │
                                            Horizon workers + Scheduler (systemd services)
```

The app runs as its own Linux user **`paygate`** in **`/opt/paygate/app`**, separate from Webuzo's users.

---

## Part A — Webuzo panel (browser), do this first

1. **DNS**: `aidemo.in` (and `www.aidemo.in`) must point to `147.93.62.3` (A record).
   If the domain's DNS is in Webuzo, adding the domain below creates it; otherwise add it at your domain registrar.
2. In the Webuzo **user** panel (not the admin panel): **Domains → Add Domain** → `aidemo.in` (skip if it is already listed).
3. **SSL/TLS → Let's Encrypt / AutoSSL**: issue a certificate for `aidemo.in` and `www.aidemo.in`.
4. Note the **document root** of `aidemo.in` (shown in the domain list), e.g. `/home/USER/public_html/aidemo.in`. You need it in step 14.

Check from your computer (wait a few minutes after DNS changes):

```bash
ping -c1 aidemo.in
```

Expected: a reply from `147.93.62.3`.

---

## Part B — Server setup over SSH (as `root`)

Log in: `ssh root@147.93.62.3` (or use Webuzo's **Terminal**).

### Step 1. Look at the server

```bash
cat /etc/almalinux-release
uname -m
ps -eo comm | grep -Ei 'httpd|nginx|lsws|litespeed' | sort -u
ss -ltnp | grep -E ':5432|:6379|:8000' || echo "ports free"
getenforce 2>/dev/null || echo "no selinux"
```

Expected: `AlmaLinux release 9.x`, `x86_64`, `httpd` in the web-server list, and `ports free`.

- If the web-server list shows **only** `nginx` or `litespeed`/`lshttpd` (no `httpd`), stop and ask: step 13 is different.
- If 5432 or 6379 is already in use, a PostgreSQL/Redis from Webuzo's Apps is running. Stop and ask before continuing.

### Step 2. Basic tools and extra repositories

```bash
dnf install -y epel-release
dnf install -y git unzip curl tar openssl
dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
```

### Step 3. PostgreSQL 18 (the database)

```bash
dnf install -y https://download.postgresql.org/pub/repos/yum/reporpms/EL-9-x86_64/pgdg-redhat-repo-latest.noarch.rpm
dnf -qy module disable postgresql
dnf install -y postgresql18-server postgresql18-contrib
PGSETUP_INITDB_OPTIONS="--auth-local=peer --auth-host=scram-sha-256 --encoding=UTF8 --locale=C.UTF-8" \
    /usr/pgsql-18/bin/postgresql-18-setup initdb
systemctl enable --now postgresql-18
systemctl status postgresql-18 --no-pager | head -5
```

Expected: `active (running)`. (`postgresql18-contrib` provides `btree_gist`, which PayGate needs.)

PostgreSQL listens on `localhost` only by default, so it is not reachable from the internet. Keep it that way.

### Step 4. Create the two database users and the database

PayGate uses two users: `paygate_owner` (only for migrations) and `paygate_app` (the running app, cannot change the schema or history tables).
This generates strong passwords and saves them in `/root/paygate-secrets.txt`:

```bash
umask 077
cat > /root/paygate-secrets.txt <<EOF
DB_OWNER_PASSWORD=$(openssl rand -hex 20)
DB_APP_PASSWORD=$(openssl rand -hex 20)
EOF
cat /root/paygate-secrets.txt
```

**Copy these two passwords into your password manager now.**

```bash
source /root/paygate-secrets.txt
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
CREATE ROLE paygate_owner LOGIN PASSWORD '$DB_OWNER_PASSWORD' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE paygate_app   LOGIN PASSWORD '$DB_APP_PASSWORD'   NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE paygate OWNER paygate_owner ENCODING 'UTF8' TEMPLATE template0;
ALTER DATABASE paygate SET timezone TO 'UTC';
SQL
sudo -u postgres psql -d paygate -v ON_ERROR_STOP=1 \
    -c "ALTER SCHEMA public OWNER TO paygate_owner;" \
    -c "REVOKE ALL ON SCHEMA public FROM PUBLIC;"
```

Expected: `CREATE ROLE`, `CREATE ROLE`, `CREATE DATABASE`, `ALTER DATABASE`, `ALTER SCHEMA`, `REVOKE`.

Test the login with a password over `127.0.0.1` (what the app does):

```bash
PGPASSWORD="$DB_APP_PASSWORD" psql "host=127.0.0.1 dbname=paygate user=paygate_app" -c "select version();"
```

Expected: one row starting `PostgreSQL 18`.

### Step 5. Redis (queues, cache, sessions)

```bash
dnf module list redis
dnf module enable -y redis:7
dnf install -y redis
sed -i 's/^appendonly no/appendonly yes/' /etc/redis/redis.conf
grep -E '^(bind|protected-mode|appendonly)' /etc/redis/redis.conf
systemctl enable --now redis
redis-cli ping
```

Expected: `bind 127.0.0.1 -::1`, `protected-mode yes`, `appendonly yes`, then `PONG`.

> If `dnf module list redis` shows no `7` stream, use Valkey (a drop-in Redis) instead:
> `dnf install -y valkey && sed -i 's/^appendonly no/appendonly yes/' /etc/valkey/valkey.conf && systemctl enable --now valkey && valkey-cli ping`

### Step 6. PHP 8.5 for the command line (side by side with Webuzo's PHP)

```bash
dnf install -y php85-php-cli php85-php-common php85-php-pgsql php85-php-pecl-redis6 \
    php85-php-intl php85-php-bcmath php85-php-mbstring php85-php-xml php85-php-process \
    php85-php-pecl-zip php85-php-gd php85-php-opcache php85-php-sodium
cat > /etc/opt/remi/php85/php.d/99-paygate.ini <<'EOF'
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 25M
expose_php = Off
date.timezone = UTC
EOF
/opt/remi/php85/root/usr/bin/php -v
/opt/remi/php85/root/usr/bin/php -m | grep -Ei '^(pdo_pgsql|pgsql|redis|intl|bcmath|pcntl|posix|zip|gd|exif|sockets|mbstring|Zend OPcache)$'
```

Expected: `PHP 8.5.x`, and every one of these names appears: pdo_pgsql, pgsql, redis, intl, bcmath, pcntl, posix, zip, gd, exif, sockets, mbstring, Zend OPcache. (If `php85-*` is not found, use `php84-` everywhere in this guide, including the paths `/opt/remi/php84/...`.)

Composer (PHP's package manager):

```bash
curl -sS https://getcomposer.org/installer | /opt/remi/php85/root/usr/bin/php -- --install-dir=/usr/local/bin --filename=composer
```

### Step 7. FrankenPHP (the web server that runs PayGate)

```bash
curl -fL -o /usr/local/bin/frankenphp https://github.com/php/frankenphp/releases/latest/download/frankenphp-linux-x86_64
chmod +x /usr/local/bin/frankenphp
frankenphp version
mkdir -p /etc/frankenphp/php.d
cat > /etc/frankenphp/php.d/paygate.ini <<'EOF'
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 25M
expose_php = Off
date.timezone = UTC
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
EOF
cat > /tmp/check.php <<'EOF'
<?php
echo PHP_VERSION, PHP_EOL;
foreach (['pdo_pgsql','redis','intl','bcmath','zip','gd','exif','mbstring','openssl'] as $e) echo $e, ': ', extension_loaded($e) ? 'ok' : 'MISSING', PHP_EOL;
echo 'upload_max_filesize: ', ini_get('upload_max_filesize'), PHP_EOL;
EOF
PHP_INI_SCAN_DIR=/etc/frankenphp/php.d frankenphp php-cli /tmp/check.php
```

Expected: a PHP version ≥ 8.4, every extension `ok`, and `upload_max_filesize: 20M`.

### Step 8. Node.js 24 (only to build the website's JavaScript/CSS)

```bash
curl -fsSL https://rpm.nodesource.com/setup_24.x | bash -
dnf install -y nodejs
node -v && npm -v
```

Expected: `v24.x`.

### Step 9. The `paygate` Linux user

```bash
useradd -m -d /opt/paygate -s /bin/bash paygate
sudo -iu paygate bash -c 'mkdir -p ~/bin ~/backups && ln -sf /opt/remi/php85/root/usr/bin/php ~/bin/php && echo "export PATH=\$HOME/bin:\$PATH" >> ~/.bashrc'
source /root/paygate-secrets.txt
sudo -iu paygate bash -c "umask 077; echo '127.0.0.1:5432:paygate:paygate_owner:$DB_OWNER_PASSWORD' > ~/.pgpass; echo 'DB_OWNER_PASSWORD=$DB_OWNER_PASSWORD' > ~/.deploy.env"
sudo -iu paygate bash -c 'php -v | head -1; ls -la ~/.pgpass ~/.deploy.env'
```

Expected: `PHP 8.5.x` and both files with `-rw-------`.

### Step 10. Let the server read the GitHub repository (deploy key)

```bash
sudo -iu paygate mkdir -p -m 700 /opt/paygate/.ssh
sudo -iu paygate ssh-keygen -t ed25519 -N "" -f /opt/paygate/.ssh/id_ed25519 -C "paygate@aidemo.in"
cat /opt/paygate/.ssh/id_ed25519.pub
```

Copy the printed line. On GitHub: **silverwebbuzz/paygate → Settings → Deploy keys → Add deploy key**, paste it, title `aidemo.in`, leave **"Allow write access" unticked**, Save.

```bash
sudo -iu paygate ssh -o StrictHostKeyChecking=accept-new -T git@github.com
```

Expected: `Hi silverwebbuzz/paygate! You've successfully authenticated...`

---

## Part C — First install of the app (as the `paygate` user)

```bash
sudo -iu paygate
```

Everything in Part C runs as `paygate` (your prompt shows `paygate@...`).

### Step 11. Get the code, install packages, create `.env`

```bash
git clone git@github.com:silverwebbuzz/paygate.git ~/app
cd ~/app
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
chmod 600 .env
```

Now edit `.env` (`nano .env`) and set these values (leave every other line as it is):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://aidemo.in

APP_DOMAIN=aidemo.in
API_DOMAIN=aidemo.in
PAY_DOMAIN=aidemo.in
HORIZON_DOMAIN=aidemo.in
API_PATH=api
PAY_PATH=pay

LOG_LEVEL=warning

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=paygate
DB_USERNAME=paygate_app
DB_PASSWORD=<DB_APP_PASSWORD from /root/paygate-secrets.txt>
DB_SSLMODE=disable

REDIS_HOST=127.0.0.1

SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=<mail.aidemo.in or your SMTP host>
MAIL_PORT=587
MAIL_SCHEME=null
MAIL_USERNAME=<a mailbox you created in Webuzo, e.g. no-reply@aidemo.in>
MAIL_PASSWORD=<its password>
MAIL_FROM_ADDRESS="no-reply@aidemo.in"
```

Notes: `API_PATH`/`PAY_PATH` put the API under `/api` and the payer pages under `/pay` (add both lines if they are not in the file). `DB_SSLMODE=disable` is fine because the database is on the same machine. Add `SESSION_SECURE_COOKIE=true` as a new line if it is not in the file.
Keep `PAYGATE_ENFORCE_2FA=true` and `PAYGATE_API_ENFORCE_IP=true`.

Generate the two secret keys (**run only once, ever**; changing them later makes stored bank/UPI data unreadable):

```bash
php artisan key:generate --force
sed -i "s|^PAYGATE_HASH_KEY=.*|PAYGATE_HASH_KEY=$(openssl rand -base64 32)|" .env
grep -E '^(APP_KEY|PAYGATE_HASH_KEY)=' .env
```

**Copy both lines into your password manager now** (separately from database backups).

### Step 12. Database tables, permissions, assets, first admin

```bash
cd ~/app
source ~/.deploy.env
DB_USERNAME=paygate_owner DB_PASSWORD="$DB_OWNER_PASSWORD" php artisan migrate --force
psql "host=127.0.0.1 dbname=paygate user=paygate_owner" -v app_role=paygate_app -f database/sql/app-privileges.sql
php artisan migrate:status | tail -3
```

Expected: migrations run without errors; `migrate:status` shows `Ran` for every line; the privileges script prints `GRANT`/`REVOKE`.

Check the safety rule works (this **must fail** with `permission denied`):

```bash
PGPASSWORD="$(grep ^DB_PASSWORD= .env | cut -d= -f2)" psql "host=127.0.0.1 dbname=paygate user=paygate_app" -c "UPDATE ledger_entries SET amount = amount WHERE false;"
```

Build the website files and cache the config:

```bash
npm ci
npm run build
php artisan storage:link
php artisan config:cache
php artisan event:cache
php artisan view:cache
```

Create the first Super admin (asks for name, email, password of 12+ characters with upper/lower case, a number and a symbol):

```bash
php artisan paygate:create-admin
exit
```

(`exit` returns you to `root`.)

---

## Part D — Run it (as `root`)

### Step 13. The three background services

```bash
cat > /etc/systemd/system/paygate-web.service <<'EOF'
[Unit]
Description=PayGate web (FrankenPHP)
After=network.target postgresql-18.service redis.service

[Service]
User=paygate
Group=paygate
WorkingDirectory=/opt/paygate/app
Environment=PHP_INI_SCAN_DIR=/etc/frankenphp/php.d
ExecStart=/usr/local/bin/frankenphp php-server --root /opt/paygate/app/public --listen 127.0.0.1:8000
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/paygate-horizon.service <<'EOF'
[Unit]
Description=PayGate queue workers (Horizon)
After=network.target postgresql-18.service redis.service

[Service]
User=paygate
Group=paygate
WorkingDirectory=/opt/paygate/app
Environment=PATH=/opt/paygate/bin:/usr/local/bin:/usr/bin:/bin
ExecStart=/opt/remi/php85/root/usr/bin/php artisan horizon
Restart=always
RestartSec=3
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/paygate-scheduler.service <<'EOF'
[Unit]
Description=PayGate scheduler
After=network.target postgresql-18.service redis.service

[Service]
User=paygate
Group=paygate
WorkingDirectory=/opt/paygate/app
Environment=PATH=/opt/paygate/bin:/usr/local/bin:/usr/bin:/bin
ExecStart=/opt/remi/php85/root/usr/bin/php artisan schedule:work
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now paygate-web paygate-horizon paygate-scheduler
systemctl status paygate-web paygate-horizon paygate-scheduler --no-pager | grep -E '●|Active'
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: aidemo.in' -H 'X-Forwarded-Proto: https' http://127.0.0.1:8000/up
```

Expected: all three `active (running)` and `200`.

The `paygate` user must be allowed to restart Horizon during deploys (used by the deploy script):

```bash
echo 'paygate ALL=(root) NOPASSWD: /usr/bin/systemctl restart paygate-horizon, /usr/bin/systemctl restart paygate-scheduler, /usr/bin/systemctl restart paygate-web' > /etc/sudoers.d/paygate
chmod 440 /etc/sudoers.d/paygate
visudo -cf /etc/sudoers.d/paygate
```

### Step 14. Forward aidemo.in from Webuzo's Apache to PayGate

If `getenforce` (step 1) said `Enforcing`, first run: `setsebool -P httpd_can_network_connect 1`.

Put this `.htaccess` file in the **document root of `aidemo.in`** from Part A step 4
(and delete any default `index.html` / `index.php` Webuzo placed there):

```apache
# PayGate: forward everything to the app on 127.0.0.1:8000.
# Let's Encrypt renewals (/.well-known/) stay with Webuzo.
RewriteEngine On

RewriteCond %{HTTPS} off
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

RequestHeader set X-Forwarded-Proto "https"

RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^(.*)$ http://127.0.0.1:8000/$1 [P,L]
```

The same, as commands (replace `DOCROOT` with the real document root and `USER` with the Webuzo user that owns the domain):

```bash
DOCROOT=/home/USER/public_html/aidemo.in
cat > "$DOCROOT/.htaccess" <<'HTACCESS'
RewriteEngine On

RewriteCond %{HTTPS} off
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

RequestHeader set X-Forwarded-Proto "https"

RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^(.*)$ http://127.0.0.1:8000/$1 [P,L]
HTACCESS
chown USER:USER "$DOCROOT/.htaccess"
rm -f "$DOCROOT/index.html"
```

If `aidemo.in`'s document root is the main `public_html` folder that also holds your other sites' folders, first give
`aidemo.in` its own folder in Webuzo's domain settings, so this `.htaccess` does not affect the other sites.

Apache forwards the original host name in `X-Forwarded-Host` and the visitor's IP in `X-Forwarded-For`; PayGate trusts
these headers only from `127.0.0.1` (see `bootstrap/app.php`).

Test from the server:

```bash
curl -sI https://aidemo.in/login | head -1
curl -s  https://aidemo.in/api/v1/ping; echo
curl -sI http://aidemo.in/ | grep -i location
```

Expected: `HTTP/... 200` for the login page; `{"status":"ok","time":"..."}` from the API; the http URL redirects to `https://`.

- `503 Service Unavailable` / `502`: Apache's proxy module is off, or SELinux blocks it (see above). In Webuzo admin, make sure Apache modules `proxy` and `proxy_http` are enabled.
- `ERR_TOO_MANY_REDIRECTS`: remove the 3 `RewriteCond %{HTTPS} off` … `[R=301,L]` lines and use Webuzo's own "force HTTPS" option instead.
- PayGate's own 404 page on `aidemo.in`: check `APP_DOMAIN` in `.env`, then `sudo -iu paygate php ~/app/artisan config:cache`.

Open **https://aidemo.in**, sign in with the admin from step 12, and set up two-factor authentication.
Horizon (queues) is at **https://aidemo.in/horizon**.
Partners call the API at **https://aidemo.in/api/v1** (the partner portal's API documentation page shows this address).
They sign the path **without** `/api` (e.g. `/v1/payins`), exactly as that documentation describes.
Payment links look like **https://aidemo.in/pay/p/…**.

### Step 15. Nightly database backup

```bash
sudo -iu paygate bash -c '(crontab -l 2>/dev/null; echo "30 2 * * * pg_dump -h 127.0.0.1 -U paygate_owner -Fc paygate > \$HOME/backups/paygate-\$(date +\%F).dump && find \$HOME/backups -name \"*.dump\" -mtime +14 -delete") | crontab -'
sudo -iu paygate crontab -l
```

Copy the backups off the server regularly (Webuzo's **Backup and Restore** can include `/opt/paygate/backups`). A backup is useless without `APP_KEY` and `PAYGATE_HASH_KEY`.

---

## Part E — Deploying new versions through git

### Step 16. Create the deploy script (once, as `root`)

```bash
cat > /opt/paygate/deploy.sh <<'EOF'
#!/usr/bin/env bash
# PayGate deploy: pull main, install, migrate as owner, rebuild, restart workers.
set -euo pipefail
export PATH="$HOME/bin:$PATH"
source "$HOME/.deploy.env"
cd "$HOME/app"

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan down --retry=30 || true
php artisan config:clear      # migrations must see the owner login below, not a cached config
DB_USERNAME=paygate_owner DB_PASSWORD="$DB_OWNER_PASSWORD" php artisan migrate --force
psql "host=127.0.0.1 dbname=paygate user=paygate_owner" -q -v app_role=paygate_app -f database/sql/app-privileges.sql
php artisan config:cache
php artisan event:cache
php artisan view:cache
php artisan up

sudo /usr/bin/systemctl restart paygate-horizon
sudo /usr/bin/systemctl restart paygate-scheduler
php artisan migrate:status | tail -1
echo "Deployed $(git log -1 --oneline)"
EOF
chown paygate:paygate /opt/paygate/deploy.sh
chmod 750 /opt/paygate/deploy.sh
```

### Step 17. Every release

1. Merge your changes into `main` on GitHub.
2. Take a backup first if the release has migrations: `sudo -iu paygate bash -c 'pg_dump -h 127.0.0.1 -U paygate_owner -Fc paygate > ~/backups/before-deploy-$(date +%F-%H%M).dump'`
3. Deploy:

```bash
sudo -iu paygate ~/deploy.sh
```

Expected: ends with `Deployed <commit>`. The site shows a short maintenance page while migrations run.

---

## Troubleshooting

| Symptom                                       | Look at                                                                                         |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| Site error / blank page                       | `tail -50 /opt/paygate/app/storage/logs/laravel-$(date +%F).log`                                |
| Web service down                              | `journalctl -u paygate-web -n 50 --no-pager`                                                    |
| Webhooks/emails not sent                      | `journalctl -u paygate-horizon -n 50 --no-pager`, https://aidemo.in/horizon                     |
| Payments not expiring, alerts missing         | `journalctl -u paygate-scheduler -n 50 --no-pager`                                              |
| `permission denied for table …` after release | Re-run the `psql … app-privileges.sql` line from the deploy script                              |
| `could not connect to server` (database)      | `systemctl status postgresql-18`                                                                |
| `Connection refused [tcp://127.0.0.1:6379]`   | `systemctl status redis` (or `valkey`)                                                          |
| `.env` change has no effect                   | `sudo -iu paygate php ~/app/artisan config:cache`, then `systemctl restart paygate-horizon`     |
| Upload larger than 2 MB fails                 | `/etc/frankenphp/php.d/paygate.ini` not loaded; re-check step 7, `systemctl restart paygate-web` |

Never run `php artisan db:seed` or `migrate:fresh` on this server. See [Deployment.md](Deployment.md) for the database rules.

---

## Later: moving the API and payer pages to subdomains

When the demo becomes production and you want `api.aidemo.in` and `pay.aidemo.in`:

1. In Webuzo, add the subdomains `api` and `pay`, issue SSL for them, and put the **same** `.htaccess` from step 14 in each subdomain's document root.
2. In `/opt/paygate/app/.env` change these lines:

   ```dotenv
   API_DOMAIN=api.aidemo.in
   PAY_DOMAIN=pay.aidemo.in
   API_PATH=
   PAY_PATH=
   ```

3. Run `sudo -iu paygate ~/deploy.sh` (it rebuilds the front-end and the config cache with the new addresses).

Partners then change their base URL from `https://aidemo.in/api/v1` to `https://api.aidemo.in/v1`; their signing code stays the same.
Payment links already sent keep pointing at the old `/pay/...` address, so switch before real payers use the demo links.
