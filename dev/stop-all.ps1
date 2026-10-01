# AIDataPlatform native Windows service manager — STOP.
# Usage: powershell -ExecutionPolicy Bypass -File .\dev\stop-all.ps1
# Stops ONLY processes this manager started (tracked PID files).
# Laragon-managed Apache/Nginx/MySQL and any foreign process are untouched.
# Ollama is never stopped unless this manager started it (tracked PID).
. "$PSScriptRoot\common.ps1"

Write-Host ''
Write-Host 'Stopping AIDataPlatform managed services...' -ForegroundColor Cyan
Write-Host ''

$order = @(
    @{ name = 'laravel-schedule'; match = 'schedule:work' },
    @{ name = 'laravel-queue'; match = 'queue:work' },
    @{ name = 'celery-beat'; match = 'celery' },
    @{ name = 'celery-worker'; match = 'celery' },
    @{ name = 'ai-engine'; match = 'uvicorn app.main:app' },
    @{ name = 'redis'; match = 'redis-server' }
)

foreach ($svc in $order) {
    $result = Stop-ManagedProcess $svc.name $svc.match
    switch ($result) {
        'stopped' { Write-StatusLine 'OK' $svc.name 'stopped'; Write-ManagerLog("stopped $($svc.name)") }
        'already-dead' { Write-StatusLine 'OK' $svc.name 'already dead (pidfile cleaned)' }
        'not-tracked' { Write-StatusLine 'OK' $svc.name 'not started by manager (skipped)' }
        'still-alive' { Write-StatusLine 'ERROR' $svc.name 'refused to stop (still alive)'; Write-ManagerLog("FAILED to stop $($svc.name)") }
    }
}

Write-Host ''
Write-Host 'Laragon-managed services were left untouched.' -ForegroundColor Gray
