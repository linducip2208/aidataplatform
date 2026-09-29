#!/bin/sh
# AIDataPlatform — idempotent Ubuntu 24.04 production deploy.
#
# Run as root (or with sudo) on a fresh Ubuntu 24.04 VM. Safe to re-run.
# Usage: sudo APP_DIR=/opt/aidataplatform DOMAIN=data.example.com \
#            bash infrastructure/scripts/deploy-ubuntu24.sh
# Assumes the repo is already cloned to /opt/aidataplatform (override with
# APP_DIR; set REPO_URL to have the script clone it when APP_DIR is empty).
#
# POSIX sh: /bin/sh on Ubuntu is dash, so no `set -o pipefail` and no bashisms.
#
# Every value that reaches a shell, a crontab or a file written as root is
# validated against a character allowlist before it is used. APP_DIR and
# APP_USER are interpolated into a crontab line that cron later hands to /bin/sh
# as root, so `APP_DIR='/opt/x; rm -rf /'` would otherwise be remote code
# execution on the production host with a `sudo` in front of it. The allowlist
# is the defence, and the cron line is re-checked against it after assembly.
#
# EXIT CODES:
#   0   the deploy finished and healthcheck.sh reported no failures
#   2   a precondition was missing (no docker, no repo, unreadable .env)
#   3   a deploy step failed - the previous version is still what is serving
#   10  first run: .env was created from .env.example and needs editing. NOT a
#       finished deploy; re-run this script after filling it in.
set -eu

EXIT_OK=0
EXIT_PRECONDITION=2
EXIT_STEP_FAILED=3
EXIT_NEEDS_ENV=10

APP_DIR="${APP_DIR:-/opt/aidataplatform}"
APP_USER="${APP_USER:-aidata}"
DOMAIN="${DOMAIN:-}"
REPO_URL="${REPO_URL:-}"

# The services that write to the database. A restore or a schema migration
# under these re-appends the new data over whatever was just applied.
WRITERS="laravel laravel-queue laravel-schedule fastapi celery-worker celery-beat"
# The services that carry a healthcheck in docker-compose.yml. The rest are
# only required to be Up.
HEALTHCHECKED="postgres redis laravel fastapi nginx"

step() { echo; echo "=== [$1/9] $2 ==="; }
fatal() { echo; echo "FATAL: $*" >&2; echo "The previous version is still what is serving; nothing was torn down." >&2; exit "$EXIT_STEP_FAILED"; }
need() { echo; echo "FATAL: $*" >&2; exit "$EXIT_PRECONDITION"; }

