# AIDataPlatform native Windows service manager — STATUS.
# Usage: powershell -ExecutionPolicy Bypass -File .\dev\status.ps1
# Read-only: never starts, stops, or modifies anything. Checks live state
# (process + HTTP health/readiness), never just open ports.
. "$PSScriptRoot\common.ps1"

$cfg = Get-ProjectConfig

Write-Host ''
Write-Host 'AIDATAPLATFORM SERVICE STATUS' -ForegroundColor Cyan
Write-Host ''

function Show-Managed([string]$Name, [string]$Match, [string]$Label) {
    $procId = Get-ManagedPid $Name
    if ($null -eq $procId) {
        Write-StatusLine 'WARN' $Label 'not started by manager (no pidfile)'
        return
    }
    if (Test-PidAlive $procId $Match) { Write-StatusLine 'OK' $Label "PID $procId alive" }
    else { Write-StatusLine 'ERROR' $Label "PID $procId dead" }
}

# Laravel/Web (Laragon-served)
$web = Invoke-HttpJson ($cfg.AppUrl + '/up') 5
if ($web.ok) { Write-StatusLine 'OK' 'Laravel/Web' ("HTTP $($web.status) @ $($cfg.AppUrl), $($web.ms)ms") }
else { Write-StatusLine 'ERROR' 'Laravel/Web' ("unreachable @ $($cfg.AppUrl)") }

# MySQL (check only)
if (Test-TcpPort '127.0.0.1' 3306) { Write-StatusLine 'OK' 'MySQL' '127.0.0.1:3306 reachable' }
else { Write-StatusLine 'ERROR' 'MySQL' '127.0.0.1:3306 refused (start Laragon MySQL)' }

# Redis (check only)
if (Test-TcpPort '127.0.0.1' 6379) { Write-StatusLine 'OK' 'Redis' '127.0.0.1:6379 reachable' }
else { Write-StatusLine 'ERROR' 'Redis' '127.0.0.1:6379 refused' }

# AI Engine (process + health + readiness)
Show-Managed 'ai-engine' 'uvicorn app.main:app' 'AI Engine'
$health = Invoke-HttpJson ("http://127.0.0.1:$($cfg.EnginePort)/api/v1/health") 10
if ($health.ok -and $health.body.status -eq 'ok') {
    Write-StatusLine 'OK' 'AI Engine health' ("$($health.body.app)/$($health.body.version) $($health.body.env), $($health.ms)ms")
} else { Write-StatusLine 'ERROR' 'AI Engine health' 'unhealthy or unreachable' }
$ready = Invoke-HttpJson ("http://127.0.0.1:$($cfg.EnginePort)/api/v1/readiness") 10
if ($ready.ok -and $ready.body.ready -eq $true) {
    Write-StatusLine 'OK' 'AI Engine readiness' ($ready.body.checks | ConvertTo-Json -Compress)
} else { Write-StatusLine 'WARN' 'AI Engine readiness' 'not ready' }

# Celery worker (process + inspect ping when possible)
Show-Managed 'celery-worker' 'celery' 'Celery Worker'
$inspectOut = ''
try {
    $psi = New-Object Diagnostics.ProcessStartInfo
    $psi.FileName = $cfg.Python
    $psi.Arguments = '-m celery -A app.workers.celery_app.celery_app inspect ping'
    $psi.WorkingDirectory = (Join-Path $cfg.Root 'ai-engine')
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    foreach ($k in @('CELERY_BROKER_URL','REDIS_URL','CELERY_RESULT_BACKEND')) {
        $v = [Environment]::GetEnvironmentVariable($k, 'Process')
        if ([string]::IsNullOrWhiteSpace($v)) {
            $line = Select-String -LiteralPath (Join-Path $cfg.Root 'ai-engine\.env') -Pattern "^$k=" -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($line) { $psi.Environment[$k] = ($line.Line -split '=', 2)[1] }
        }
    }
    $proc = [Diagnostics.Process]::Start($psi)
    if ($proc.WaitForExit(20000)) { $inspectOut = $proc.StandardOutput.ReadToEnd() } else { try { $proc.Kill() } catch { } }
} catch { $inspectOut = '' }
if ($inspectOut -match 'pong') { Write-StatusLine 'OK' 'Celery heartbeat' 'inspect ping: pong' }
else { Write-StatusLine 'WARN' 'Celery heartbeat' 'no pong (worker may be starting, or broker unreachable)' }

# Celery beat
Show-Managed 'celery-beat' 'celery' 'Celery Beat'

# Ollama (report only)
$ollama = Invoke-HttpJson 'http://127.0.0.1:11434/api/tags' 3
if ($ollama.ok) { Write-StatusLine 'OK' 'Ollama' 'already running' }
else { Write-StatusLine 'WARN' 'Ollama' 'not running (optional)' }

# Laravel queue + scheduler (managed)
Show-Managed 'laravel-queue' 'queue:work' 'Laravel Queue'
Show-Managed 'laravel-schedule' 'schedule:work' 'Laravel Scheduler'

Write-Host ''
