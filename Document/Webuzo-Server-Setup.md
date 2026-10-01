# PayGate on a Webuzo server (no Docker)

Step-by-step commands to run PayGate at **`https://aidemo.in`** on an AlmaLinux 9 VPS managed by Webuzo,
**without changing anything your other projects use**.
Run the commands **one block at a time** and check each "Expected" line before moving on.

> This server: AlmaLinux 9.8, Webuzo 4.8, IP `147.93.62.3`, Webuzo user `silverwebbuzz_in`,
> `aidemo.in` document root `/home/silverwebbuzz_in/public_html/aidemo`. Replace these if yours differ.

---

## 0. The plan (read once)

### Everything PayGate needs lives in one folder

```
/home/silverwebbuzz_in/
├── public_html/aidemo/        ← aidemo.in document root: only one .htaccess file (forwards to PayGate)
└── paygate/                   ← everything PayGate, NOT reachable from the web
    ├── app/                   ← the code (git clone), .env, uploads, logs
    ├── bin/                   ← php (→ separate PHP 8.5 in /opt/remi), frankenphp, composer, psql, pg_dump
    ├── node/                  ← private Node.js 24 (only used to build the website files)
    ├── etc/                   ← PayGate's own PHP settings and service settings
    ├── backups/               ← nightly database backups
    ├── env.sh                 ← "use PayGate's tools" switch for a terminal session
    └── deploy.sh              ← one command to deploy a new version from git
```

The code is **beside** `public_html`, not inside it, so `.env` (passwords and keys) can never be downloaded,
even if the `.htaccess` stops working.

### What it uses on the server, and what it leaves alone

