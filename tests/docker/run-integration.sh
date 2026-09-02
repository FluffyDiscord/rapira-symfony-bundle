#!/usr/bin/env bash
# End-to-end integration tests (IT-101..IT-107) for the Rapira bundle. Builds the integration
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
else
    bad "dispatcher container never became healthy"
fi

# ---------------------------------------------------------------------------
log "IT-101b GPCS still serves in prod (usePutenv survives the mid-request \$_ENV re-import)"
start -e VARIABLES_ORDER=GPCS
if wait_health; then
    code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/")
    body=$(curl -s "${BASE}/")
    { [ "$code" = "200" ] && echo "$body" | grep -q 'OK marker='; } \
        && ok "GPCS serves 200 in prod (usePutenv, no EGPCS requirement)" || bad "GPCS did not serve cleanly: $code $body"
else
    bad "GPCS container never became healthy (usePutenv should make variables_order irrelevant)"
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
log "Integration summary: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" = "0" ]
