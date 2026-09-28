# AIDataPlatform healthcheck for Windows PowerShell (mirrors healthcheck.sh).
# Usage: powershell -ExecutionPolicy Bypass -File infrastructure/scripts/healthcheck.ps1
$ErrorActionPreference = "Continue"
$pass = 0; $fail = 0
function Ok($m)   { Write-Host "  [OK]   $m" -ForegroundColor Green; $script:pass++ }
function Bad($m,$h) { Write-Host "  [FAIL] $m -- $h" -ForegroundColor Red; $script:fail++ }

Write-Host "== AIDataPlatform healthcheck (PowerShell) =="

try { docker compose exec -T laravel curl -fsS --max-time 10 http://localhost:8000/up | Out-Null; Ok "laravel /up" }
catch { Bad "laravel /up" "docker compose logs laravel | tail" }

try { docker compose exec -T fastapi python -c "import urllib.request; assert urllib.request.urlopen('http://localhost:8000/api/v1/health', timeout=10).status==200"; Ok "fastapi /api/v1/health" }
catch { Bad "fastapi /api/v1/health" "docker compose logs fastapi | tail" }

try { docker compose exec -T nginx wget -qO- http://localhost/health | Out-Null; Ok "nginx /health" }
catch { Bad "nginx /health" "docker compose logs nginx | tail" }

try { docker compose exec -T postgres pg_isready | Out-Null; Ok "postgres pg_isready" }
catch { Bad "postgres pg_isready" "docker compose logs postgres | tail" }

try { $p = docker compose exec -T redis redis-cli ping; if ($p -match "PONG") { Ok "redis PING" } else { Bad "redis PING" "check REDIS_PASSWORD" } }
catch { Bad "redis PING" "docker compose logs redis | tail" }

Write-Host "== result: $pass passed, $fail failed =="
if ($fail -gt 0) { exit 1 }
