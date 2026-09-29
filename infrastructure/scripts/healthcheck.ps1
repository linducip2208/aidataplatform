# AIDataPlatform healthcheck for Windows PowerShell (mirrors healthcheck.sh).
#
# Usage: powershell -ExecutionPolicy Bypass -File infrastructure/scripts/healthcheck.ps1
#
# Probes only endpoints and services that exist in this tree; the table of
# "service -> path -> source of truth" lives at the top of
# infrastructure/scripts/healthcheck.sh and is not repeated here.
#
# try/catch does not catch a native command exiting non-zero, so every probe
# below inspects $LASTEXITCODE explicitly. The previous version ran
# `docker compose exec` through a helper that threw its stdout away and
# returned only the exit status, so the two probes that need the HTTP status
# (POST /api/login, GET /api/me) always compared the empty string against
# "200" and reported "laravel unreachable" on a perfectly healthy stack.
#
# EXIT CODES:
#   0  every probe passed (warnings may still have been printed)
#   1  the stack answered but at least one probe FAILED
#   2  the check could not be performed: no docker, no Compose v2, no project
#      in this directory. 2 means "no answer", never "all good".
$ErrorActionPreference = "Continue"

$script:pass = 0
$script:fail = 0
$script:warn = 0

function Ok($m)    { Write-Host "  [OK]   $m" -ForegroundColor Green; $script:pass++ }
function Bad($m, $h) { Write-Host "  [FAIL] $m -- $h" -ForegroundColor Red; $script:fail++ }
function Warn($m, $h) { Write-Host "  [WARN] $m -- $h" -ForegroundColor Yellow; $script:warn++ }

