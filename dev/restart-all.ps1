# AIDataPlatform native Windows service manager — RESTART.
# Usage: powershell -ExecutionPolicy Bypass -File .\dev\restart-all.ps1
# Stop (manager-tracked only) -> wait until clean -> start -> health checks.
& "$PSScriptRoot\stop-all.ps1"
Start-Sleep -Seconds 3
& "$PSScriptRoot\start-all.ps1"