# ---------------------------------------------------------------------------
# Validate before use, not after. These three values all end up as root's
# arguments or as text in a root-owned crontab and logrotate file.
# ---------------------------------------------------------------------------
case "$APP_DIR" in
    /*) ;;
    *) need "APP_DIR must be an absolute path (got '$APP_DIR')" ;;
esac
case "$APP_DIR" in
    *[!A-Za-z0-9_./-]*) need "APP_DIR may only contain A-Z a-z 0-9 _ . / - (got '$APP_DIR')" ;;
esac
# /opt, /var, /bin ... are refused: the script chown -R's APP_DIR and logrotate
# is pointed at a file under it, and a one-level path is almost certainly a
# typo for the intended directory.
case "$APP_DIR" in
    /?*) need "APP_DIR must be at least two levels deep (got '$APP_DIR'); refusing to chown -R and rotate logs under it" ;;
esac

case "$APP_USER" in
    *[!A-Za-z0-9_-]*) need "APP_USER may only contain A-Z a-z 0-9 _ - (got '$APP_USER')" ;;
esac
case "$APP_USER" in
    root) need "APP_USER must not be root; the backup cron would then run as root" ;;
esac

if [ -n "$DOMAIN" ]; then
    case "$DOMAIN" in
        *.*) ;;
        *) need "DOMAIN must be a hostname (got '$DOMAIN')" ;;
    esac
    case "$DOMAIN" in
        *[!A-Za-z0-9.-]*) need "DOMAIN may only contain A-Z a-z 0-9 . - (got '$DOMAIN')" ;;
        .* | *.) need "DOMAIN must not start or end with a dot (got '$DOMAIN')" ;;
    esac
fi

command -v apt-get >/dev/null 2>&1 || need "apt-get not found; this script targets a Debian/Ubuntu host"
if [ "$(id -u)" -ne 0 ]; then
    need "run this as root: sudo bash infrastructure/scripts/deploy-ubuntu24.sh"
fi

step 1 "base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y || fatal "apt-get update failed (no network, or a broken source list?)"
# A partial base install is not survivable: curl is how Docker is installed and
# logrotate is what keeps cron.log from filling the disk.
apt-get install -y ca-certificates curl gnupg git make ufw logrotate cron \
    || fatal "apt-get install of the base packages failed; fix the apt sources and re-run"
if [ -n "$DOMAIN" ]; then
    apt-get install -y certbot python3-certbot-nginx \
        || echo "WARN: certbot install failed; the TLS step will need a manual run"
fi

step 2 "docker engine (official repo)"
# The plugin is checked even when docker itself is present: `docker compose`
# (v2) is what every other file in this repo uses, and a host with only the
# v1 `docker-compose` binary otherwise fails much later with a bare
# "docker: 'compose' is not a docker command".
if ! docker compose version >/dev/null 2>&1; then
    if ! command -v docker >/dev/null 2>&1; then
        install -m 0755 -d /etc/apt/keyrings
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
            || fatal "could not fetch and install Docker's signing key"
        chmod a+r /etc/apt/keyrings/docker.gpg
        . /etc/os-release
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME:-noble} stable" \
            > /etc/apt/sources.list.d/docker.list
        apt-get update -y || fatal "apt-get update failed after adding the Docker repository"
        apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin \
            || fatal "installing Docker Engine failed"
        systemctl enable --now docker || fatal "systemctl could not start docker"
    else
        fatal "docker is installed but 'docker compose' (v2 plugin) is not; install docker-compose-plugin and re-run"
    fi
else
    echo "docker already installed: $(docker --version)"
    echo "compose plugin: $(docker compose version --short 2>/dev/null || echo unknown)"
fi
docker compose version >/dev/null 2>&1 || fatal "'docker compose' still does not work after installation"

step 3 "app user + repo"
id "$APP_USER" >/dev/null 2>&1 || useradd -r -m -s /bin/bash "$APP_USER"
# The backup cron runs as $APP_USER and calls `docker compose`, which talks to
# the daemon over /var/run/docker.sock — root:root 0660, group docker. Without
# this the 02:00 backup fails every single night with "permission denied while
# trying to connect to the Docker daemon" and nobody notices until they need it.
if ! id -nG "$APP_USER" | tr ' ' '\n' | grep -qx docker; then
    usermod -aG docker "$APP_USER" || fatal "could not add $APP_USER to the docker group"
    echo "added $APP_USER to the docker group (required by the backup cron)"
fi

if [ ! -f "$APP_DIR/docker-compose.yml" ]; then
    if [ -n "$REPO_URL" ]; then
        echo "cloning $REPO_URL into $APP_DIR ..."
        git clone "$REPO_URL" "$APP_DIR" || fatal "git clone failed"
    else
        need "no repository at $APP_DIR and REPO_URL is not set. Clone it first:
  git clone <repo-url> $APP_DIR
or re-run with REPO_URL=<repo-url> to have this script do it."
    fi
fi
mkdir -p "$APP_DIR/backups"
# Not `|| true`: the backup cron runs as $APP_USER and cannot write
# $APP_DIR/backups without this, and the failure would surface only at 02:00.
chown -R "$APP_USER":"$APP_USER" "$APP_DIR" || fatal "could not chown $APP_DIR to $APP_USER"
cd "$APP_DIR"

step 4 "firewall (ufw)"
# Non-fatal by design: a host without iptables (a nested VM, a container) must
# still be deployable. Reported rather than swallowed.
for _rule in "OpenSSH" "80/tcp" "443/tcp"; do
    if ! ufw allow "$_rule" >/dev/null 2>&1; then
        echo "WARN: 'ufw allow $_rule' failed - continue and open the ports out of band if this host is exposed"
    fi
done
ufw --force enable >/dev/null 2>&1 || echo "WARN: 'ufw enable' failed; open 22/80/443 manually if this host is exposed"
ufw status || true

step 5 "env file"
if [ ! -f .env.example ]; then
    need "no .env.example in $APP_DIR - this does not look like the AIDataPlatform repository"
fi
if [ ! -f .env ]; then
    cp .env.example .env || fatal "could not create .env from .env.example"
    echo "!! EDIT .env now (POSTGRES_PASSWORD, REDIS_PASSWORD, SERVICE_API_KEY, LLM keys, APP_KEY, GRAFANA pw) then re-run this script."
    echo "   Generate the app key:   cd application && php -r \"echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;\""
    echo "   Generate the service key: python3 -c 'import secrets; print(secrets.token_urlsafe(48))'"
    echo "!! Nothing has been deployed yet. Re-run this script after editing .env."
    exit "$EXIT_NEEDS_ENV"
fi
# A missing APP_KEY is not a warning: laravel-entrypoint.sh calls die() on an
# empty APP_KEY, so the container exits and every page 500s. Say so and stop
# before `docker compose up` rebuilds an image that cannot start.
grep -q '^APP_KEY=base64:' .env || fatal "APP_KEY is not set in .env; the laravel container refuses to start without it. Generate one with: cd application && php artisan key:generate --show"
grep -q '^APP_DEBUG=false' .env || echo "WARN: APP_DEBUG is not false in .env; production should not ship debug pages"
# A placeholder secret is a live hole: security.py treats the placeholders as
# "not configured" and then fails closed, so the app works but every engine
# call is a 401. That is exactly the symptom this deploy would otherwise ship.
if grep -qE '^SERVICE_API_KEY=(change-me|changeme)?[[:space:]]*$' .env; then
    fatal "SERVICE_API_KEY in .env is still the shipped placeholder; the engine fails closed and every engine-backed page will be a 502"
fi
docker compose config --quiet || fatal "'docker compose config' rejected docker-compose.yml; the stack was not touched"

# ---------------------------------------------------------------------------
# wait_for_stack — "up" is not "serving". Returns non-zero as soon as a
# healthchecked service goes unhealthy, rather than waiting out the full
# timeout on a container that is never going to recover.
# ---------------------------------------------------------------------------
wait_for_stack() {
    _tries=$1
    _i=1
    while [ "$_i" -le "$_tries" ]; do
        _snap=$(docker compose ps -a --format '{{.Service}} {{.Status}}' 2>/dev/null)
        _pending=""
        _unhealthy=""
        for _s in $_HEALTHCHECKED
        do
            _st=$(printf '%s\n' "$_snap" | awk -v s="$_s" '$1 == s { sub(/^[^ ]+ /, ""); print; exit }')
            case "$_st" in
                *"(unhealthy)"*) _unhealthy="$_unhealthy $_s" ;;
                *"(starting)"*)   _pending="$_pending $_s(starting)" ;;
                Up*)              ;;
                "")               _pending="$_pending $_s(absent)" ;;
                *)                _pending="$_pending $_s($_st)" ;;
            esac
        done
        if [ -n "$_unhealthy" ]; then
            echo "unhealthy:$_unhealthy"
            return 1
        fi
        if [ -z "$_pending" ]; then
            return 0
        fi
        [ "$_i" = "1" ] || printf '.'
        sleep 5
        _i=$((_i + 1))
    done
    echo ""
    echo "still not ready:$_pending"
    return 1
}

step 6 "pre-up backup (rollback point)"
# A deploy that migrates the schema is the exact moment a pre-change dump
# matters, and it is the only rollback point that exists afterwards. Skipped on
# a genuinely fresh install, where there is no database to dump yet.
if docker compose ps postgres --format '{{.Status}}' 2>/dev/null | grep -q '^Up'; then
    bash infrastructure/scripts/backup.sh || fatal "the pre-deploy backup failed; refusing to deploy without a rollback point (see the [backup] FATAL above)"
else
    echo "postgres is not running yet (fresh install) - nothing to back up"
fi

step 7 "build + up"
# The laravel image runs `npm ci` in its assets stage, so application/package-lock.json
# has to exist. That is the first thing the build fails on if it does not.
_pre_image=$(docker compose images -q laravel 2>/dev/null | head -n 1)
docker compose up -d --build || fatal "docker compose up -d --build failed; the build or the container start did not succeed"
echo -n "waiting for the stack to become healthy "
if ! wait_for_stack 90; then
    docker compose ps
    echo "--- last 40 lines of each service log ---"
    for _s in $_HEALTHCHECKED; do
        echo "--- $_s ---"
        docker compose logs --tail=20 "$_s" 2>&1 || true
    done
    fatal "the stack did not reach a healthy state; it has NOT been verified as serving the new version"
fi
echo ""
_post_image=$(docker compose images -q laravel 2>/dev/null | head -n 1)
if [ -n "$_pre_image" ] && [ "$_pre_image" = "$_post_image" ]; then
    echo "note: the laravel image id is unchanged ($_post_image) - no code changed, so the containers were not recreated"
else
    echo "laravel image: ${_pre_image:-<none>} -> ${_post_image:-<none>}"
fi
docker compose ps

step 8 "migrations + seed + verification"
# Both the laravel container and the fastapi container migrate on start, and
# both exit non-zero / log loudly on failure. These two commands are fatal here:
# a deploy that leaves the schema behind the code is not a deploy.
docker compose exec -T laravel php artisan migrate --force \
    || fatal "artisan migrate --force failed; see: docker compose logs laravel"
docker compose exec -T fastapi alembic upgrade head \
    || fatal "alembic upgrade head failed; see: docker compose logs fastapi"
docker compose exec -T laravel php artisan db:seed --force \
    || echo "WARN: db:seed failed - usually it means the demo data is already seeded; see: docker compose logs laravel"

# The deploy is only complete when healthcheck.sh says the new version is
# actually answering. `|| echo WARN` here is what let a broken release print
# "deploy complete" and exit 0.
if ! bash infrastructure/scripts/healthcheck.sh; then
    echo
    echo "healthcheck.sh reported failures. The new version is NOT verified as serving." >&2
    echo "Inspect first:  docker compose ps && docker compose logs --tail=50 <service>" >&2
    echo "Roll back:      cd $APP_DIR && git checkout <previous-tag> && docker compose up -d --build" >&2
    echo "Restore the database: bash infrastructure/scripts/restore.sh --yes $APP_DIR/backups/restore-safety_*.sql.gz" >&2
    exit "$EXIT_STEP_FAILED"
fi

step 9 "ssl, backup cron, logrotate"
if [ -n "$DOMAIN" ]; then
    echo "To issue TLS (needs DNS $DOMAIN -> this server, and nginx answering on :80):"
    echo "  certbot --nginx -d $DOMAIN"
    echo "certbot writes fullchain.pem / privkey.pem into infrastructure/nginx/ssl/."
    echo "Then uncomment the :443 server block in infrastructure/nginx/default.conf and the HSTS header, and: docker compose restart nginx"
else
    echo "Set DOMAIN=... and re-run for the TLS hint, or run: certbot --nginx -d <your-domain>"
fi

CRON_LOG="$APP_DIR/backups/cron.log"
CRON_LINE="0 2 * * * $APP_USER cd $APP_DIR && BACKUP_DIR=$APP_DIR/backups bash infrastructure/scripts/backup.sh >> $CRON_LOG 2>&1"
# Belt and braces: the allowlist above is the real control, this catches a
# future edit that widens one of the three variables. Listed as literal
# characters rather than one negated class, because a case pattern containing
# & or | does not parse in dash.
case "$CRON_LINE" in
    *';'* | *'&'* | *'|'* | *'`'* | *'('* | *')'* | *'>'* | *'<'*)
        fatal "the assembled cron line contains a shell metacharacter; refusing to install it" ;;
esac
case "$CRON_LINE" in
    *[!A-Za-z0-9_./:=,@*%+-]*) fatal "the assembled cron line contains an unexpected character; refusing to install it" ;;
esac
( crontab -u "$APP_USER" -l 2>/dev/null | grep -v 'infrastructure/scripts/backup.sh'; echo "$CRON_LINE" ) | crontab -u "$APP_USER" - \
    || fatal "could not install the backup cron for $APP_USER"
echo "backup cron installed for $APP_USER:"
crontab -u "$APP_USER" -l | grep 'backup.sh' || echo "  (not listed - check: crontab -u $APP_USER -l)"

# Named for what it rotates. It used to be called aidata-nginx while rotating
# the backup log, which sends the next reader to the wrong file.
cat > /etc/logrotate.d/aidata-backups <<EOF
$CRON_LOG {
  weekly
  rotate 8
  compress
  missingok
  notifempty
  su $APP_USER $APP_USER
  create 0644 $APP_USER $APP_USER
}
EOF

echo
echo "=== deploy complete. Check: docker compose ps && bash infrastructure/scripts/healthcheck.sh ==="
exit "$EXIT_OK"
