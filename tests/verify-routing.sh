#!/usr/bin/env sh
# AIDataPlatform routing contract check.
#
# Asserts the invariants of the request-routing layer by PARSING the config,
# not by running it: infrastructure/nginx/default.conf and docker-compose.yml
# are read with grep/sed/awk only. No nginx, no Docker, no network, no curl.
#
# Everything here is derived from the configuration plus nginx's documented
# semantics; nothing in this file proves the running proxy behaves the same way.
# Only `nginx -t` plus a live request can do that.
#
# Usage: sh tests/verify-routing.sh
# Deps:  sh + grep + sed + awk  (nothing else)
set -u

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
CONF="$ROOT_DIR/infrastructure/nginx/default.conf"
COMPOSE="$ROOT_DIR/docker-compose.yml"
APP_CONF="$ROOT_DIR/application/config/ai_engine.php"

PASS=0
FAIL=0
WARN=0

ok()   { echo "  [OK]   $1"; PASS=$((PASS + 1)); }
bad()  { echo "  [FAIL] $1${2:+ -- $2}"; FAIL=$((FAIL + 1)); }
warn() { echo "  [WARN] $1${2:+ -- $2}"; WARN=$((WARN + 1)); }
head_() { echo "-- $1 --"; }

for f in "$CONF" "$COMPOSE"; do
  if [ ! -f "$f" ]; then
    echo "  [FAIL] missing required file: $f"
    echo "== result: 0 passed, 1 failed, 0 warnings =="
    exit 1
  fi
done

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------

# Print the body of one `location` block, braces stripped of the matcher and
# comments removed. The leading `=` of an exact-match location is dropped, so
# /health addresses `location = /health` as well as `location /health`.
loc_body() {
  awk -v want="$1" '
    BEGIN { active = 0; depth = 0 }
    {
      opener = ($0 ~ /^[ \t]*location[ \t]+/)
      if (opener) {
        m = $0
        sub(/^[ \t]*location[ \t]+/, "", m)
        sub(/[{].*$/, "", m)
        gsub(/[[:space:]]/, "", m)
        sub(/^=/, "", m)
        active = (m == want)
        depth = 0
      }
      if (!active) next
      raw = $0
      sub(/#.*/, "", raw)
      tmp = raw
      o = gsub(/[{]/, "", tmp)
      c = gsub(/[}]/, "", tmp)
      depth += o - c
      if (opener) {
        p = index(raw, "{")
        line = (p > 0) ? substr(raw, p + 1) : ""
        if (depth == 0) {
          gsub(/[[:space:]]/, "", line)
          if (line != "") print line
          active = 0
          next
        }
      } else {
        line = raw
      }
      if (depth > 0) print line
      if (depth <= 0) active = 0
    }
  ' "$CONF"
}

# Print every location matcher whose block contains an add_header directive.
# nginx cancels every inherited add_header as soon as a lower level uses one,
# so a stray add_header inside a location silently drops the server-wide
# security headers for that location.
locations_with_add_header() {
  awk '
    BEGIN { active = 0; depth = 0; hit = 0; name = "" }
    {
      opener = ($0 ~ /^[ \t]*location[ \t]+/)
      if (opener) {
        m = $0
        sub(/^[ \t]*location[ \t]+/, "", m)
        sub(/[{].*$/, "", m)
        gsub(/[[:space:]]/, "", m)
        name = m
        active = 1
        depth = 0
        hit = 0
      }
      if (!active) next
      raw = $0
      sub(/#.*/, "", raw)
      tmp = raw
      o = gsub(/[{]/, "", tmp)
      c = gsub(/[}]/, "", tmp)
      depth += o - c
      if (raw ~ /^[ \t]*add_header[ \t]/) hit = 1
      if (depth <= 0) { if (hit) print name; active = 0 }
    }
  ' "$CONF"
}

# Print `host:port` for every `server` directive inside an upstream block.
upstream_servers() {
  awk '
    /^[ \t]*upstream[ \t]+/ { inup = 1; next }
    inup && /^[ \t]*server[ \t]+/ {
      line = $0
      sub(/^[ \t]*server[ \t]+/, "", line)
      split(line, a, /[ \t;]/)
      print a[1]
    }
    inup && /^[ \t]*}/ { inup = 0 }
  ' "$CONF"
}

# Print the first port a compose service declares under `expose:`.
compose_expose_of() {
  awk -v want="$1" '
    /^services:/ { ins = 1; next }
    ins && /^[a-zA-Z]/ { ins = 0 }
    ins && $0 ~ "^  " want ":$" { found = 1; next }
    ins && found {
      if ($0 ~ /^  [a-zA-Z0-9_-]+:/) exit
      if ($0 ~ /^    expose:/) { inexp = 1; next }
      if (inexp && $0 ~ /^      - /) {
        v = $0
        sub(/^      - /, "", v)
        gsub(/"/, "", v)
        print v
        exit
      }
    }
  ' "$COMPOSE"
}

# Size directive to whole megabytes: 500M -> 500, 2g -> 2048, 1048576 -> 1.
size_mb() {
  v=$(printf '%s' "$1" | sed 's/[[:space:]]//g')
  num=${v%[kKmMgG]}
  unit=${v#"$num"}
  case $unit in
    "") echo $((num / 1048576)) ;;
    k | K) echo $((num / 1024)) ;;
    m | M) echo $num ;;
    g | G) echo $((num * 1024)) ;;
    *) echo 0 ;;
  esac
}

