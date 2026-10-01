# AIDataPlatform native Windows service manager — START.
# Usage: powershell -ExecutionPolicy Bypass -File .\dev\start-all.ps1
# Starts ONLY what this project needs and Laragon does not already manage.
# Laragon-owned Apache/Nginx/MySQL are checked, never started or stopped.
. "$PSScriptRoot\common.ps1"

$cfg = Get-ProjectConfig
$failures = 0

Write-Host ''
Write-Host 'AIDataPlatform' -ForegroundColor Cyan
Write-Host 'Native Windows Service Manager'
Write-Host ''

function Assert-Ok([bool]$Cond, [string]$Name, [string]$Detail = '') {
    if ($Cond) { Write-StatusLine 'OK' $Name $Detail }
    else { Write-StatusLine 'ERROR' $Name $Detail; $script:failures++ }
}

function Start-Tracked([string]$Name, [string]$Exe, [string[]]$Arguments, [string]$WorkDir, [string]$LogFile, [string]$MustContain) {
    $existing = Get-ManagedPid $Name
    if ($null -ne $existing -and (Test-PidAlive $existing $MustContain)) {
        Write-StatusLine 'OK' $Name "already running (PID $existing)"
        return $true
    }
    if ($null -ne $existing) { Clear-ManagedPid $Name }
    if ([string]::IsNullOrWhiteSpace($Exe) -or -not (Test-Path -LiteralPath $Exe)) {
        Write-StatusLine 'ERROR' $Name "executable not found: $Exe"
        $script:failures++
        return $false
    }
    $logDir = Split-Path -Parent $LogFile
    if (-not (Test-Path -LiteralPath $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
    $p = Start-Process -FilePath $Exe -ArgumentList $Arguments -WorkingDirectory $WorkDir `
        -RedirectStandardOutput $LogFile -RedirectStandardError ($LogFile -replace '\.log$', '.err.log') `
        -WindowStyle Hidden -PassThru
    Set-ManagedPid $Name $p.Id
    Write-ManagerLog("started $Name pid=$($p.Id) cmd=$Exe $($Arguments -join ' ')")
    return $true
}

# ---- 0. Preconditions (checked, never installed/started blindly) ----
Assert-Ok (Test-Path -LiteralPath $cfg.Python) 'Python (.venv)' $cfg.Python
if (-not (Test-Path -LiteralPath $cfg.Python)) { exit 1 }
Assert-Ok ($cfg.Php -ne '') 'PHP (Laragon)' $cfg.Php
Assert-Ok (Test-Path -LiteralPath (Join-Path $cfg.Root 'ai-engine\app\main.py')) 'FastAPI entrypoint' 'app/main.py:create_app'
Assert-Ok (Test-Path -LiteralPath (Join-Path $cfg.Root 'application\artisan')) 'Laravel artisan' 'application/artisan'

# ---- 1. Laragon-managed: Apache/Nginx + MySQL (check only) ----
$web = Invoke-HttpJson ($cfg.AppUrl + '/up') 5
Assert-Ok $web.ok 'Laravel / Laragon' ("HTTP $($web.status) @ $($cfg.AppUrl)")
Assert-Ok (Test-TcpPort '127.0.0.1' 3306) 'MySQL' '127.0.0.1:3306'

# ---- 2. Redis (start only if we must; track only if we did) ----
$redisOurs = $false
$staleRedis = Get-ManagedPid 'redis'
if ($null -ne $staleRedis -and -not (Test-PidAlive $staleRedis 'redis-server')) { Clear-ManagedPid 'redis' }
if (Test-TcpPort '127.0.0.1' 6379) {
    Write-StatusLine 'OK' 'Redis' '127.0.0.1:6379 already listening'
} elseif ($cfg.RedisServer -ne '' -and (Get-ManagedPid 'redis') -eq $null) {
    if (Start-Tracked 'redis' $cfg.RedisServer @('--port', '6379') $cfg.Root (Join-Path $cfg.Root 'ai-engine\logs\redis.log') 'redis-server') {
        Start-Sleep -Seconds 2
        if (Test-TcpPort '127.0.0.1' 6379) { $redisOurs = $true; Write-StatusLine 'OK' 'Redis' 'started by manager' }
        else { Write-StatusLine 'ERROR' 'Redis' 'process started but port closed'; $script:failures++ }
    }
} else {
    Write-StatusLine 'ERROR' 'Redis' 'port closed and no redis-server found (Laragon Redis or REDIS_SERVER env)'
    $script:failures++
}
# ---- 3. AI Engine (uvicorn app.main:app) ----
$engineAlive = Invoke-HttpJson ("http://127.0.0.1:$($cfg.EnginePort)/api/v1/health") 5
if ($engineAlive.ok -and $engineAlive.body.status -eq 'ok' -and (Get-ManagedPid 'ai-engine') -eq $null) {
    Write-StatusLine 'WARN' 'AI Engine' 'healthy instance already serving (unmanaged) -- not starting a duplicate'
} elseif (Adopt-OrBlock 'ai-engine' 'uvicorn app.main:app') {
    Start-Tracked 'ai-engine' $cfg.Python @('-m', 'uvicorn', 'app.main:app', '--host', $cfg.EngineHost, '--port', "$($cfg.EnginePort)") `
        (Join-Path $cfg.Root 'ai-engine') (Join-Path $cfg.Root 'ai-engine\logs\uvicorn.log') 'uvicorn app.main:app' | Out-Null
    Start-Sleep -Seconds 6
}
$health = Invoke-HttpJson ("http://127.0.0.1:$($cfg.EnginePort)/api/v1/health") 10
Assert-Ok ($health.ok -and $health.body.status -eq 'ok') 'AI Engine' ("health @ 127.0.0.1:$($cfg.EnginePort), $($health.ms)ms")
$ready = Invoke-HttpJson ("http://127.0.0.1:$($cfg.EnginePort)/api/v1/readiness") 10
if ($ready.ok -and $ready.body.ready -eq $true) { Write-StatusLine 'OK' 'AI Engine readiness' ($ready.body.checks | ConvertTo-Json -Compress) }
else { Write-StatusLine 'WARN' 'AI Engine readiness' 'not ready yet (db/redis?)'; }

# ---- 4. Celery worker + beat (separate processes) ----
if (Adopt-OrBlock 'celery-worker' 'celery_app.celery_app worker') {
    Start-Tracked 'celery-worker' $cfg.Python @('-m', 'celery', '-A', 'app.workers.celery_app.celery_app', 'worker', '--loglevel=info', '--concurrency=' + $cfg.Concurrency, '-P', 'solo', '-Q', $cfg.Queues) `
        (Join-Path $cfg.Root 'ai-engine') (Join-Path $cfg.Root 'ai-engine\logs\celery-worker.log') 'celery_app' | Out-Null
}
$wPid = Get-ManagedPid 'celery-worker'
Assert-Ok (($null -ne $wPid) -and (Test-PidAlive $wPid 'celery')) 'Celery Worker' ("PID $wPid, Q: $($cfg.Queues)")
if (Adopt-OrBlock 'celery-beat' 'celery_app.celery_app beat') {
    Start-Tracked 'celery-beat' $cfg.Python @('-m', 'celery', '-A', 'app.workers.celery_app.celery_app', 'beat', '--loglevel=info') `
        (Join-Path $cfg.Root 'ai-engine') (Join-Path $cfg.Root 'ai-engine\logs\celery-beat.log') 'celery_app' | Out-Null
}
$bPid = Get-ManagedPid 'celery-beat'
Assert-Ok (($null -ne $bPid) -and (Test-PidAlive $bPid 'celery')) 'Celery Beat' ("PID $bPid")

# ---- 5. Laravel queue + scheduler (managed, Laragon serves HTTP only) ----
if ($cfg.Php -ne '') {
    if (Adopt-OrBlock 'laravel-queue' 'artisan queue:work') {
        Start-Tracked 'laravel-queue' $cfg.Php @((Join-Path $cfg.Root 'application\artisan'), 'queue:work', '--queue=datasets,default', '--tries=3', '--backoff=30', '--max-time=3600') `
            (Join-Path $cfg.Root 'application') (Join-Path $cfg.Root 'ai-engine\logs\laravel-queue.log') 'queue:work' | Out-Null
    }
    $qPid = Get-ManagedPid 'laravel-queue'
    Assert-Ok (($null -ne $qPid) -and (Test-PidAlive $qPid 'artisan')) 'Laravel Queue' ("PID $qPid")
    if (Adopt-OrBlock 'laravel-schedule' 'artisan schedule:work') {
        Start-Tracked 'laravel-schedule' $cfg.Php @((Join-Path $cfg.Root 'application\artisan'), 'schedule:work', '--whisper') `
            (Join-Path $cfg.Root 'application') (Join-Path $cfg.Root 'ai-engine\logs\laravel-schedule.log') 'schedule:work' | Out-Null
    }
    $sPid = Get-ManagedPid 'laravel-schedule'
    Assert-Ok (($null -ne $sPid) -and (Test-PidAlive $sPid 'artisan')) 'Laravel Scheduler' ("PID $sPid")
}

# ---- 6. Ollama (report only, never started/stopped) ----
$ollama = Invoke-HttpJson 'http://127.0.0.1:11434/api/tags' 3
if ($ollama.ok) { Write-StatusLine 'OK' 'Ollama' 'already running' }
else { Write-StatusLine 'WARN' 'Ollama' 'not running (optional; start it yourself if needed)' }

Write-Host ''
if ($failures -eq 0) { Write-Host 'All required services are healthy.' -ForegroundColor Green }
else { Write-Host "$failures check(s) failed -- see lines above." -ForegroundColor Red; exit 1 }
