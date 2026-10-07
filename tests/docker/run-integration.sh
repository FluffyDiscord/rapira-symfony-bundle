#!/usr/bin/env bash
# End-to-end integration tests (IT-101..IT-109) for the Rapira bundle. Builds the integration
# image, then drives the real Rapira binary over HTTP and asserts behaviour. Requires docker,
# curl and python3 on the host; the code under test runs entirely inside the container.
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
IMAGE="rapira-bundle-integration"
PORT="${RAPIRA_IT_PORT:-8300}"
BASE="http://127.0.0.1:${PORT}"
CID=""
PASS=0
FAIL=0

log()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[32mPASS\033[0m %s\n' "$*"; PASS=$((PASS + 1)); }
bad()  { printf '  \033[31mFAIL\033[0m %s\n' "$*"; FAIL=$((FAIL + 1)); }

cleanup() { [ -n "$CID" ] && docker rm -f "$CID" >/dev/null 2>&1; CID=""; }
trap cleanup EXIT

start() {
    cleanup
    CID=$(docker run -d -p "127.0.0.1:${PORT}:8000" "$@" "$IMAGE")
}

wait_health() {
    for _ in $(seq 1 40); do
        code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/health" 2>/dev/null || true)
        [ "$code" = "201" ] && return 0
        sleep 0.25
    done
    return 1
}

log "Building integration image"
if ! docker build -q -f "$ROOT/tests/docker/integration.Dockerfile" -t "$IMAGE" "$ROOT" >/dev/null; then
    echo "image build failed"; exit 1
fi

# ---------------------------------------------------------------------------
log "IT-101  dispatcher boots, serves N requests on one worker, env precedence"
start -e RAPIRA_TEST_MARKER=from-env-runtime
if wait_health; then
    grep -q 'rapira dispatcher worker started' <(docker logs "$CID" 2>&1) && ok "logged dispatcher worker start" || bad "no dispatcher worker-start log line"

    for _ in 1 2 3; do curl -s -o /dev/null "${BASE}/" || true; done

    codes=""; pids=""
    for _ in $(seq 1 200); do
        resp=$(curl -s -w '\n%{http_code}' "${BASE}/")
        code=$(printf '%s' "$resp" | tail -1)
        # 000 is a client/connection-level transient (no HTTP response); retry once so the
        # assertion only fails on a real HTTP error, which is what a worker crash produces.
        [ "$code" = "000" ] && code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/")
        codes="${codes}${code} "
        pids="${pids}$(printf '%s' "$resp" | head -1 | sed -n 's/.*pid=\([0-9]*\).*/\1/p') "
    done
    offending=$(echo "$codes" | tr ' ' '\n' | grep -v '^200$' | grep -v '^$' | sort | uniq -c | tr '\n' ' ')
    [ -z "$offending" ] && ok "200 sequential requests all 200" || bad "non-200 responses: ${offending}"
    distinct_pids=$(echo "$pids" | tr ' ' '\n' | sort -u | grep -c '[0-9]' || true)
    [ "$distinct_pids" = "1" ] && ok "served by a single worker pid" || bad "expected 1 worker pid, saw $distinct_pids"

    marker=$(curl -s "${BASE}/" | sed -n 's/.*marker=\([^ ]*\).*/\1/p')
    [ "$marker" = "from-env-runtime" ] && ok "docker -e value wins over .env.local.php (EGPCS)" || bad "marker was '$marker', expected from-env-runtime"

    worker=$(curl -s "${BASE}/" | sed -n 's/.*worker=\([01]\).*/\1/p')
    [ "$worker" = "1" ] && ok "kernel.runtime_mode.worker on despite APP_RUNTIME_MODE=web=1 in .env.local.php" || bad "worker was '$worker', expected 1"
else
    bad "dispatcher container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-101b GPCS serves in prod, process env still wins over .env.local.php"
start -e VARIABLES_ORDER=GPCS -e RAPIRA_TEST_MARKER=from-env-gpcs
if wait_health; then
    code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/")
    body=$(curl -s "${BASE}/")
    { [ "$code" = "200" ] && echo "$body" | grep -q 'OK marker=from-env-gpcs'; } \
        && ok "GPCS serves 200 with the docker -e value" || bad "GPCS did not serve cleanly: $code $body"
else
    bad "GPCS container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-102  classic-mode fallback serves identical responses"
start -e RAPIRA_CONFIG=/app/rapira-classic.toml -e RAPIRA_TEST_MARKER=classic-marker
if wait_health; then
    body=$(curl -s "${BASE}/")
    echo "$body" | grep -q 'OK marker=classic-marker' && ok "classic mode serves via parent runner" || bad "classic response wrong: $body"
else
    bad "classic container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-103  throwable after head: worker survives, next request fine"
start -e RAPIRA_TEST_MARKER=m
if wait_health; then
    pid_before=$(curl -s "${BASE}/" | sed -n 's/.*pid=\([0-9]*\).*/\1/p')
    curl -s -o /dev/null "${BASE}/boom-after-head" || true
    sleep 0.3
    after=$(curl -s "${BASE}/")
    pid_after=$(echo "$after" | sed -n 's/.*pid=\([0-9]*\).*/\1/p')
    echo "$after" | grep -q 'OK marker=' && ok "next request after mid-stream throw is 200" || bad "worker did not recover: $after"
    [ -n "$pid_before" ] && [ "$pid_before" = "$pid_after" ] && ok "worker pid unchanged ($pid_after)" || bad "pid changed $pid_before -> $pid_after"
else
    bad "container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-104  multipart upload: small ok, over-limit 413"
start
if wait_health; then
    small=$(mktemp); dd if=/dev/zero of="$small" bs=1024 count=300 status=none
    big=$(mktemp);   dd if=/dev/zero of="$big"   bs=1024 count=2048 status=none
    resp=$(curl -s -F "file=@${small}" "${BASE}/upload")
    echo "$resp" | grep -q 'size=307200' && ok "300 KB upload received and moved" || bad "small upload wrong: $resp"
    code=$(curl -s -o /dev/null -w '%{http_code}' -F "file=@${big}" "${BASE}/upload")
    [ "$code" = "413" ] && ok "2 MB over max_file_size_mb=1 -> 413" || bad "over-limit upload returned $code, expected 413"
    rm -f "$small" "$big"
else
    bad "container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-105  sessions persist per client; raw JSON body reaches getContent()"
start
if wait_health; then
    jar=$(mktemp)
    curl -s -c "$jar" "${BASE}/session/set/hello" >/dev/null
    got=$(curl -s -b "$jar" "${BASE}/session/get")
    [ "$got" = "marker:hello" ] && ok "session survives across requests for one client" || bad "session get was '$got'"
    anon=$(curl -s "${BASE}/session/get")
    [ "$anon" = "marker:anonymous" ] && ok "fresh client has no session" || bad "anon client saw '$anon'"
    echoed=$(curl -s -H 'content-type: application/json' -d '{"name":"Rick"}' "${BASE}/echo-json")
    [ "$echoed" = '{"name":"Rick"}' ] && ok "raw JSON body reaches getContent()" || bad "echo-json was '$echoed'"
    rm -f "$jar"
else
    bad "container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-108  sessions never leak between clients on one worker"
start
if wait_health; then
    jar_a=$(mktemp)
    jar_b=$(mktemp)
    anon_headers=$(mktemp)

    anonymous_sees_nothing() {
        anon=$(curl -s -D "$anon_headers" "${BASE}/session/get")
        leaked_cookie=$(grep -i '^set-cookie:' "$anon_headers" | grep -e "$id_a" -e "$id_b")
        [ "$anon" = "marker:anonymous" ] && [ -z "$leaked_cookie" ] && ok "$1" || bad "$1: body '$anon', cookie '$leaked_cookie'"
    }

    curl -s -c "$jar_a" "${BASE}/session/set/alpha" >/dev/null
    curl -s -c "$jar_b" "${BASE}/session/set/beta" >/dev/null
    id_a=$(awk '$6 == "PHPSESSID" { print $7 }' "$jar_a")
    id_b=$(awk '$6 == "PHPSESSID" { print $7 }' "$jar_b")
    [ -n "$id_a" ] && [ -n "$id_b" ] && [ "$id_a" != "$id_b" ] && ok "each client gets its own session id" || bad "session ids a='$id_a' b='$id_b'"

    got_a=$(curl -s -b "$jar_a" "${BASE}/session/get")
    got_b=$(curl -s -b "$jar_b" "${BASE}/session/get")
    [ "$got_a" = "marker:alpha" ] && [ "$got_b" = "marker:beta" ] && ok "interleaved clients read only their own session" || bad "a read '$got_a', b read '$got_b'"

    curl -s -b "$jar_a" "${BASE}/session/get" >/dev/null
    anonymous_sees_nothing "anonymous request right after a session request sees no session"

    forged=$(curl -s -b "PHPSESSID=forgedsessionid00000000000000" "${BASE}/session/get")
    [ "$forged" = "marker:anonymous" ] && ok "unknown session id sees no data" || bad "forged id read '$forged'"

    curl -s -b "$jar_a" "${BASE}/session/echo-set/alpha2" >/dev/null
    anonymous_sees_nothing "anonymous request after stray echo output sees no session"

    curl -s -b "$jar_a" "${BASE}/session/stream-boom" >/dev/null
    anonymous_sees_nothing "anonymous request after a crashed session request sees no session"

    got_a=$(curl -s -b "$jar_a" "${BASE}/session/get")
    [ "$got_a" = "marker:alpha2" ] && ok "client session still works after the crash" || bad "a read '$got_a' after the crash"

    rm -f "$jar_a" "$jar_b" "$anon_headers"
else
    bad "container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-106  StreamedResponse streams progressively"
start
if wait_health; then
    stream_result=$(python3 - "$PORT" <<'PY'
import socket, sys, time
port = int(sys.argv[1])
s = socket.create_connection(("127.0.0.1", port), timeout=10)
s.sendall(b"GET /stream HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
start = time.time(); first=None; marks=[]
while True:
    try: data = s.recv(4096)
    except socket.timeout: break
    if not data: break
    t = time.time() - start
    if first is None: first = t
    if b"chunk-" in data: marks.append(round(t, 3))
s.close()
ttfb_ok = first is not None and first < 0.5
spread_ok = len(marks) >= 2 and (marks[-1] - marks[0]) >= 0.4
print(("OKSTREAM" if (ttfb_ok and spread_ok) else "BADSTREAM") + " first=%.3f marks=%s" % (first if first is not None else -1, marks))
PY
)
    echo "$stream_result" | grep -q OKSTREAM && ok "chunks arrive progressively ($stream_result)" || bad "not progressive ($stream_result)"
else
    bad "container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-107  graceful drain: in-flight request completes on docker stop"
start
if wait_health; then
    tmp=$(mktemp)
    ( curl -s -m 30 "${BASE}/slow" > "$tmp" 2>&1; echo "DONE:$?" >> "$tmp" ) &
    slow_pid=$!
    sleep 1
    t0=$(date +%s)
    docker stop -t 20 "$CID" >/dev/null 2>&1
    t1=$(date +%s)
    wait "$slow_pid" 2>/dev/null || true
    body=$(cat "$tmp")
    echo "$body" | grep -q 'slow-done' && ok "in-flight request completed during drain" || bad "slow request lost during drain: $body"
    [ $((t1 - t0)) -lt 15 ] && ok "drain returned in $((t1 - t0))s (before the 20s deadline)" || bad "drain took $((t1 - t0))s"
    rm -f "$tmp"; docker rm -f "$CID" >/dev/null 2>&1; CID=""
else
    bad "container never became healthy"
fi

# ---------------------------------------------------------------------------
# IT-109: one long-lived database connection per worker, replaced when the server drops it.
DB_NET="rapira-it-db"
DB_CID=""
DB_KIND=""

db_cleanup() {
    [ -n "$DB_CID" ] && docker rm -f "$DB_CID" >/dev/null 2>&1
    DB_CID=""
    docker network rm "$DB_NET" >/dev/null 2>&1 || true
}
trap 'cleanup; db_cleanup' EXIT

db_sql() {
    if [ "$DB_KIND" = "pgsql" ]; then
        docker exec "$DB_CID" psql -U app -d app -tAc "$1" 2>/dev/null
    else
        docker exec "$DB_CID" mariadb -uroot -proot -N -e "$1" 2>/dev/null
    fi
}

start_db() {
    DB_KIND="$1"
    db_cleanup
    docker network create "$DB_NET" >/dev/null
    if [ "$DB_KIND" = "pgsql" ]; then
        DB_CID=$(docker run -d --network "$DB_NET" --network-alias db \
            -e POSTGRES_USER=app -e POSTGRES_PASSWORD=app -e POSTGRES_DB=app postgres:17-alpine)
    else
        DB_CID=$(docker run -d --network "$DB_NET" --network-alias db \
            -e MARIADB_ROOT_PASSWORD=root -e MARIADB_USER=app -e MARIADB_PASSWORD=app -e MARIADB_DATABASE=app mariadb:11)
    fi
    # The images restart the server once after init; wait for a query to succeed twice in a row.
    for _ in $(seq 1 90); do
        db_sql "SELECT 1" >/dev/null && sleep 1 && db_sql "SELECT 1" >/dev/null && break
        sleep 1
    done
    if [ "$DB_KIND" = "pgsql" ]; then
        db_sql "CREATE DATABASE sessions" >/dev/null
        docker exec "$DB_CID" psql -U app -d sessions -tAc "CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL PRIMARY KEY, sess_data BYTEA NOT NULL, sess_lifetime INTEGER NOT NULL, sess_time INTEGER NOT NULL)" >/dev/null
    else
        db_sql "CREATE DATABASE sessions; GRANT ALL ON sessions.* TO 'app'@'%'; CREATE TABLE sessions.sessions (sess_id VARBINARY(128) NOT NULL PRIMARY KEY, sess_data LONGBLOB NOT NULL, sess_lifetime INTEGER UNSIGNED NOT NULL, sess_time INTEGER UNSIGNED NOT NULL) COLLATE utf8mb4_bin, ENGINE = InnoDB" >/dev/null
    fi
}

session_backends() {
    if [ "$DB_KIND" = "pgsql" ]; then
        db_sql "SELECT pid FROM pg_stat_activity WHERE datname = 'sessions' ORDER BY pid" | tr '\n' ' '
    else
        db_sql "SELECT ID FROM information_schema.PROCESSLIST WHERE DB = 'sessions' ORDER BY ID" | tr '\n' ' '
    fi
}

kill_backend() {
    if [ "$DB_KIND" = "pgsql" ]; then
        db_sql "SELECT pg_terminate_backend($1)" >/dev/null
    else
        db_sql "KILL $1" >/dev/null
    fi
}

kill_session_backends() {
    for backend in $(session_backends); do kill_backend "$backend"; done
    sleep 0.5
}

db_backend() { curl -s "${BASE}/db/backend" | sed -n 's/^backend:\([0-9]*\)$/\1/p'; }

session_id() { awk '$6 == "PHPSESSID" { print $7 }' "$1"; }

held_advisory_locks() {
    if [ "$DB_KIND" = "pgsql" ]; then
        db_sql "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND granted"
    else
        db_sql "SELECT COUNT(IS_USED_LOCK('$1'))"
    fi
}

rename_sessions_table() {
    if [ "$DB_KIND" = "pgsql" ]; then
        docker exec "$DB_CID" psql -U app -d sessions -tAc "ALTER TABLE $1 RENAME TO $2" >/dev/null
    else
        db_sql "RENAME TABLE sessions.$1 TO sessions.$2" >/dev/null
    fi
}

advisory_lock_suite() {
    local jar="$1" tmp="$2" sid
    sid=$(session_id "$jar")

    ( curl -s -b "$jar" "${BASE}/session/slow-set/gamma" > "$tmp" ) &
    slow_pid=$!
    sleep 1
    during=$(held_advisory_locks "$sid")
    wait "$slow_pid" 2>/dev/null || true
    after=$(held_advisory_locks "$sid")
    [ "$during" = "1" ] && [ "$after" = "0" ] && ok "advisory lock held during the request, released after it" || bad "advisory locks during=$during after=$after"

    ( curl -s -b "$jar" -w '\n%{http_code}' "${BASE}/session/slow-set/delta" > "$tmp" ) &
    slow_pid=$!
    sleep 1
    rename_sessions_table sessions sessions_off
    wait "$slow_pid" 2>/dev/null || true
    rename_sessions_table sessions_off sessions
    failed_code=$(tail -1 "$tmp")
    leftover=$(held_advisory_locks "$sid")
    [ "$failed_code" = "500" ] && [ "$leftover" = "0" ] && ok "failed session write on a live connection leaves no advisory lock behind" || bad "after failed write: code=$failed_code leftover locks=$leftover"

    rename_sessions_table sessions sessions_off
    failed_code=$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" "${BASE}/session/get")
    leftover=$(held_advisory_locks "$sid")
    rename_sessions_table sessions_off sessions
    [ "$failed_code" = "500" ] && [ "$leftover" = "0" ] && ok "failed session read on a live connection leaves no advisory lock behind" || bad "after failed read: code=$failed_code leftover locks=$leftover"

    got=$(curl -s -m 5 -b "$jar" "${BASE}/session/get")
    [ "$got" = "marker:gamma" ] && ok "session usable after the failed read and write" || bad "after failed read and write read '$got'"
}

db_suite() {
    local kind="$1" app_env="$2" database_url="$3" session_dsn="$4"
    log "IT-109  ${kind} (${app_env}): one long-lived Doctrine and session connection, replaced when the server drops it"
    start_db "$kind"
    start --network "$DB_NET" -e APP_ENV="$app_env" -e DATABASE_URL="$database_url" -e SESSION_DSN="$session_dsn"
    if ! wait_health; then
        bad "container never became healthy"
        docker logs "$CID" 2>&1 | tail -20
        return
    fi

    b1=$(db_backend); b2=$(db_backend); b3=$(db_backend)
    [ -n "$b1" ] && [ "$b1" = "$b2" ] && [ "$b2" = "$b3" ] && ok "Doctrine keeps one connection across requests ($b1)" || bad "Doctrine backends '$b1' '$b2' '$b3'"

    kill_backend "$b3"; sleep 0.5
    resp=$(curl -s -w '\n%{http_code}' "${BASE}/db/backend")
    code=$(printf '%s' "$resp" | tail -1)
    b4=$(printf '%s' "$resp" | head -1 | sed -n 's/^backend:\([0-9]*\)$/\1/p')
    [ "$code" = "200" ] && [ -n "$b4" ] && [ "$b4" != "$b3" ] && ok "killed Doctrine connection replaced before the request ($b3 -> $b4)" || bad "after kill: code=$code backend='$b4' resp='$resp'"

    jar=$(mktemp)
    curl -s -c "$jar" "${BASE}/session/set/alpha" >/dev/null
    s1=$(session_backends)
    curl -s -b "$jar" "${BASE}/session/get" >/dev/null
    curl -s -b "$jar" "${BASE}/session/get" >/dev/null
    s2=$(session_backends)
    [ "$(echo "$s1" | wc -w)" = "1" ] && [ "$s1" = "$s2" ] && ok "session keeps one connection across requests ($s1)" || bad "session backends '$s1' then '$s2'"

    kill_session_backends
    got=$(curl -s -b "$jar" "${BASE}/session/get")
    [ "$got" = "marker:alpha" ] && ok "session read survives a killed connection" || bad "after kill read '$got'"

    tmp=$(mktemp)
    ( curl -s -b "$jar" -w '\n%{http_code}' "${BASE}/session/slow-set/beta" > "$tmp" ) &
    slow_pid=$!
    sleep 1
    kill_session_backends
    wait "$slow_pid" 2>/dev/null || true
    slow_code=$(tail -1 "$tmp")
    got=$(curl -s -b "$jar" "${BASE}/session/get")
    [ "$slow_code" = "200" ] && [ "$got" = "marker:beta" ] && ok "session write survives a connection killed mid-request" || bad "mid-request kill: code=$slow_code then read '$got'"

    s3=$(session_backends)
    [ "$(echo "$s3" | wc -w)" = "1" ] && ok "still one session connection after reconnects ($s3)" || bad "session backends after reconnects '$s3'"

    if [ "$app_env" = "db_advisory" ]; then
        advisory_lock_suite "$jar" "$tmp"
    fi

    if [ "$FAIL" != "0" ]; then docker logs "$CID" 2>&1 | tail -20; fi
    rm -f "$jar" "$tmp"
    cleanup
    db_cleanup
}

PG_DATABASE_URL="postgresql://app:app@db:5432/app?serverVersion=17&charset=utf8"
PG_SESSION_DSN="postgresql://app:app@db:5432/sessions"
MARIA_DATABASE_URL="mysql://app:app@db:3306/app?serverVersion=11.8.2-MariaDB&charset=utf8mb4"
MARIA_SESSION_DSN="mysql://app:app@db:3306/sessions"

db_suite pgsql db "$PG_DATABASE_URL" "$PG_SESSION_DSN"
db_suite mariadb db "$MARIA_DATABASE_URL" "$MARIA_SESSION_DSN"
db_suite pgsql db_advisory "$PG_DATABASE_URL" "$PG_SESSION_DSN"
db_suite mariadb db_advisory "$MARIA_DATABASE_URL" "$MARIA_SESSION_DSN"

# ---------------------------------------------------------------------------
log "Integration summary: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" = "0" ]