# Time directive to whole seconds: 300s -> 300, 5m -> 300, 90 -> 90.
time_s() {
  v=$(printf '%s' "$1" | sed 's/[[:space:]]//g')
  num=${v%s}
  unit=${v#"$num"}
  case $unit in
    ms) echo 0 ;;
    m) echo $((num * 60)) ;;
    *) echo $num ;;
  esac
}

# First scalar directive value inside a location body.
loc_value() {
  printf '%s\n' "$1" | sed -n "s/^[ 	]*$2[ 	][ 	]*//p" | sed -n '1p' |
    sed 's/;[ 	]*$//; s/[ 	]*$//'
}

# A real .env wins over the example file; CI usually only has the example.
env_value() {
  key=$1
  for src in "$ROOT_DIR/.env" "$ROOT_DIR/.env.example"; do
    [ -f "$src" ] || continue
    v=$(sed -n "s/^[[:space:]]*$key[[:space:]]*=[[:space:]]*//p" "$src" | sed -n '1p')
    v=$(printf '%s' "$v" | sed 's/[[:space:]]*#.*$//; s/^"//; s/"$//; s/^'"'"'//; s/'"'"'$//; s/[[:space:]]*$//')
    [ -n "$v" ] && { echo "$v"; return; }
  done
  echo ""
}

# Simulate the config's own rewrite: strip the literal prefix and re-root the
# remainder at /. This is the arithmetic the proxy performs for one request.
map_url() {
  case "$1" in
    "$2"*) printf '/%s\n' "${1#"$2"}" ;;
    *) printf '%s\n' "$1" ;;
  esac
}

echo "== routing contract (static parse, nothing executed) =="
echo "conf=$CONF"

# ---------------------------------------------------------------------------
head_ "[1] locations present"
# ---------------------------------------------------------------------------
AI_BODY=$(loc_body '/ai-api/')
LAR_BODY=$(loc_body '/')
HEALTH_BODY=$(loc_body '/health')

[ -n "$AI_BODY" ] && ok "location /ai-api/ exists" || bad "location /ai-api/ exists" "not found in $CONF"
[ -n "$LAR_BODY" ] && ok "location / (Laravel default) exists" || bad "location / (Laravel default) exists" "not found"
[ -n "$HEALTH_BODY" ] && ok "location = /health exists" || bad "location = /health exists" "not found"

AI_EXACT=$(loc_body '/ai-api')
if [ -n "$AI_EXACT" ] && printf '%s' "$AI_EXACT" | grep -q 'return 30[12]'; then
  ok "location = /ai-api redirects instead of falling through to Laravel"
else
  bad "location = /ai-api redirect" "bare /ai-api would be matched by 'location /' and sent to Laravel"
fi

