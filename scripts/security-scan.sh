#!/usr/bin/env bash
# PayGate external security & performance scan (read-only, non-destructive).
#
#   scripts/security-scan.sh <portal-url> <api-url> <pay-url> [report.md]
#   scripts/security-scan.sh https://aidemo.in https://aidemo.in/api https://aidemo.in/pay
#
# Runs from any machine with curl, openssl and ab (Apache Bench, ships with
# macOS). It never logs in, never creates data and keeps load light
# (LOAD_REQUESTS / LOAD_CONCURRENCY, default 300 / 10). Only run it against
# servers you own or have written permission to test. Writes a Markdown
# report (default: security-report-<host>-<date>.md). Method and how to read
# the results: Document/Security-Testing.md.

set -uo pipefail

PORTAL=${1:?portal url, e.g. https://aidemo.in}
API=${2:?api url, e.g. https://aidemo.in/api}
PAY=${3:?pay url, e.g. https://aidemo.in/pay}
PORTAL=${PORTAL%/}; API=${API%/}; PAY=${PAY%/}
HOST=$(echo "$PORTAL" | sed -E 's#^https?://##; s#/.*##')
REPORT=${4:-security-report-$HOST-$(date +%Y%m%d-%H%M).md}
LOAD_REQUESTS=${LOAD_REQUESTS:-300}
LOAD_CONCURRENCY=${LOAD_CONCURRENCY:-10}
UA="PayGate-SecurityScan/1"

PASS=0; FAIL=0; WARN=0
ROWS=""

row() { # status, area, check, detail
    case $1 in PASS) PASS=$((PASS+1));; FAIL) FAIL=$((FAIL+1));; WARN) WARN=$((WARN+1));; esac
    local icon; case $1 in PASS) icon="✅ PASS";; FAIL) icon="❌ FAIL";; *) icon="⚠️ WARN";; esac
    ROWS+="| $icon | $2 | $3 | ${4//|/\\|} |"$'\n'
}

headers() { curl -s -o /dev/null -D - -A "$UA" --max-time 20 "$@" | tr -d '\r'; }
status() { curl -s -o /dev/null -w '%{http_code}' -A "$UA" --max-time 20 "$@"; }
header_val() { echo "$1" | grep -i "^$2:" | head -1 | cut -d: -f2- | sed 's/^ //'; }

echo "Scanning $PORTAL ..."

# ── 1. Transport (TLS) ─────────────────────────────────────────────────────
for proto in tls1 tls1_1; do
    if echo | openssl s_client -connect "$HOST:443" -servername "$HOST" -"$proto" >/dev/null 2>&1; then
        row FAIL "TLS" "Old protocol $proto refused" "Server accepts $proto; allow TLS 1.2+ only"
    else
        row PASS "TLS" "Old protocol $proto refused" "refused"
    fi
done
if echo | openssl s_client -connect "$HOST:443" -servername "$HOST" -tls1_2 >/dev/null 2>&1; then
    row PASS "TLS" "TLS 1.2 supported" "ok"
else
    row WARN "TLS" "TLS 1.2 supported" "TLS 1.2 handshake failed (check with SSL Labs)"
