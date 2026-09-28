# AIDataPlatform healthcheck for Windows PowerShell.
# Mirrors infrastructure/scripts/healthcheck.sh.
#
# Usage: powershell -ExecutionPolicy Bypass -File infrastructure/scripts/healthcheck.ps1
#
# Note: try/catch does not catch a native command exiting non-zero, so every
# probe below inspects $LASTEXITCODE explicitly. The previous version used
# try/catch around `docker compose exec`, which reported OK even when the probe
# failed.
$ErrorActionPreference = "Continue"

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
Push-Location $repoRoot
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

$script:pass = 0
$script:fail = 0
$script:warn = 0
function Ok($m)   { Write-Host "  [OK]   $m" -ForegroundColor Green; $script:pass++ }
function Bad($m, $h) { Write-Host "  [FAIL] $m -- $h" -ForegroundColor Red; $script:fail++ }
function Warn($m, $h) { Write-Host "  [WARN] $m -- $h" -ForegroundColor Yellow; $script:warn++ }

function Get-Code { param([string[]]$Args)
    $null = & docker compose @Args
    return $LASTEXITCODE
}

Write-Host "== AIDataPlatform healthcheck (PowerShell) =="

# 1) Laravel framework probe
Write-Host "-- laravel (GET /up) --"
$code = Get-Code @("exec", "-T", "laravel", "curl", "-fsS", "-o", "/dev/null", "--max-time", "10", "http://localhost:8000/up")
if ($code -eq 0) { Ok "laravel /up" } else { Bad "laravel /up" "docker compose logs laravel | Select-Object -Last 50" }

# 2) Laravel token API
Write-Host "-- laravel token API (POST /api/login, GET /api/me) --"
$code = Get-Code @(
    "exec", "-T", "laravel", "curl", "-sS", "-o", "/tmp/aidata-login.json", "-w", "%{http_code}",
    "--max-time", "15", "-X", "POST", "http://localhost:8000/api/login",
    "-H", "Content-Type: application/json",
    "-d", '{"email":"admin@example.com","password":"Admin123!"}'
)
$loginCode = ($code -replace "\D", "")
if ($loginCode -eq "200") {
    $body = (& docker compose exec -T laravel cat /tmp/aidata-login.json 2>$null) -join ""
    $m = [regex]::Match($body, '"token"\s*:\s*"([^"]+)"')
    if ($m.Success) {
        $token = $m.Groups[1].Value
        $code = Get-Code @("exec", "-T", "laravel", "curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}",
            "--max-time", "15", "http://localhost:8000/api/me", "-H", "Authorization: Bearer $token")
        if (("$code" -replace "\D", "") -eq "200") { Ok "laravel token API (login + /api/me)" }
        else { Bad "GET /api/me" "http=$code" }
    } else {
        Warn "POST /api/login" "200 but no token in the response body"
    }
} elseif ($loginCode -in @("422", "401")) {
    Warn "POST /api/login" "http=$loginCode (no seeded admin@example.com? run: docker compose exec laravel php artisan db:seed --force)"
} elseif ($loginCode -eq "000" -or $code -eq 0) {
    Bad "POST /api/login" "laravel unreachable"
} else {
    Bad "POST /api/login" "http=$loginCode"
}

# 3) Engine liveness
Write-Host "-- engine (GET /api/v1/health) --"
$code = Get-Code @("exec", "-T", "fastapi", "python", "-c",
    "import urllib.request; assert urllib.request.urlopen('http://localhost:8000/api/v1/health', timeout=10).status==200")
if ($code -eq 0) { Ok "engine /api/v1/health" } else { Bad "engine /api/v1/health" "docker compose logs fastapi | Select-Object -Last 50" }

# 4) Engine dependencies -- /api/v1/readiness answers 200 even when not ready,
#    so the body has to be read.
Write-Host "-- engine (GET /api/v1/readiness) --"
$readiness = (& docker compose exec -T fastapi python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8000/api/v1/readiness', timeout=15).read().decode())" 2>$null) -join ""
if ($LASTEXITCODE -eq 0) {
    if ($readiness -match '"ready"\s*:\s*true') { Ok "engine /api/v1/readiness ready=true" }
    elseif ($readiness -match '"ready"\s*:\s*false') { Bad "engine /api/v1/readiness" "ready=false; $readiness" }
    else { Warn "engine /api/v1/readiness" "unparsable body: $readiness" }
} else {
    Bad "engine /api/v1/readiness" "request failed"
}

# 5) Nginx prefix strip -- proves /ai-api/ is rewritten to /api/v1/
Write-Host "-- nginx (GET /ai-api/api/v1/health via the /ai-api prefix) --"
$viaNginx = (& docker compose exec -T nginx wget -qO- --timeout=10 http://localhost/ai-api/api/v1/health 2>$null) -join ""
if ($LASTEXITCODE -eq 0 -and $viaNginx -match '"status"') { Ok "nginx /ai-api/ prefix is stripped to /api/v1/" }
else { Bad "nginx /ai-api/api/v1/health" "docker compose logs nginx | Select-Object -Last 20" }

# 6) Nginx liveness
Write-Host "-- nginx (GET /health) --"
$code = Get-Code @("exec", "-T", "nginx", "wget", "-qO-", "--timeout=10", "http://localhost/health")
if ($code -eq 0) { Ok "nginx /health" } else { Bad "nginx /health" "docker compose logs nginx | Select-Object -Last 20" }

# 7) Postgres
Write-Host "-- postgres (pg_isready) --"
$pgUser = if ($env:POSTGRES_USER) { $env:POSTGRES_USER } else { "aidata" }
$pgDb = if ($env:POSTGRES_DB) { $env:POSTGRES_DB } else { "aidata" }
$code = Get-Code @("exec", "-T", "postgres", "pg_isready", "-U", $pgUser, "-d", $pgDb)
if ($code -eq 0) { Ok "postgres pg_isready ($pgUser/$pgDb)" } else { Bad "postgres pg_isready ($pgUser/$pgDb)" "docker compose logs postgres | Select-Object -Last 30" }

# 8) Redis -- redis-cli needs the password whenever REDIS_PASSWORD is set
Write-Host "-- redis (PING) --"
if ($env:REDIS_PASSWORD) {
    $pong = (& docker compose exec -T redis redis-cli -a $env:REDIS_PASSWORD --no-auth-warning ping 2>$null) -join ""
} else {
    $pong = (& docker compose exec -T redis redis-cli ping 2>$null) -join ""
}
if ($pong -match "PONG") { Ok "redis PING" } else { Bad "redis PING" "reply='$pong'; docker compose logs redis | Select-Object -Last 20" }

# 9) Celery
Write-Host "-- celery --"
foreach ($svc in @("celery-worker", "celery-beat")) {
    $status = (& docker compose ps $svc --format "{{.Status}}" 2>$null) -join ""
    if ($status -match "Up|running") { Ok "$svc $status" } else { Bad $svc "status='$status'; docker compose logs $svc | Select-Object -Last 20" }
}

Write-Host "== result: $pass passed, $fail failed, $warn warnings =="
Pop-Location
if ($fail -gt 0) { exit 1 }
exit 0