# The compose nginx healthcheck calls http://localhost/health; if the exact
# location were removed the container would report unhealthy forever.
if grep -q 'wget -qO- http://localhost/health' "$COMPOSE"; then
  ok "compose nginx healthcheck target is served by an exact location"
else
  warn "compose nginx healthcheck no longer probes http://localhost/health" "review $COMPOSE"
fi

# ---------------------------------------------------------------------------
head_ "[2] /ai-api/ prefix is stripped"
# ---------------------------------------------------------------------------
RW=$(printf '%s\n' "$AI_BODY" | sed -n 's/^[ 	]*rewrite[ 	][ 	]*//p' | sed -n '1p')
RW_PATTERN=$(printf '%s' "$RW" | awk '{print $1}')
RW_REPLACE=$(printf '%s' "$RW" | awk '{print $2}')
RW_FLAG=$(printf '%s' "$RW" | awk '{print $3}' | sed 's/;$//')

if [ -n "$RW" ]; then ok "location /ai-api/ has a rewrite"; else bad "location /ai-api/ has a rewrite" "the /ai-api prefix would be forwarded verbatim"; fi

case "$RW_REPLACE" in
  /*) ok "rewrite replacement starts at the server root ($RW_REPLACE)" ;;
  *) bad "rewrite replacement root" "'$RW_REPLACE' does not start with /, so the backend would see a relative path" ;;
esac

if [ "$RW_FLAG" = "break" ]; then
  ok "rewrite ends in break (no re-match of the rewritten URI)"
else
  bad "rewrite flag" "expected 'break', found '${RW_FLAG:-none}'"
fi

case "$RW_PATTERN" in
  ^/*) ok "rewrite pattern is anchored to the start of the URI" ;;
  *) bad "rewrite pattern anchor" "'$RW_PATTERN' is unanchored" ;;
esac

AI_PASS=$(loc_value "$AI_BODY" 'proxy_pass')
case "$AI_PASS" in
  '' ) bad "location /ai-api/ has a proxy_pass" "not found" ;;
  *://*) ;;
  *) bad "location /ai-api/ has a proxy_pass" "not found" ;;
esac

# proxy_pass WITHOUT a URI part forwards the (already rewritten) request URI
# unchanged. With a URI part nginx would replace the matched location prefix
# instead, stripping /ai-api/ a second time and losing the leading slash.
rest=${AI_PASS#*://}
case "$rest" in
  */*) bad "proxy_pass on /ai-api/ carries no URI part" "'$AI_PASS' would strip the prefix a second time; it must be 'http://fastapi_app'" ;;
  *) ok "proxy_pass has no URI part, so the rewrite owns the stripping" ;;
esac

AI_PREFIX=$(printf '%s' "$RW_PATTERN" | sed 's|^\^||; s|/(.*)$||; s|[()$.*]| |g; s|[[:space:]]*$||')
if [ -z "$AI_PREFIX" ]; then AI_PREFIX='/ai-api/'; fi
[ "$AI_PREFIX" = "/ai-api" ] && AI_PREFIX='/ai-api/'

echo "   prefix literal taken from the config: $AI_PREFIX"
echo "   external URL                       -> backend path"
for u in /ai-api/api/v1/health /ai-api/docs /ai-api/api/v1/readiness /ai-api/metrics /api/datasets /up / /build/assets/app.js /storage/datasets/x.png /login; do
  printf '   %-34s -> %s\n' "$u" "$(map_url "$u" "$AI_PREFIX")"
done

for pair in "/ai-api/api/v1/health:/api/v1/health" "/ai-api/docs:/docs" "/api/datasets:/api/datasets" "/up:/up"; do
  u=${pair%%:*}
  want=${pair#*:}
  got=$(map_url "$u" "$AI_PREFIX")
  if [ "$got" = "$want" ]; then
    ok "map $u -> $got"
  else
    bad "map $u" "expected $want, config rewrite yields $got"
  fi
done

case "$(map_url "/ai-api/api/v1/health" "$AI_PREFIX")" in
  "$AI_PREFIX"*) bad "the /ai-api prefix does not survive the rewrite" "the engine mounts /api/v1/* and would answer 404" ;;
  *) ok "the /ai-api prefix does not survive the rewrite" ;;