# One line, so a multi-line HTML error page cannot bury the summary.
function Shorten($s) {
    if ($null -eq $s) { return "" }
    $flat = ($s -replace "[`r`n]+", " ")
    if ($flat.Length -gt 220) { return $flat.Substring(0, 220) }
    return $flat
}

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
Push-Location $repoRoot
try {
    # -- preflight ------------------------------------------------------------
    Write-Host "== AIDataPlatform healthcheck (PowerShell) =="
    Write-Host "   repo: $repoRoot"

    $preflightOk = $true
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        Write-Host "  [FAIL] the 'docker' CLI is not on PATH - no probe below can run" -ForegroundColor Red
        $preflightOk = $false
    } else {
        $null = & docker compose version 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host "  [FAIL] 'docker compose' is unavailable (Compose v2 plugin missing or daemon down)" -ForegroundColor Red
            $preflightOk = $false
        } elseif (-not (Test-Path "docker-compose.yml") -and -not (Test-Path "compose.yml")) {
            Write-Host "  [FAIL] no docker-compose.yml in $repoRoot" -ForegroundColor Red
            $preflightOk = $false
        } else {
            $svcLines = @(& docker compose ps -a --format "{{.Service}}" 2>$null)
            if ($LASTEXITCODE -ne 0) {
                Write-Host "  [FAIL] 'docker compose ps' failed" -ForegroundColor Red
                $preflightOk = $false
            } elseif ($svcLines.Count -eq 0) {
                Write-Host "  [FAIL] no compose services found for $repoRoot - is the stack created here?" -ForegroundColor Red
                Write-Host "         run: cd $repoRoot ; docker compose ps" -ForegroundColor Red
                $preflightOk = $false
            } else {
                Ok "docker + compose reachable, $($svcLines.Count) services known"
            }
        }
    }
    if (-not $preflightOk) {
        Write-Host "== result: preflight failed, 0 probes run (this is NOT an all-clear) ==" -ForegroundColor Red
        exit 2
    }

    # -- .env (process env only; an already-set variable always wins) ----------
    $envFile = Join-Path $repoRoot ".env"
    if (Test-Path $envFile) {
        Get-Content $envFile | ForEach-Object {
            $line = $_.Trim()
            if ($line -and -not $line.StartsWith("#") -and $line -match "^([A-Za-z_][A-Za-z0-9_]*)=(.*)$") {
                $name = $Matches[1]
                $value = $Matches[2].Trim().Trim('"').Trim("'")
                if (-not [Environment]::GetEnvironmentVariable($name)) {
                    [Environment]::SetEnvironmentVariable($name, $value)
                }
            }
        }
    }

    # -- probe helpers --------------------------------------------------------
    # Invoke-Curl runs curl inside a container and keeps BOTH the body and the
    # status line, so "the server said 500" is never confused with "curl could
    # not run". The last line of stdout is the status: everything before it is
    # the body.
    function Invoke-Curl {
        param([string]$Service, [string[]]$CurlArgs)
        $script:pcStatus = "unreachable"
        $script:pcCode = ""
        $script:pcBody = ""
        $script:pcErr = ""
        $argv = @("exec", "-T", $Service, "curl", "-sS", "--max-time", "15", "-w", "\n%{http_code}") + $CurlArgs
        $out = @(& docker compose @argv 2>&1)
        $rc = $LASTEXITCODE
        $text = ($out -join "`n")
        $lines = $text -split "`n"
        $last = if ($lines.Count -gt 0) { $lines[$lines.Count - 1].Trim() } else { "" }
        if ($last -notmatch "^\d{3}$") {
            $script:pcErr = "$text (no HTTP status line in the response)"
            return
        }
        $script:pcCode = $last
        $script:pcBody = (($lines[0..($lines.Count - 2)]) -join "`n")
        if ($rc -ne 0) { $script:pcStatus = "unreachable"; $script:pcErr = $text }
        elseif ($last -eq "200") { $script:pcStatus = "ok" }
        else { $script:pcStatus = "bad-http" }
    }

    # Exit-code-only probe, for tools whose exit status is the signal
    # (pg_isready, redis-cli ping, busybox wget).
    function Get-ExitCode {
        param([string[]]$DockerArgs)
        $null = & docker compose @DockerArgs
        return $LASTEXITCODE
    }

    # -- [1] container roll-call ----------------------------------------------
    Write-Host "-- [1] container states --"
    $psLines = @(& docker compose ps -a --format "{{.Service}} {{.Status}}" 2>$null)
    $psMap = @{}
    foreach ($l in $psLines) {
        if ($l -match "^(\S+)\s+(.*)$") { $psMap[$Matches[1]] = $Matches[2] }
    }
    foreach ($svc in @("postgres", "redis", "laravel", "laravel-queue", "laravel-schedule",
                       "fastapi", "celery-worker", "celery-beat", "nginx", "prometheus", "grafana")) {
        if (-not $psMap.ContainsKey($svc)) {
            Bad $svc "not present in the compose project at $repoRoot - docker compose ps -a"
        } elseif ($psMap[$svc] -like "*(unhealthy)*") {
            Bad $svc "unhealthy: $($psMap[$svc]) - docker compose logs $svc | Select-Object -Last 50"
        } elseif ($psMap[$svc] -like "Up*" -or $psMap[$svc] -like "running*") {
            Ok "$svc ($($psMap[$svc]))"
        } else {
            Bad $svc "status='$($psMap[$svc])' - docker compose logs $svc | Select-Object -Last 50"
        }
    }

    # -- [2] laravel /up ------------------------------------------------------
    Write-Host "-- [2] laravel (GET /up) --"
    Invoke-Curl "laravel" @("-o", "/dev/null", "http://localhost:8000/up")
    switch ($script:pcStatus) {
        "ok"          { Ok "laravel /up" }
        "unreachable" { Bad "laravel /up" "probe could not run: $(Shorten $script:pcErr)" }
        default       { Bad "laravel /up" "http=$($script:pcCode) body=$(Shorten $script:pcBody)" }
    }

    # -- [3] laravel token API ------------------------------------------------
    Write-Host "-- [3] laravel token API (POST /api/login, GET /api/me) --"
    Invoke-Curl "laravel" @("-X", "POST", "http://localhost:8000/api/login",
        "-H", "Content-Type: application/json",
        "-d", '{"email":"admin@example.com","password":"Admin123!"}')
    switch ($script:pcStatus) {
        "unreachable" {
            Bad "POST /api/login" "probe could not run: $(Shorten $script:pcErr)"
        }
        "bad-http" {
            if ($script:pcCode -in @("401", "422")) {
                Warn "POST /api/login" "http=$($script:pcCode) body=$(Shorten $script:pcBody) | seed it: docker compose exec laravel php artisan db:seed --force"
            } elseif ($script:pcCode -eq "429") {
                Bad "POST /api/login" "http=429 - throttled (throttle:login, 5/min per email+IP). Wait a minute; the seeded-account warning may be a throttle artefact."
            } else {
                Bad "POST /api/login" "http=$($script:pcCode) body=$(Shorten $script:pcBody)"
            }
        }
        "ok" {
            $m = [regex]::Match($script:pcBody, '"token"\s*:\s*"([^"]+)"')
            if (-not $m.Success) {
                Warn "POST /api/login" "http=200 but no `"token`" in the body: $(Shorten $script:pcBody)"
            } else {
                $auth = "Authorization: Bearer $($m.Groups[1].Value)"
                Invoke-Curl "laravel" @("-o", "/dev/null", "-H", $auth, "http://localhost:8000/api/me")
                switch ($script:pcStatus) {
                    "ok"          { Ok "laravel token API (login + /api/me)" }
                    "unreachable" { Bad "GET /api/me" "probe could not run: $(Shorten $script:pcErr)" }
                    default       { Bad "GET /api/me" "http=$($script:pcCode) - the token was issued but rejected" }
                }
            }
        }
    }

    # -- [4] engine health ----------------------------------------------------
    Write-Host "-- [4] engine (GET /api/v1/health) --"
    Invoke-Curl "fastapi" @("http://localhost:8000/api/v1/health")
    switch ($script:pcStatus) {
        "ok" {
            if ($script:pcBody -match '"status"') { Ok "engine /api/v1/health 200, body carries status" }
            else { Warn "engine /api/v1/health" "http=200 but the body is not the engine's HealthResponse: $(Shorten $script:pcBody)" }
        }
        "unreachable" { Bad "engine /api/v1/health" "probe could not run: $(Shorten $script:pcErr)" }
        default       { Bad "engine /api/v1/health" "http=$($script:pcCode) body=$(Shorten $script:pcBody)" }
    }

    # -- [5] engine readiness -------------------------------------------------
    # /api/v1/readiness answers 200 even when the database is unreachable
    # (ai-engine/app/api/v1/health.py returns a plain dict), so the code proves
    # nothing and the body is the only signal. NOTJSON is its own outcome.
    Write-Host "-- [5] engine (GET /api/v1/readiness) --"
    $py = @'
import json, sys, urllib.request
try:
    raw = urllib.request.urlopen("http://localhost:8000/api/v1/readiness", timeout=15).read().decode("utf-8", "replace")
except Exception as exc:
    print("UNREACHABLE:" + type(exc).__name__)
    sys.exit(0)
try:
    d = json.loads(raw)
except Exception:
    print("NOTJSON")
    sys.exit(0)
if not isinstance(d, dict) or "ready" not in d:
    print("NOTJSON")
    sys.exit(0)
checks = d.get("checks") if isinstance(d.get("checks"), dict) else {}
print("READY:" + str(d.get("ready")))
print("DB:" + str(checks.get("db")))
print("REDIS:" + str(checks.get("redis")))
'@
    $rOut = @(& docker compose exec -T fastapi python -c $py 2>&1)
    $rRc = $LASTEXITCODE
    $rText = ($rOut -join "`n")
    $rReady = ([regex]::Match($rText, "(?m)^READY:(.*)$")).Groups[1].Value
    $rDb = ([regex]::Match($rText, "(?m)^DB:(.*)$")).Groups[1].Value
    $rRedis = ([regex]::Match($rText, "(?m)^REDIS:(.*)$")).Groups[1].Value
    if ($rRc -ne 0) {
        Bad "engine /api/v1/readiness" "probe could not run: $(Shorten $rText)"
    } elseif ($rText -match "UNREACHABLE:") {
        Bad "engine /api/v1/readiness" "the engine did not answer: docker compose logs fastapi | Select-Object -Last 50"
    } elseif ($rReady -eq "True" -or $rReady -eq "true") {
        if ($rRedis -eq "up") {
            Ok "engine /api/v1/readiness ready=true (db=$rDb redis=$rRedis)"
        } elseif ([string]::IsNullOrEmpty($rRedis)) {
            Warn "engine /api/v1/readiness" "ready=true but the body reported no redis check"
        } else {
            Warn "engine /api/v1/readiness" "ready=true but redis is $rRedis - cache/session/queue calls will fail"
        }
    } elseif ($rReady -eq "False" -or $rReady -eq "false") {
        Bad "engine /api/v1/readiness" "ready=false (db=$rDb redis=$rRedis) - docker compose logs fastapi | Select-Object -Last 50"
    } elseif ($rText -match "NOTJSON") {
        Bad "engine /api/v1/readiness" "the response was not JSON - something other than the engine answered"
    } else {
        Bad "engine /api/v1/readiness" "no 'ready' field in the body: $(Shorten $rText)"
    }

    # -- [6] nginx /health (the control for the next probe) --------------------
    Write-Host "-- [6] nginx (GET /health) --"
    $nginxUp = $true
    $c = Get-ExitCode @("exec", "-T", "nginx", "wget", "-qO-", "--timeout=15", "http://localhost/health")
    if ($c -eq 0) { Ok "nginx /health" }
    else { $nginxUp = $false; Bad "nginx /health" "wget rc=$c - docker compose logs nginx | Select-Object -Last 20" }

    # -- [7] the /ai-api prefix strip ----------------------------------------
    Write-Host "-- [7] nginx (GET /ai-api/api/v1/health, proves the prefix strip) --"
    if (-not $nginxUp) {
        Warn "nginx /ai-api/api/v1/health" "skipped - nginx did not answer /health, so this probe cannot say anything"
    } else {
        $aiOut = @(& docker compose exec -T nginx wget -qO- --timeout=15 "http://localhost/ai-api/api/v1/health" 2>$null)
        $aiRc = $LASTEXITCODE
        $aiBody = ($aiOut -join "`n").Trim()
        if ($aiRc -ne 0 -and [string]::IsNullOrEmpty($aiBody)) {
            Bad "nginx /ai-api/api/v1/health" "wget rc=$aiRc and no body - nginx answered /health, so the rewrite or the fastapi upstream is broken"
        } elseif ($aiBody -eq "ok") {
            Bad "nginx /ai-api/api/v1/health" "got nginx's own /health body ('ok') - the /ai-api/ location is not rewriting, the request fell through to laravel"
        } elseif ($aiBody -match '"status"') {
            Ok "nginx /ai-api/ prefix is stripped to /api/v1/"
        } else {
            Bad "nginx /ai-api/api/v1/health" "rc=$aiRc body=$(Shorten $aiBody) - docker compose logs nginx | Select-Object -Last 20"
        }
    }

    # -- [8] postgres ---------------------------------------------------------
    Write-Host "-- [8] postgres (pg_isready) --"
    $pgUser = if ($env:POSTGRES_USER) { $env:POSTGRES_USER } else { "aidata" }
    $pgDb = if ($env:POSTGRES_DB) { $env:POSTGRES_DB } else { "aidata" }
    $c = Get-ExitCode @("exec", "-T", "postgres", "pg_isready", "-U", $pgUser, "-d", $pgDb)
    if ($c -eq 0) { Ok "postgres pg_isready ($pgUser/$pgDb)" }
    else { Bad "postgres pg_isready ($pgUser/$pgDb)" "pg_isready exit=$c - docker compose logs postgres | Select-Object -Last 30" }

    # -- [9] redis ------------------------------------------------------------
    # redis-cli needs the password whenever REDIS_PASSWORD is set, and an empty
    # REDIS_PASSWORD in compose means "no password at all".
    Write-Host "-- [9] redis (PING) --"
    $redisMode = "without password"
    if ($env:REDIS_PASSWORD) {
        $redisMode = "with REDIS_PASSWORD"
        $pong = (@(& docker compose exec -T redis redis-cli -a $env:REDIS_PASSWORD --no-auth-warning ping 2>$null) -join "")
    } else {
        $pong = (@(& docker compose exec -T redis redis-cli ping 2>$null) -join "")
    }
    if ($pong -match "PONG") { Ok "redis PING ($redisMode)" }
    elseif ($pong -match "NOAUTH|WRONGPASS|ERR") { Bad "redis PING" "$redisMode`: $(Shorten $pong) - REDIS_PASSWORD does not match what the server was started with" }
    else { Bad "redis PING" "$redisMode`: reply='$(Shorten $pong)' - docker compose logs redis | Select-Object -Last 20" }

    Write-Host "== result: $pass passed, $fail failed, $warn warnings =="
}
finally {
    Pop-Location
}

if ($fail -gt 0) { exit 1 }
exit 0