| Piece                 | PayGate uses                                                                  | Your other projects                                                    |
| --------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| **PHP**               | A separate **PHP 8.5** in `/opt/remi/php85` (Webuzo's PHP 8.5 lacks `redis` and crashes on exit), called only by its full path | Unchanged. The server's default PHP, Webuzo's PHP 8.5 and every site's PHP stay as they are |
| PHP settings          | Its own file `paygate/etc/php.d/paygate.ini`                                  | Webuzo's `php.ini` files are **not edited**                            |
| **Web server**        | **FrankenPHP**, a single file in `paygate/bin`, listening on `127.0.0.1:8000`   | Untouched. Apache keeps serving all sites; only `aidemo.in` forwards to :8000 |
| **PostgreSQL**        | A **new PostgreSQL 18** on port **5433** (PayGate needs 15 or newer)            | Webuzo's PostgreSQL 13 on port 5432 keeps running, untouched           |
| **Redis**             | Webuzo's **Redis 8.2**, but its own numbered databases (10 and 11) and key names | Other projects keep databases 0–9 and their own keys                   |
| **Node.js**           | Private **Node 24** in `paygate/node`                                         | Webuzo's Node 12 and the `node` command stay as they are               |
| **Composer**          | Private copy in `paygate/bin`                                                 | Unchanged                                                              |
| Background jobs       | 3 new services: `paygate-web`, `paygate-horizon`, `paygate-scheduler`         | Nothing else is changed                                                 |

The new terms, in plain words:

- **PostgreSQL**: the database (like MySQL). PayGate stores partners, payments and the ledger there.
- **Redis**: a very fast in-memory store. PayGate keeps its job queue, cache and login sessions there.
- **Horizon**: PayGate's background workers (webhooks, emails, reports).
- **Scheduler**: PayGate's own "cron" (expires payments, retries webhooks, sends alerts every minute).

### Addresses (one domain for the demo)

| Address                     | Used for                                   |
| --------------------------- | ------------------------------------------ |
| `https://aidemo.in/`        | Admin, partner and branch portals; Horizon |
| `https://aidemo.in/api/v1`  | Partner API                                |
| `https://aidemo.in/pay/p/…` | Public payment pages for payers            |

```
Browser ──https──▶ Webuzo Apache (SSL, aidemo.in) ──▶ FrankenPHP 127.0.0.1:8000 ──▶ PayGate
                                                                    │
                       PostgreSQL 18, 127.0.0.1:5433 ◀──────────────┼──────────────▶ Redis 127.0.0.1:6379 (db 10, 11)
                                                                    │
                                              Horizon workers + Scheduler (services)
```

> **Security note.** PayGate runs as the Webuzo user `silverwebbuzz_in`. Every other website of that user runs as
> the same Linux user, so a hacked plugin on any of those sites could read PayGate's `.env`. That is acceptable for a
> demo. **Before real money goes through PayGate**, give `aidemo.in` its own Webuzo user (Webuzo admin → Users → Add
> user) and repeat this guide with that user name.

---

## Part A — Webuzo panel (browser)

1. **Domain**: `aidemo.in` is already added, with document root `/home/silverwebbuzz_in/public_html/aidemo`.
2. **SSL**: Webuzo user panel → **SSL/TLS → Let's Encrypt / AutoSSL** → issue a certificate for `aidemo.in` and `www.aidemo.in`.
3. **Apps → Auto Upgrade**: set **PostgreSQL** and **Redis** to **"Do not Auto Upgrade"**.
   An automatic major upgrade of a database can break the data in it. Upgrade databases only on purpose, after a backup.

---

## Part B — Server setup over SSH (as `root`)

Log in: `ssh root@147.93.62.3` (or Webuzo's **Terminal**).

### Step 1. Check the server

```bash
echo "== Webuzo PHP 8.5 =="
/usr/local/apps/php85/bin/php -v | head -1
/usr/local/apps/php85/bin/php -m | grep -Ei '^(pdo_pgsql|pgsql|redis|intl|bcmath|pcntl|posix|zip|gd|exif|sockets|mbstring|zend opcache|sodium|openssl|curl|fileinfo|tokenizer|dom|xml)$' | sort -fu
echo "== Redis =="
REDIS_CLI=$(ls /usr/local/apps/redis/bin/redis-cli 2>/dev/null || command -v redis-cli); echo "$REDIS_CLI"
$REDIS_CLI ping
$REDIS_CLI CONFIG GET bind; $REDIS_CLI CONFIG GET requirepass; $REDIS_CLI CONFIG GET databases
$REDIS_CLI INFO keyspace
echo "== Ports in use =="
ss -ltnp | grep -E ':5432|:5433|:6379|:8000 ' || true
echo "== Web server =="
ps -eo comm | grep -Ei 'httpd|nginx|lsws|litespeed' | sort -u
getenforce 2>/dev/null || echo "no selinux"
echo "== aidemo folder =="
ls -la /home/silverwebbuzz_in/public_html/aidemo
```

Expected:

- **PHP**: on this server Webuzo's PHP 8.5 has no `redis` extension and prints `free(): invalid pointer` (it crashes
  when it exits). PayGate therefore gets its own PHP 8.5 in step 1b, and Webuzo's PHP is left alone.
- **Redis**: `PONG`; `bind` shows `127.0.0.1` (only this server); `databases` is `16`.
  Note the `requirepass` value: if it is not empty, that is the Redis password for `.env` in step 11.
  `INFO keyspace` lists the databases already used (`db0`, `db1`…). PayGate uses **db10 and db11**; if those already appear, tell me.
- **Ports**: something on 5432 (Webuzo PostgreSQL 13) and 6379 (Redis). **Nothing** on 5433 or 8000.
- **Web server**: on this server both `httpd` (Apache) and `nginx` run. Step 1a finds out which one answers the
  visitors, because step 14 depends on it.

### Step 1a. Which web server answers, and where is aidemo.in's folder?

```bash
echo "== Who listens on 80/443 =="
ss -ltnp | grep -E ':(80|443|8080|8181|8443) '
echo "== aidemo.in in the web server settings =="
grep -rls "aidemo" /usr/local/apps/nginx/etc /usr/local/apps/apache2/etc 2>/dev/null | head
grep -rhs -A3 "server_name.*aidemo\|ServerName.*aidemo" /usr/local/apps/nginx/etc /usr/local/apps/apache2/etc 2>/dev/null | grep -Ei "server_name|ServerName|root|DocumentRoot|proxy_pass" | head -20
echo "== Folders =="
ls -la /home/silverwebbuzz_in/public_html | grep -i aidemo
ls -d /home/*/public_html/*aidemo* 2>/dev/null
```

Send the output. It decides the folder used in step 14 and whether step 14 uses `.htaccess` (Apache) or an nginx
setting.

### Step 1b. PHP 8.5 for PayGate only (separate from Webuzo's PHP)

The Remi repository installs PHP versions **side by side** under `/opt/remi/php85`. It does not replace the `php`
command, Webuzo's PHP, or any site's PHP; only PayGate calls it, by its full path.

```bash
dnf install -y epel-release
dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf install -y php85-php-cli php85-php-common php85-php-pgsql php85-php-pecl-redis6 \
    php85-php-intl php85-php-bcmath php85-php-mbstring php85-php-xml php85-php-process \
    php85-php-pecl-zip php85-php-gd php85-php-opcache php85-php-sodium
/opt/remi/php85/root/usr/bin/php -v | head -1
/opt/remi/php85/root/usr/bin/php -m | grep -Ei '^(pdo_pgsql|pgsql|redis|intl|bcmath|pcntl|posix|zip|gd|exif|sockets|mbstring|zend opcache|sodium|openssl|curl|fileinfo|tokenizer|dom|xml)$' | sort -fu | tr '\n' ' '; echo
php -v | head -1
```

Expected: `PHP 8.5.x`, then all 20 names (bcmath curl dom exif fileinfo gd intl mbstring openssl pcntl pdo_pgsql
pgsql posix redis sockets sodium tokenizer xml zip Zend OPcache), and **no** `free(): invalid pointer`.
The last line is the server's normal `php`, which must show the **same version as before** (unchanged).

### Step 2. PayGate's folder and its "switch" files

```bash
P=/home/silverwebbuzz_in/paygate
mkdir -p $P/{bin,node,etc/php.d,backups,.ssh}

cat > $P/env.sh <<'ENVSH'
# PayGate tools for this terminal session: source ~/paygate/env.sh
export PAYGATE=/home/silverwebbuzz_in/paygate
export PATH="$PAYGATE/bin:$PAYGATE/node/bin:$PATH"
export PHP_INI_SCAN_DIR=":$PAYGATE/etc/php.d"
export PGPASSFILE="$PAYGATE/.pgpass"
export COMPOSER_HOME="$PAYGATE/.composer"
export npm_config_cache="$PAYGATE/.npm"
export GIT_SSH_COMMAND="ssh -i $PAYGATE/.ssh/deploy_key -o IdentitiesOnly=yes -o UserKnownHostsFile=$PAYGATE/.ssh/known_hosts -o StrictHostKeyChecking=accept-new"
ENVSH

cat > $P/etc/services.env <<'SVCENV'
PATH=/home/silverwebbuzz_in/paygate/bin:/home/silverwebbuzz_in/paygate/node/bin:/usr/local/bin:/usr/bin:/bin
PHP_INI_SCAN_DIR=:/home/silverwebbuzz_in/paygate/etc/php.d
SVCENV

cat > $P/etc/php.d/paygate.ini <<'PHPINI'
; PayGate only. Loaded after Webuzo's own php.ini, through PHP_INI_SCAN_DIR (env.sh / services.env).
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 25M
expose_php = Off
date.timezone = UTC
; Horizon (background workers) needs proc_open, exec and pcntl_*.
disable_functions =
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
PHPINI

ln -sf /opt/remi/php85/root/usr/bin/php $P/bin/php
chown -R silverwebbuzz_in:silverwebbuzz_in $P
chmod 700 $P $P/.ssh
ls -la $P
```

Expected: the folders `bin node etc backups .ssh` and `env.sh`, owned by `silverwebbuzz_in`.

`PHP_INI_SCAN_DIR=":…"` means: "load PHP's normal settings, **then** PayGate's file". It is set only for PayGate's
terminal sessions and services, so other sites never see it.

Check it works:

```bash
sudo -u silverwebbuzz_in -H bash -c 'source ~/paygate/env.sh && php -r "echo PHP_VERSION, \" \", ini_get(\"memory_limit\"), \" \", ini_get(\"date.timezone\"), \" [\", ini_get(\"disable_functions\"), \"]\n\";"'
```

Expected: `8.5.x 512M UTC []`.

### Step 3. PostgreSQL 18 on port 5433 (beside Webuzo's PostgreSQL 13)

```bash
dnf install -y https://download.postgresql.org/pub/repos/yum/reporpms/EL-9-x86_64/pgdg-redhat-repo-latest.noarch.rpm
dnf -qy module disable postgresql
dnf install -y postgresql18-server postgresql18-contrib
PGSETUP_INITDB_OPTIONS="--auth-local=peer --auth-host=scram-sha-256 --encoding=UTF8 --locale=C.UTF-8" \
    /usr/pgsql-18/bin/postgresql-18-setup initdb
sed -i -E "s/^#?port = [0-9]+/port = 5433/" /var/lib/pgsql/18/data/postgresql.conf
grep -E "^(port|#?listen_addresses)" /var/lib/pgsql/18/data/postgresql.conf
systemctl enable --now postgresql-18
systemctl status postgresql-18 --no-pager | head -5
ss -ltnp | grep -E ':5432|:5433'
```

Expected: `port = 5433`, `listen_addresses = 'localhost'` (commented is fine, that is the default), `active (running)`,
and both 5432 (Webuzo's PostgreSQL 13) and 5433 (the new one) listening.

`postgresql18-contrib` provides `btree_gist`, which PayGate needs. Webuzo's PostgreSQL 13 is not touched.

```bash
ln -sf /usr/pgsql-18/bin/psql /home/silverwebbuzz_in/paygate/bin/psql
ln -sf /usr/pgsql-18/bin/pg_dump /home/silverwebbuzz_in/paygate/bin/pg_dump
```

### Step 4. PayGate's database and its two database users

PayGate uses two users: `paygate_owner` (only for migrations) and `paygate_app` (the running app; it cannot change
the table structure or rewrite history tables). This makes strong passwords and keeps them in a root-only file:

```bash
umask 077
cat > /root/paygate-secrets.txt <<SECRETS
DB_OWNER_PASSWORD=$(openssl rand -hex 20)
DB_APP_PASSWORD=$(openssl rand -hex 20)
SECRETS
cat /root/paygate-secrets.txt
```

**Copy both passwords into your password manager now.**

```bash
source /root/paygate-secrets.txt
sudo -u postgres /usr/pgsql-18/bin/psql -p 5433 -v ON_ERROR_STOP=1 <<SQL
CREATE ROLE paygate_owner LOGIN PASSWORD '$DB_OWNER_PASSWORD' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE paygate_app   LOGIN PASSWORD '$DB_APP_PASSWORD'   NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE paygate OWNER paygate_owner ENCODING 'UTF8' TEMPLATE template0;
ALTER DATABASE paygate SET timezone TO 'UTC';
SQL
sudo -u postgres /usr/pgsql-18/bin/psql -p 5433 -d paygate -v ON_ERROR_STOP=1 \
    -c "ALTER SCHEMA public OWNER TO paygate_owner;" \
    -c "REVOKE ALL ON SCHEMA public FROM PUBLIC;"
```

Expected: `CREATE ROLE`, `CREATE ROLE`, `CREATE DATABASE`, `ALTER DATABASE`, `ALTER SCHEMA`, `REVOKE`.

Save the owner password where PayGate's tools find it (only `silverwebbuzz_in` can read these two files):

```bash
P=/home/silverwebbuzz_in/paygate
echo "127.0.0.1:5433:paygate:paygate_owner:$DB_OWNER_PASSWORD" > $P/.pgpass
echo "DB_OWNER_PASSWORD=$DB_OWNER_PASSWORD" > $P/.deploy.env
chown silverwebbuzz_in:silverwebbuzz_in $P/.pgpass $P/.deploy.env
chmod 600 $P/.pgpass $P/.deploy.env
PGPASSWORD="$DB_APP_PASSWORD" /usr/pgsql-18/bin/psql "host=127.0.0.1 port=5433 dbname=paygate user=paygate_app" -c "select version();"
```

Expected: one row starting `PostgreSQL 18`.

### Step 5. Redis

Nothing to install: PayGate uses Webuzo's Redis (checked in step 1) with its own databases **10** and **11**
and its own key names, so it never mixes with other projects' data. You set this in `.env` in step 11.

### Step 6. Composer (private copy)

```bash
sudo -u silverwebbuzz_in -H bash -c 'source ~/paygate/env.sh && curl -sS https://getcomposer.org/installer | php -- --install-dir=$PAYGATE/bin --filename=composer && composer --version'
```

Expected: `Composer version 2.x`.

### Step 7. FrankenPHP (the web server that runs PayGate)

```bash
sudo -u silverwebbuzz_in -H bash -c 'source ~/paygate/env.sh && curl -fL -o $PAYGATE/bin/frankenphp https://github.com/php/frankenphp/releases/latest/download/frankenphp-linux-x86_64 && chmod +x $PAYGATE/bin/frankenphp && frankenphp version'
```

Expected: `FrankenPHP v1.x PHP 8.x ...`. FrankenPHP has its own PHP built in (for the website only) with every
extension PayGate needs.

Check it reads PayGate's PHP settings:

```bash
sudo -u silverwebbuzz_in -H bash -c 'source ~/paygate/env.sh && echo "<?php echo ini_get(\"upload_max_filesize\"), \" \", extension_loaded(\"pdo_pgsql\") && extension_loaded(\"redis\") ? \"ok\" : \"MISSING\", PHP_EOL;" > /tmp/pg-check.php && frankenphp php-cli /tmp/pg-check.php; rm -f /tmp/pg-check.php'
```

Expected: `20M ok`.

### Step 8. Node.js 24 (private, only to build the website files)

```bash
sudo -u silverwebbuzz_in -H bash -c '
source ~/paygate/env.sh
NODE_TAR=$(curl -fsSL https://nodejs.org/dist/latest-v24.x/SHASUMS256.txt | awk "/linux-x64.tar.xz\$/{print \$2}")
echo "Downloading $NODE_TAR"
curl -fsSL "https://nodejs.org/dist/latest-v24.x/$NODE_TAR" | tar -xJ -C $PAYGATE/node --strip-components=1
which node; node -v; npm -v'
node -v 2>/dev/null || echo "server-wide node unchanged"
```

Expected: `/home/silverwebbuzz_in/paygate/node/bin/node` and `v24.x` inside PayGate's session; the last line
shows the server's own Node (`v12…`), unchanged.

### Step 9. Deploy key (lets the server read the GitHub repository)

A key just for PayGate, so the Webuzo user's own SSH keys are not touched:

```bash
sudo -u silverwebbuzz_in -H ssh-keygen -t ed25519 -N "" -f /home/silverwebbuzz_in/paygate/.ssh/deploy_key -C "paygate@aidemo.in"
cat /home/silverwebbuzz_in/paygate/.ssh/deploy_key.pub
```

Copy the printed line. On GitHub: **silverwebbuzz/paygate → Settings → Deploy keys → Add deploy key**, paste it,
title `aidemo.in`, leave **"Allow write access" unticked**, Save. Then:

```bash
sudo -u silverwebbuzz_in -H bash -c 'source ~/paygate/env.sh && $GIT_SSH_COMMAND -T git@github.com'
```

Expected: `Hi silverwebbuzz/paygate! You've successfully authenticated...`

---

## Part C — First install of the app (as `silverwebbuzz_in`)

Open a PayGate session (do this every time you work on PayGate by hand):

```bash
sudo -u silverwebbuzz_in -H bash
source ~/paygate/env.sh
```

Your prompt now runs as `silverwebbuzz_in` with PayGate's tools. Check: `which php node composer psql` should all
show `/home/silverwebbuzz_in/paygate/...`.

### Step 10. Get the code

> The server installs the **`main`** branch. Merge the PayGate changes you want into `main` on GitHub first.

```bash
git clone git@github.com:silverwebbuzz/paygate.git ~/paygate/app
cd ~/paygate/app
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
chmod 600 .env
```

### Step 11. Fill in `.env`

`nano .env` and set these values (leave every other line as it is; add a line if it is missing):

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
DB_PORT=5433
DB_DATABASE=paygate
DB_USERNAME=paygate_app
DB_PASSWORD=<DB_APP_PASSWORD from /root/paygate-secrets.txt>
DB_SSLMODE=disable

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=10
REDIS_CACHE_DB=11
REDIS_PREFIX=paygate-aidemo-
CACHE_PREFIX=paygate-aidemo-cache-
HORIZON_PREFIX=paygate-aidemo-horizon:

SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=<mail.aidemo.in or your SMTP host>
MAIL_PORT=587
MAIL_SCHEME=null
MAIL_USERNAME=<a mailbox you created in Webuzo, e.g. no-reply@aidemo.in>
MAIL_PASSWORD=<its password>
MAIL_FROM_ADDRESS="no-reply@aidemo.in"
```

- `REDIS_PASSWORD`: keep `null` if step 1 showed an empty `requirepass`; otherwise put that password.
- `API_PATH`/`PAY_PATH`: the API lives under `/api`, payer pages under `/pay`.
- Keep `PAYGATE_ENFORCE_2FA=true` and `PAYGATE_API_ENFORCE_IP=true`.

Generate the two secret keys (**only once, ever**; changing them later makes stored bank/UPI details unreadable):

```bash
php artisan key:generate --force
sed -i "s|^PAYGATE_HASH_KEY=.*|PAYGATE_HASH_KEY=$(openssl rand -base64 32)|" .env
grep -E '^(APP_KEY|PAYGATE_HASH_KEY)=' .env
```

**Copy both lines into your password manager now**, separately from the database backups.

### Step 12. Tables, permissions, website files, first admin

```bash
cd ~/paygate/app
source ~/paygate/.deploy.env
DB_USERNAME=paygate_owner DB_PASSWORD="$DB_OWNER_PASSWORD" php artisan migrate --force
psql "host=127.0.0.1 port=5433 dbname=paygate user=paygate_owner" -v app_role=paygate_app -f database/sql/app-privileges.sql
php artisan migrate:status | tail -3
```

Expected: migrations run without errors; the privileges script prints `GRANT`/`REVOKE`; every line of
`migrate:status` says `Ran`.

Check the safety rule (this **must fail** with `permission denied`):

```bash
PGPASSWORD="$(grep ^DB_PASSWORD= .env | cut -d= -f2)" psql "host=127.0.0.1 port=5433 dbname=paygate user=paygate_app" -c "UPDATE ledger_entries SET amount = amount WHERE false;"
```

Build the website files and cache the settings:

```bash
npm ci
npm run build
php artisan storage:link
php artisan config:cache
php artisan event:cache
php artisan view:cache
```

Create the first Super admin (name, email, and a password of 12+ characters with upper and lower case, a number
and a symbol):

```bash
php artisan paygate:create-admin
exit
```

(`exit` takes you back to `root`.)

---

## Part D — Run it (as `root`)

### Step 13. The three PayGate services

```bash
P=/home/silverwebbuzz_in/paygate

cat > /etc/systemd/system/paygate-web.service <<UNIT
[Unit]
Description=PayGate web (FrankenPHP) for aidemo.in
After=network.target postgresql-18.service

[Service]
User=silverwebbuzz_in
Group=silverwebbuzz_in
WorkingDirectory=$P/app
EnvironmentFile=$P/etc/services.env
ExecStart=$P/bin/frankenphp php-server --root $P/app/public --listen 127.0.0.1:8000
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/paygate-horizon.service <<UNIT
[Unit]
Description=PayGate queue workers (Horizon)
After=network.target postgresql-18.service

[Service]
User=silverwebbuzz_in
Group=silverwebbuzz_in
WorkingDirectory=$P/app
EnvironmentFile=$P/etc/services.env
ExecStart=$P/bin/php artisan horizon
Restart=always
RestartSec=3
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/paygate-scheduler.service <<UNIT
[Unit]
Description=PayGate scheduler
After=network.target postgresql-18.service

[Service]
User=silverwebbuzz_in
Group=silverwebbuzz_in
WorkingDirectory=$P/app
EnvironmentFile=$P/etc/services.env
ExecStart=$P/bin/php artisan schedule:work
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now paygate-web paygate-horizon paygate-scheduler
systemctl status paygate-web paygate-horizon paygate-scheduler --no-pager | grep -E '●|Active'
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: aidemo.in' http://127.0.0.1:8000/up
```

Expected: all three `active (running)` and `200`.

Let PayGate's deploy script restart its own services (and nothing else):

```bash
echo 'silverwebbuzz_in ALL=(root) NOPASSWD: /usr/bin/systemctl restart paygate-horizon, /usr/bin/systemctl restart paygate-scheduler, /usr/bin/systemctl restart paygate-web' > /etc/sudoers.d/paygate
chmod 440 /etc/sudoers.d/paygate
visudo -cf /etc/sudoers.d/paygate
```

Expected: `parsed OK`.

### Step 14. Forward aidemo.in from Apache to PayGate

If step 1 showed `Enforcing` for SELinux, first run: `setsebool -P httpd_can_network_connect 1`.

This puts one `.htaccess` in `aidemo.in`'s document root (backing up anything that is there), and nothing else:

```bash
D=/home/silverwebbuzz_in/public_html/aidemo
mkdir -p /root/aidemo-docroot-backup && cp -a $D/. /root/aidemo-docroot-backup/ 2>/dev/null
cat > $D/.htaccess <<'HTACCESS'
# aidemo.in → PayGate (FrankenPHP on 127.0.0.1:8000). Let's Encrypt renewals (/.well-known/) stay with Webuzo.
RewriteEngine On

RewriteCond %{HTTPS} off
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

RequestHeader set X-Forwarded-Proto "https"

RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^(.*)$ http://127.0.0.1:8000/$1 [P,L]
HTACCESS
chown silverwebbuzz_in:silverwebbuzz_in $D/.htaccess
rm -f $D/index.html $D/index.php
ls -la $D
```

Apache passes the original host name (`X-Forwarded-Host`) and the visitor's IP (`X-Forwarded-For`) to PayGate,
which trusts them only from `127.0.0.1` (see `bootstrap/app.php`).

Test:

```bash
curl -sI https://aidemo.in/login | head -1
curl -s  https://aidemo.in/api/v1/ping; echo
curl -sI http://aidemo.in/ | grep -i location
```

Expected: `HTTP/... 200`, then `{"status":"ok","time":"..."}`, then a redirect to `https://aidemo.in/`.

- `502` / `503`: Apache's proxy modules are off, or SELinux blocks it. In Webuzo admin, enable Apache modules
  `proxy`, `proxy_http` and `headers`.
- `500` right away: `mod_headers` is off. Enable `headers`, or delete the `RequestHeader` line.
- `ERR_TOO_MANY_REDIRECTS`: delete the three lines from `RewriteCond %{HTTPS} off` to `[R=301,L]`, and use Webuzo's
  own "Force HTTPS" option for the domain instead.
- PayGate's own "Not found" page on `https://aidemo.in/`: check `APP_DOMAIN` in `.env`, then (as `silverwebbuzz_in`
  with `env.sh`) `php artisan config:cache`.

Open **https://aidemo.in**, sign in with the admin from step 12 and set up two-factor authentication.

- Horizon (queues): **https://aidemo.in/horizon**
- Partner API: **https://aidemo.in/api/v1** (the partner portal's API documentation page shows this address).
  Partners sign the path **without** `/api` (e.g. `/v1/payins`), exactly as that page describes.
- Payment links: **https://aidemo.in/pay/p/…**

### Step 15. Nightly database backup

```bash
(crontab -u silverwebbuzz_in -l 2>/dev/null; echo '30 2 * * * . $HOME/paygate/env.sh && pg_dump -h 127.0.0.1 -p 5433 -U paygate_owner -Fc paygate > $HOME/paygate/backups/paygate-$(date +\%F).dump && find $HOME/paygate/backups -name "*.dump" -mtime +14 -delete') | crontab -u silverwebbuzz_in -
crontab -u silverwebbuzz_in -l | tail -2
```

This also shows up under the user's **Cron Jobs** in Webuzo. Copy `paygate/backups` off the server regularly
(Webuzo **Backup and Restore** of the user includes it). A database backup is useless without `APP_KEY` and
`PAYGATE_HASH_KEY`.

---

## Part E — Deploying new versions through git

### Step 16. The deploy script (once, as `root`)

```bash
cat > /home/silverwebbuzz_in/paygate/deploy.sh <<'DEPLOY'
#!/usr/bin/env bash
# PayGate deploy: pull main, install, migrate as owner, rebuild, restart workers.
set -euo pipefail
source "$HOME/paygate/env.sh"
source "$PAYGATE/.deploy.env"
cd "$PAYGATE/app"

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan down --retry=30 || true
php artisan config:clear      # migrations must use the owner login below, not a cached config
DB_USERNAME=paygate_owner DB_PASSWORD="$DB_OWNER_PASSWORD" php artisan migrate --force
psql "host=127.0.0.1 port=5433 dbname=paygate user=paygate_owner" -q -v app_role=paygate_app -f database/sql/app-privileges.sql
php artisan config:cache
php artisan event:cache
php artisan view:cache
php artisan up

sudo /usr/bin/systemctl restart paygate-horizon
sudo /usr/bin/systemctl restart paygate-scheduler
php artisan migrate:status | tail -1
echo "Deployed $(git log -1 --oneline)"
DEPLOY
chown silverwebbuzz_in:silverwebbuzz_in /home/silverwebbuzz_in/paygate/deploy.sh
chmod 750 /home/silverwebbuzz_in/paygate/deploy.sh
```

### Step 17. Every release

1. Merge your changes into `main` on GitHub.
2. If the release changes the database, take a backup first:
   `sudo -u silverwebbuzz_in -H bash -c 'source ~/paygate/env.sh && pg_dump -h 127.0.0.1 -p 5433 -U paygate_owner -Fc paygate > ~/paygate/backups/before-deploy-$(date +%F-%H%M).dump'`
3. Deploy:

```bash
sudo -u silverwebbuzz_in -H /home/silverwebbuzz_in/paygate/deploy.sh
```

Expected: it ends with `Deployed <commit>`. Visitors see a short maintenance page while migrations run.

---

## Troubleshooting

| Symptom                                       | Look at                                                                                          |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Site error / blank page                       | `tail -50 /home/silverwebbuzz_in/paygate/app/storage/logs/laravel-$(date +%F).log`               |
| Web service down                              | `journalctl -u paygate-web -n 50 --no-pager`                                                     |
| Webhooks/emails not sent                      | `journalctl -u paygate-horizon -n 50 --no-pager`, https://aidemo.in/horizon                      |
| Payments not expiring, alerts missing         | `journalctl -u paygate-scheduler -n 50 --no-pager`                                               |
| `permission denied for table …` after release | Re-run the `psql … app-privileges.sql` line from the deploy script                               |
| `could not connect to server` (database)      | `systemctl status postgresql-18`; `.env` must have `DB_PORT=5433`                                |
| `Connection refused … 6379` / `NOAUTH`        | Webuzo's Redis is stopped, or it has a password: set `REDIS_PASSWORD` in `.env`, then `config:cache` |
| `.env` change has no effect                   | As `silverwebbuzz_in` with `env.sh`: `php artisan config:cache`, then `sudo systemctl restart paygate-horizon` |
| `Call to undefined function proc_open()`      | `PHP_INI_SCAN_DIR` not set: use `source ~/paygate/env.sh` (by hand) or check `etc/services.env`  |
| Upload larger than 2 MB fails                 | Step 7 check must say `20M`; then `systemctl restart paygate-web`                                |

Never run `php artisan db:seed` or `migrate:fresh` on this server. See [Deployment.md](Deployment.md) for the database rules.

---

## Later: moving the API and payer pages to subdomains

When the demo becomes production and you want `api.aidemo.in` and `pay.aidemo.in`:

1. In Webuzo, add the subdomains `api` and `pay`, issue SSL for them, and copy the `.htaccess` from step 14 into each subdomain's document root.
2. In `/home/silverwebbuzz_in/paygate/app/.env` change:

   ```dotenv
   API_DOMAIN=api.aidemo.in
   PAY_DOMAIN=pay.aidemo.in
   API_PATH=
   PAY_PATH=
   ```

3. Run the deploy script (step 17); it rebuilds the website files and the settings cache with the new addresses.

Partners then change their base URL from `https://aidemo.in/api/v1` to `https://api.aidemo.in/v1`; their signing
code stays the same. Payment links already sent keep the old `/pay/...` address, so switch before real payers use the demo.

## Removing PayGate completely

Nothing else on the server depends on it:

```bash
systemctl disable --now paygate-web paygate-horizon paygate-scheduler
rm -f /etc/systemd/system/paygate-*.service /etc/sudoers.d/paygate && systemctl daemon-reload
rm -f /home/silverwebbuzz_in/public_html/aidemo/.htaccess
crontab -u silverwebbuzz_in -l | grep -v 'paygate' | crontab -u silverwebbuzz_in -
# Only after you have kept a final backup:
# rm -rf /home/silverwebbuzz_in/paygate
# dnf remove postgresql18-server postgresql18-contrib postgresql18 && rm -rf /var/lib/pgsql/18
```