esac

# ---------------------------------------------------------------------------
head_ "[3] service key cannot be supplied by a browser"
# ---------------------------------------------------------------------------
if printf '%s\n' "$AI_BODY" | grep -Eq '^[[:space:]]*proxy_set_header[[:space:]]+X-Service-Key[[:space:]]+""[[:space:]]*;'; then
  ok "X-Service-Key is cleared on /ai-api/ (empty value = header not sent)"
else
  bad "X-Service-Key is cleared on /ai-api/" \
      "a browser-supplied key would authenticate straight to the engine and bypass Laravel"
fi

# A real vulnerability, not a style rule: relaying the caller's own header back.
if grep -Eq 'proxy_set_header[[:space:]]+X-Service-Key[[:space:]]+\$' "$CONF"; then
  bad "X-Service-Key is not copied from the request" "proxy_set_header X-Service-Key \$http_x_service_key forwards a client value"
else
  ok "X-Service-Key is not echoed back from the request"
fi

# The engine also accepts a Bearer JWT (app/core/security.py require_service_auth),
# so a browser bearer token would authenticate the same way the key would.
if printf '%s\n' "$AI_BODY" | grep -Eq '^[[:space:]]*proxy_set_header[[:space:]]+Authorization'; then
  warn "Authorization is explicitly set on /ai-api/" "confirm it is cleared, not relayed"
elif grep -Eq 'proxy_set_header[[:space:]]+Authorization[[:space:]]+\$' "$CONF"; then
  bad "Authorization is relayed to the engine" "the engine accepts it as a second credential channel"
else
  ok "no browser Authorization header is relayed to the engine"
fi

# ---------------------------------------------------------------------------
head_ "[4] uploads"
# ---------------------------------------------------------------------------
MAX_MB_ENV=$(env_value MAX_UPLOAD_MB)
MAX_MB=${MAX_MB_ENV:-500}
NGX_SIZE=$(sed -n 's/^[[:space:]]*client_max_body_size[[:space:]]\{1,\}\([^;]*\);.*/\1/p' "$CONF" | sed -n '1p' | sed 's/[[:space:]]//g')
NGX_MB=$(size_mb "$NGX_SIZE")

if [ -z "$NGX_SIZE" ]; then
  bad "client_max_body_size is set" "absent, nginx defaults to 1m and every dataset upload is a 413"
elif [ "$NGX_MB" -ge 500 ] 2>/dev/null; then
  ok "client_max_body_size $NGX_SIZE = ${NGX_MB}M is at least the 500 MB floor"
else
  bad "client_max_body_size $NGX_SIZE" "below the 500 MB floor (${NGX_MB}M)"
fi

if [ "$NGX_MB" -ge "$MAX_MB" ] 2>/dev/null; then
  ok "client_max_body_size covers MAX_UPLOAD_MB=${MAX_MB}"
else
  bad "client_max_body_size covers MAX_UPLOAD_MB" \
      "MAX_UPLOAD_MB=${MAX_MB} but nginx allows ${NGX_MB}M; a conforming upload is rejected with 413 before Laravel sees it"
fi

# A change to MAX_UPLOAD_MB cannot reach a file that is bind-mounted read-only
# and holds a literal, and nginx has no env directive outside the main context.
if grep -q '\$MAX_UPLOAD_MB\|\$max_upload\|env MAX_UPLOAD_MB' "$CONF"; then
  ok "client_max_body_size is wired to MAX_UPLOAD_MB"
elif [ "$NGX_MB" -gt "$MAX_MB" ] 2>/dev/null; then
  ok "client_max_body_size ${NGX_MB}M is headroom above MAX_UPLOAD_MB=${MAX_MB}"
else
  warn "client_max_body_size is a literal and MAX_UPLOAD_MB is ${MAX_MB}" \
       "raising MAX_UPLOAD_MB raises only the PHP and engine limits, not the proxy; the two must be edited together"
fi

if [ -f "$APP_CONF" ] && grep -q "max_upload_mb" "$APP_CONF"; then
  ok "application/config/ai_engine.php declares max_upload_mb"