fi
EXPIRY=$(echo | openssl s_client -connect "$HOST:443" -servername "$HOST" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
if [ -n "$EXPIRY" ]; then
    EXP_TS=$(date -j -f "%b %e %T %Y %Z" "$EXPIRY" +%s 2>/dev/null || date -d "$EXPIRY" +%s 2>/dev/null || echo 0)
    DAYS=$(( (EXP_TS - $(date +%s)) / 86400 ))
    if [ "$DAYS" -gt 14 ]; then row PASS "TLS" "Certificate valid" "expires $EXPIRY ($DAYS days)"
    else row FAIL "TLS" "Certificate valid" "expires $EXPIRY ($DAYS days)"; fi
else
    row FAIL "TLS" "Certificate valid" "could not read certificate"
fi
HTTP_CODE=$(status "http://$HOST/")
HTTP_LOC=$(header_val "$(headers "http://$HOST/")" location)
if [[ "$HTTP_CODE" =~ ^30[1278]$ && "$HTTP_LOC" == https://* ]]; then
    row PASS "TLS" "http:// redirects to https://" "$HTTP_CODE → $HTTP_LOC"
else
    row FAIL "TLS" "http:// redirects to https://" "got $HTTP_CODE ${HTTP_LOC:-}"
fi

# ── 2. Security headers ────────────────────────────────────────────────────
for target in "$PORTAL/login" "$PAY/legal/terms" "$API/v1/ping"; do
    H=$(headers "$target")
    name=${target#https://}
    for h in Strict-Transport-Security X-Content-Type-Options X-Frame-Options Referrer-Policy; do
        v=$(header_val "$H" "$h")
        if [ -n "$v" ]; then row PASS "Headers" "$h on $name" "$v"; else row FAIL "Headers" "$h on $name" "missing"; fi
    done
    for h in X-Powered-By; do
        v=$(header_val "$H" "$h")
        if [ -z "$v" ]; then row PASS "Headers" "No $h on $name" "hidden"; else row WARN "Headers" "No $h on $name" "$v"; fi
    done
    srv=$(header_val "$H" server)
    if echo "$srv" | grep -qE '[0-9]+\.[0-9]+'; then row WARN "Headers" "Server version hidden on $name" "$srv"; else row PASS "Headers" "Server version hidden on $name" "${srv:-none}"; fi
done
H=$(headers "$PORTAL/login")
v=$(header_val "$H" content-security-policy)
if [ -n "$v" ]; then row PASS "Headers" "Content-Security-Policy on portal" "$v"; else row FAIL "Headers" "Content-Security-Policy on portal" "missing"; fi

# ── 3. Cookies ─────────────────────────────────────────────────────────────
COOKIES=$(echo "$H" | grep -i '^set-cookie:')
if [ -z "$COOKIES" ]; then
    row WARN "Cookies" "Session cookie flags" "no cookie set on /login"
else
    while IFS= read -r c; do
        n=$(echo "$c" | sed -E 's/^[Ss]et-[Cc]ookie: ([^=]+)=.*/\1/')
        miss=""
        echo "$c" | grep -qi 'secure' || miss+=" Secure"
        echo "$c" | grep -qi 'samesite' || miss+=" SameSite"
        if [[ "$n" != XSRF-TOKEN ]]; then echo "$c" | grep -qi 'httponly' || miss+=" HttpOnly"; fi
        if [ -z "$miss" ]; then row PASS "Cookies" "Cookie $n flags" "Secure, HttpOnly/SameSite set"
        else row FAIL "Cookies" "Cookie $n flags" "missing:$miss"; fi
    done <<< "$COOKIES"
fi

# ── 4. Exposed files & admin tools ─────────────────────────────────────────
for path in /.env /.env.example /.git/config /.git/HEAD /composer.json /composer.lock /package.json \
            /storage/logs/laravel.log /vendor/composer/installed.json /phpinfo.php /info.php \
            /server-status /adminer.php /adminer /telescope /_ignition/health-check /docker-compose.yml /compose.yaml; do
    code=$(status "$PORTAL$path")
    if [[ "$code" == 200 ]]; then row FAIL "Exposure" "$path not public" "HTTP 200 — publicly readable"
    else row PASS "Exposure" "$path not public" "HTTP $code"; fi
done
code=$(status "$PORTAL/horizon")
if [[ "$code" == 200 ]]; then row FAIL "Exposure" "/horizon needs login" "HTTP 200 without login"
else row PASS "Exposure" "/horizon needs login" "HTTP $code"; fi
for path in /admin /admin/dashboard /partner /branch /admin/qa-checklist; do
    code=$(status "$PORTAL$path")
    if [[ "$code" == 200 ]]; then row FAIL "Access" "$path needs login" "HTTP 200 without login"
    else row PASS "Access" "$path needs login" "HTTP $code"; fi
done

# ── 5. Error handling / debug leaks ────────────────────────────────────────
BODY=$(curl -s -A "$UA" --max-time 20 "$PORTAL/this-page-does-not-exist-$(date +%s)")
if echo "$BODY" | grep -qiE 'Whoops|Stack trace|vendor/laravel|Ignition|SQLSTATE|APP_KEY'; then
    row FAIL "Errors" "404 page leaks no debug info" "debug output visible"
else row PASS "Errors" "404 page leaks no debug info" "clean"; fi
BODY=$(curl -s -A "$UA" --max-time 20 -X POST -H 'Content-Type: application/json' -d '{bad json' "$API/v1/payins")
if echo "$BODY" | grep -qiE 'Stack trace|vendor/|SQLSTATE|\.php'; then
    row FAIL "Errors" "API errors leak no internals" "$(echo "$BODY" | head -c 150)"
else row PASS "Errors" "API errors leak no internals" "$(echo "$BODY" | head -c 120)"; fi
code=$(status -X TRACE "$PORTAL/")
if [[ "$code" == 200 ]]; then row FAIL "Errors" "TRACE method disabled" "HTTP 200"; else row PASS "Errors" "TRACE method disabled" "HTTP $code"; fi

# ── 6. Partner API authentication ──────────────────────────────────────────
code=$(status "$API/v1/ping")
[[ "$code" == 200 ]] && row PASS "API" "Health endpoint answers" "HTTP 200" || row WARN "API" "Health endpoint answers" "HTTP $code"
code=$(status -X POST -H 'Content-Type: application/json' -d '{}' "$API/v1/payins")
[[ "$code" == 401 ]] && row PASS "API" "Unsigned request refused" "HTTP 401" || row FAIL "API" "Unsigned request refused" "HTTP $code"
code=$(status -H "X-Key-Id: pk_live_scan" -H "X-Timestamp: $(date +%s)" -H "X-Nonce: scan$(date +%s)abcdefgh" -H "X-Signature: deadbeef" "$API/v1/balance")
[[ "$code" == 401 ]] && row PASS "API" "Forged signature refused" "HTTP 401" || row FAIL "API" "Forged signature refused" "HTTP $code"
locked=""
for i in $(seq 1 35); do
    c=$(status -X POST -H 'Content-Type: application/json' -d '{}' "$API/v1/payins")
    if [[ "$c" == 429 ]]; then locked=$i; break; fi
done
[ -n "$locked" ] && row PASS "API" "Brute-force lockout" "429 after $locked failed attempts" || row FAIL "API" "Brute-force lockout" "no 429 after 35 failed attempts"
CORS=$(header_val "$(headers -H 'Origin: https://evil.example' "$API/v1/ping")" access-control-allow-origin)
if [[ "$CORS" == "*" || "$CORS" == "https://evil.example" ]]; then row WARN "API" "CORS not open to any site" "Access-Control-Allow-Origin: $CORS"
else row PASS "API" "CORS not open to any site" "${CORS:-not sent}"; fi

# ── 7. Public checkout ─────────────────────────────────────────────────────
code=$(status "$PAY/p/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")
[[ "$code" == 404 ]] && row PASS "Checkout" "Random payment token rejected" "HTTP 404" || row FAIL "Checkout" "Random payment token rejected" "HTTP $code"
code=$(status "$PAY/p/'%20OR%201=1--")
[[ "$code" =~ ^(404|400)$ ]] && row PASS "Checkout" "Injection in token rejected" "HTTP $code" || row FAIL "Checkout" "Injection in token rejected" "HTTP $code"
limited=""
for i in $(seq 1 70); do
    c=$(status "$PAY/p/BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB")
    if [[ "$c" == 429 ]]; then limited=$i; break; fi
done
[ -n "$limited" ] && row PASS "Checkout" "Token guessing rate-limited" "429 after $limited requests" || row WARN "Checkout" "Token guessing rate-limited" "no 429 within 70 requests/min"

# ── 8. Login page ──────────────────────────────────────────────────────────
LOGIN=$(curl -s -A "$UA" --max-time 20 "$PORTAL/login")
if echo "$LOGIN" | grep -qiE 'csrf|XSRF|_token' || echo "$H" | grep -qi 'XSRF-TOKEN'; then
    row PASS "Login" "CSRF protection present" "token issued"
else row WARN "Login" "CSRF protection present" "no CSRF token seen"; fi
code=$(status -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"email":"a@b.c","password":"x"}' "$PORTAL/login")
[[ "$code" =~ ^(419|422|302)$ ]] && row PASS "Login" "Login POST without CSRF token refused" "HTTP $code" || row WARN "Login" "Login POST without CSRF token refused" "HTTP $code"

# ── 9. Performance (light load) ────────────────────────────────────────────
PERF=""
SERVER=""
if command -v ab >/dev/null; then
    for target in "$API/v1/ping" "$PORTAL/login" "$PAY/legal/terms"; do
        OUT=$(ab -q -n "$LOAD_REQUESTS" -c "$LOAD_CONCURRENCY" -H "User-Agent: $UA" "$target" 2>&1)
        rps=$(echo "$OUT" | awk '/Requests per second/ {print $4}')
        failed=$(echo "$OUT" | awk '/Failed requests/ {print $3}')
        non2xx=$(echo "$OUT" | awk '/Non-2xx responses/ {print $3}')
        p50=$(echo "$OUT" | awk '$1=="50%" {print $2}')
        p95=$(echo "$OUT" | awk '$1=="95%" {print $2}')
        p99=$(echo "$OUT" | awk '$1=="99%" {print $2}')
        PERF+="| ${target#https://} | $LOAD_REQUESTS | $LOAD_CONCURRENCY | ${rps:-?} | ${p50:-?} | ${p95:-?} | ${p99:-?} | ${failed:-?} | ${non2xx:-0} |"$'\n'
        # Server time = first byte minus the TLS handshake (one network round trip remains in it).
        srv=$(for i in 1 2 3 4 5; do curl -s -o /dev/null -A "$UA" -w '%{time_appconnect} %{time_starttransfer} %{time_connect}\n' "$target"; done \
            | awk '{s+=($2-$1)*1000; r+=$3*1000} END {printf "%.0f %.0f", s/NR, r/NR}')
        SERVER+="| ${target#https://} | ${srv% *} | ${srv#* } |"$'\n'
        if [[ -n "$p95" && "$p95" -le 1000 ]]; then row PASS "Performance" "p95 under 1 s: ${target#https://}" "${p95} ms"
        else row WARN "Performance" "p95 under 1 s: ${target#https://}" "${p95:-?} ms"; fi
    done
fi

# ── Report ─────────────────────────────────────────────────────────────────
{
    echo "# PayGate — External Security & Performance Scan"
    echo
    echo "| | |"
    echo "|---|---|"
    echo "| Target | $PORTAL (API: $API, Pay: $PAY) |"
    echo "| Date | $(date '+%Y-%m-%d %H:%M %Z') |"
    echo "| Tool | scripts/security-scan.sh (curl, openssl, ab) — read-only, no login, no data created |"
    echo "| Result | **$PASS passed, $FAIL failed, $WARN warnings** |"
    echo
    echo "## Results"
    echo
    echo "| Result | Area | Check | Detail |"
    echo "|---|---|---|---|"
    printf '%s' "$ROWS"
    if [ -n "$PERF" ]; then
        echo
        echo "## Performance (light load)"
        echo
        echo "Times in milliseconds, measured from the scanning machine (includes its network latency)."
        echo
        echo "| URL | Requests | Concurrency | Req/s | p50 | p95 | p99 | Failed | Non-2xx |"
        echo "|---|---|---|---|---|---|---|---|---|"
        printf '%s' "$PERF"
        echo
        echo "Each Apache Bench request opens a new HTTPS connection (TCP + TLS = about 3 network round trips), so the times above are mostly distance between the scanning machine and the server. The table below separates the two (average of 5 requests):"
        echo
        echo "| URL | Request after connect, ms (server work + 1 round trip) | Network round trip (TCP connect), ms |"
        echo "|---|---|---|"
        printf '%s' "$SERVER"
    fi
} > "$REPORT"

echo "Done: $PASS passed, $FAIL failed, $WARN warnings → $REPORT"
[ "$FAIL" -eq 0 ]