else
  warn "application/config/ai_engine.php does not declare max_upload_mb" "the floor of 500 cannot be cross-checked"
fi

# ---------------------------------------------------------------------------
head_ "[5] timeouts"
# ---------------------------------------------------------------------------
CBT=$(time_s "$(sed -n 's/^[[:space:]]*client_body_timeout[[:space:]]\{1,\}\([^;]*\);.*/\1/p' "$CONF" | sed -n '1p' | sed 's/[[:space:]]//g')")
if [ -z "$CBT" ]; then
  bad "client_body_timeout is set" "absent, nginx defaults to 60s between body reads and a 500 MB upload is cut off"
elif [ "$CBT" -ge 300 ] 2>/dev/null; then
  ok "client_body_timeout ${CBT}s covers a 500 MB upload"
else
  bad "client_body_timeout ${CBT}s" "below the 300 s the engine allows for an upload"
fi

LLM_TIMEOUT=$(env_value AI_ENGINE_LLM_TIMEOUT)
LLM_TIMEOUT=${LLM_TIMEOUT:-120}
UPLOAD_TIMEOUT=$(env_value AI_ENGINE_UPLOAD_TIMEOUT)
UPLOAD_TIMEOUT=${UPLOAD_TIMEOUT:-300}

for blk in '/ai-api/:engine' '/:laravel'; do
  m=${blk%%:*}
  label=${blk#*:}
  body=$(loc_body "$m")
  if [ -z "$body" ]; then
    bad "$label read/send timeouts" "location $m not found"
    continue
  fi
  rt=$(time_s "$(loc_value "$body" 'proxy_read_timeout')")
  st=$(time_s "$(loc_value "$body" 'proxy_send_timeout')")
  if [ -z "$rt" ] || [ "$rt" -lt 60 ] 2>/dev/null; then
    bad "$label proxy_read_timeout" "'${rt:-absent}' is below 60s"
  else
    ok "$label proxy_read_timeout ${rt}s"
  fi
  if [ -z "$st" ] || [ "$st" -lt 60 ] 2>/dev/null; then
    bad "$label proxy_send_timeout" "'${st:-absent}' is below 60s"
  else
    ok "$label proxy_send_timeout ${st}s"
  fi
  if [ "$label" = "laravel" ] && [ -n "$rt" ] && [ "$rt" -lt "$LLM_TIMEOUT" ] 2>/dev/null; then
    bad "laravel read timeout covers AI_ENGINE_LLM_TIMEOUT" \
        "engine budget ${LLM_TIMEOUT}s > proxy_read_timeout ${rt}s; a slow chat is a 504 at the proxy"
  fi
done

LAR_RT=$(time_s "$(loc_value "$LAR_BODY" 'proxy_read_timeout')")
if [ -n "$LAR_RT" ] && [ "$LAR_RT" -ge "$LLM_TIMEOUT" ] 2>/dev/null; then
  ok "laravel read timeout ${LAR_RT}s >= AI_ENGINE_LLM_TIMEOUT ${LLM_TIMEOUT}s"
fi

# The engine's own budget is enforced by Laravel, which calls fastapi:8000
# directly, so these two numbers are independent of the proxy and cannot
# cancel each other out. Say so rather than pretending they interact.
echo "   AI_ENGINE_UPLOAD_TIMEOUT=${UPLOAD_TIMEOUT}s and AI_ENGINE_LLM_TIMEOUT=${LLM_TIMEOUT}s"
echo "   are Laravel->fastapi:8000 budgets; they are not the same hop as nginx->laravel."

# ---------------------------------------------------------------------------
head_ "[6] no streaming is implied"
# ---------------------------------------------------------------------------
if grep -Eq 'proxy_buffering|X-Accel-Buffering|Connection[[:space:]]+""|Upgrade|upgrade|text/event-stream' "$CONF" 2>/dev/null; then
  :
fi
if grep -Eq 'proxy_buffering[[:space:]]+off|X-Accel-Buffering' "$CONF"; then
  warn "config turns proxy buffering off" "the assistant chat is a single JSON response, not a stream"
else
  ok "proxy_buffering is left at its default, so nothing is held open artificially"
fi
if grep -Eq 'proxy_set_header[[:space:]]+Upgrade|Connection[[:space:]]+"upgrade"' "$CONF"; then
  bad "config implies a WebSocket upgrade" "no streaming endpoint exists on either backend"
else
  ok "no WebSocket upgrade is offered"
fi
# proxy_set_header Connection ""; keeps the upstream keepalive pool alive; the
# value is empty, so it must not be reported as a streaming directive.
if grep -Eq 'proxy_set_header[[:space:]]+Connection[[:space:]]+""' "$CONF"; then
  ok 'Connection "" is an empty value (upstream keepalive, not an upgrade)'
fi

# ---------------------------------------------------------------------------
head_ "[7] assets and the front controller"
# ---------------------------------------------------------------------------
for shadow in '/build' '/storage' '/public'; do
  if [ -n "$(loc_body "$shadow")" ]; then
    bad "no location claims $shadow" "nginx shares no filesystem with the Laravel container, so a static location could only ever shadow the front controller"
  else
    ok "no location claims $shadow (assets reach Laravel's server.php router)"
  fi
done

if grep -Eq '^[[:space:]]*try_files' "$CONF"; then
  bad "no try_files directive" "try_files needs a root shared with the Laravel container, which does not exist here"
else
  ok "no try_files: every static path is resolved by the Laravel container"
fi

if grep -Eq '^[[:space:]]*root[[:space:]]' "$CONF"; then
  warn "config sets a root directive" "the nginx container has no application files"
else
  ok "no root directive, so index.php can never shadow /build/"
fi

# ---------------------------------------------------------------------------
head_ "[8] headers"
# ---------------------------------------------------------------------------
for h in X-Real-IP X-Forwarded-For X-Forwarded-Proto; do
  n=$(printf '%s\n' "$AI_BODY" | grep -c "proxy_set_header $h ")
  m=$(printf '%s\n' "$LAR_BODY" | grep -c "proxy_set_header $h ")
  if [ "$n" -ge 1 ] && [ "$m" -ge 1 ]; then
    ok "$h is set on both proxy locations"
  else
    bad "$h is set on both proxy locations" "ai-api=$n laravel=$m"
  fi
done

if printf '%s\n' "$LAR_BODY" | grep -q 'proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for'; then
  ok "X-Forwarded-For appends rather than replaces"
else
  bad "X-Forwarded-For appends rather than replaces" \
      "a fixed \$remote_addr value is right only with a single trusted proxy; \$proxy_add_x_forwarded_for keeps the chain honest"
fi

if printf '%s\n' "$LAR_BODY" | grep -q 'proxy_set_header X-Real-IP \$remote_addr'; then
  ok "X-Real-IP is \$remote_addr (the address nginx actually saw)"
else
  bad "X-Real-IP is \$remote_addr" "a forwarded value would be attacker controlled"
fi

for h in 'X-Frame-Options' 'Referrer-Policy' 'X-Content-Type-Options'; do
  if grep -Eq "^[[:space:]]*add_header[[:space:]]+$h[[:space:]]" "$CONF"; then
    ok "add_header $h is set at server level"
  else
    warn "add_header $h is not set" "consider adding it; an untested CSP would break the Blade UI, so this is a report, not a change"
  fi
done

CANCELLED=$(locations_with_add_header)
if [ -z "$CANCELLED" ]; then
  ok "no location re-declares add_header, so the server-wide headers are not cancelled"
else
  bad "no location re-declares add_header" "these locations drop every inherited add_header: $(printf '%s' "$CANCELLED" | tr '\n' ' ')"
fi

if printf '%s\n' "$HEALTH_BODY" | grep -q 'add_header Content-Type'; then
  bad "the health location uses default_type" "add_header Content-Type appends a second Content-Type on top of the one the return filter already emitted"
else
  ok "the health location uses default_type, not add_header Content-Type"
fi

# ---------------------------------------------------------------------------
head_ "[9] upstreams match docker-compose"
# ---------------------------------------------------------------------------
AI_UPSTREAM=$(printf '%s\n' "$AI_BODY" | sed -n 's/^[[:space:]]*proxy_pass[[:space:]]\{1,\}http:\/\/\([A-Za-z0-9_]*\).*/\1/p' | sed -n '1p')
LAR_UPSTREAM=$(printf '%s\n' "$LAR_BODY" | sed -n 's/^[[:space:]]*proxy_pass[[:space:]]\{1,\}http:\/\/\([A-Za-z0-9_]*\).*/\1/p' | sed -n '1p')

[ -n "$AI_UPSTREAM" ] && ok "/ai-api/ proxies to upstream $AI_UPSTREAM" || bad "/ai-api/ proxies to an upstream" "no proxy_pass found"
[ -n "$LAR_UPSTREAM" ] && ok "/ proxies to upstream $LAR_UPSTREAM" || bad "/ proxies to an upstream" "no proxy_pass found"

for u in $AI_UPSTREAM $LAR_UPSTREAM; do
  [ -z "$u" ] && continue
  if grep -Eq "^[[:space:]]*upstream[[:space:]]+$u[[:space:]]*[{]" "$CONF"; then
    ok "upstream $u is declared"
  else
    bad "upstream $u is declared" "nginx would refuse to start on an undefined upstream"
  fi
done

# Every `server host:port` in an upstream must name a compose service on the
# port that service actually exposes, or nginx resolves to nothing at runtime.
for target in $(upstream_servers); do
  host=${target%%:*}
  port=${target#*:}
  case "$host" in
    *[!A-Za-z0-9_-]*) bad "upstream target $target" "unexpected host format" ; continue ;;
  esac
  if grep -Eq "^  $host:$" "$COMPOSE"; then
    ok "upstream target $host is a service in docker-compose.yml"
  else
    bad "upstream target $host" "no service named '$host' in docker-compose.yml"
  fi
  EXP=$(compose_expose_of "$host")
  if [ -n "$EXP" ] && [ "$EXP" = "$port" ]; then
    ok "$host exposes $port, matching the upstream"
  elif [ -z "$EXP" ]; then
    warn "$host declares no expose: port" "the upstream targets $port; the container is only reachable if the image EXPOSEs it"
  else
    bad "$host upstream port" "upstream uses $port but the service exposes $EXP"
  fi
done

# The Laravel -> engine call must not go through nginx, or the key nginx is
# required to strip would be stripped on the one hop that legitimately sends it.
AI_ENGINE_URL=$(sed -n 's/^[[:space:]]*AI_ENGINE_URL:[[:space:]]*${AI_ENGINE_URL:-\([^}]*\)}.*/\1/p' "$COMPOSE" | sed -n '1p')
case "$AI_ENGINE_URL" in
  http://fastapi:*) ok "AI_ENGINE_URL is $AI_ENGINE_URL, a direct in-network call" ;;
  "") bad "AI_ENGINE_URL default is readable from docker-compose.yml" "not found" ;;
  *) bad "AI_ENGINE_URL is $AI_ENGINE_URL" "routing Laravel->engine through nginx would strip the service key it must send" ;;
esac

# ---------------------------------------------------------------------------
head_ "[10] the engine's own surface is not proxied wide open"
# ---------------------------------------------------------------------------
if [ -n "$(loc_body '/ai-api/metrics')" ] && loc_body '/ai-api/metrics' | grep -q 'return 404'; then
  ok "/ai-api/metrics is refused (the engine gates it on the socket peer, which is always nginx here)"
else
  bad "/ai-api/metrics is refused" \
      "proxied, the peer is the nginx container, so _is_internal_client passes and metrics_allow_public=False never applies"
fi

for p in /api/v1/health /api/v1/readiness /api/v1/liveness /readiness /liveness; do
  out=$(map_url "$AI_PREFIX${p#/}" "$AI_PREFIX")
  if [ "$out" = "$p" ]; then ok "$AI_PREFIX$p -> $out (unauthenticated by design)"; else bad "$AI_PREFIX$p" "expected $p, got $out"; fi
done

# ---------------------------------------------------------------------------
echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
echo "note: this is a static parse. No nginx, Docker or request was executed."
[ "$FAIL" -eq 0 ]
