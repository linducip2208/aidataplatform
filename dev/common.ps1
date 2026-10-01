# AIDataPlatform native Windows service manager — shared helpers.
# Dot-source from the sibling scripts, never run directly.
# No secrets here: everything comes from application/.env, ai-engine/.env
# or environment variables. Nothing in this file is ever committed with values.

$ErrorActionPreference = 'Stop'

$Script:Root = Split-Path -Parent $PSScriptRoot
$Script:RuntimeDir = Join-Path $PSScriptRoot 'runtime'
$Script:EngineLogDir = Join-Path $Script:Root 'ai-engine\logs'

function Read-DotEnvValue([string]$File, [string]$Key) {
    if (-not (Test-Path -LiteralPath $File)) { return '' }
    foreach ($line in [System.IO.File]::ReadAllLines($File)) {
        $t = $line.Trim()
        if ($t -eq '' -or $t.StartsWith('#')) { continue }
        $i = $t.IndexOf('=')
        if ($i -lt 1) { continue }
        if ($t.Substring(0, $i).Trim() -eq $Key) {
            $v = $t.Substring($i + 1).Trim()
            if ($v.Length -ge 2 -and $v.StartsWith('"') -and $v.EndsWith('"')) {
                $v = $v.Substring(1, $v.Length - 2)
            }
            return $v
        }
    }
    return ''
}

function Get-ProjectConfig {
    $appEnv = Join-Path $Script:Root 'application\.env'
    $engineEnv = Join-Path $Script:Root 'ai-engine\.env'
    $aiUrl = Read-DotEnvValue $appEnv 'AI_ENGINE_URL'
    if ([string]::IsNullOrWhiteSpace($aiUrl)) { $aiUrl = 'http://127.0.0.1:8001' }
    $u = [System.Uri]$aiUrl
    $appUrl = Read-DotEnvValue $appEnv 'APP_URL'
    if ([string]::IsNullOrWhiteSpace($appUrl)) { $appUrl = 'http://aidataplatform.test' }
    $queues = $env:CELERY_QUEUES
    if ([string]::IsNullOrWhiteSpace($queues)) { $queues = 'default,imports,quality,ml,agent,rag' }
    $concurrency = $env:CELERY_CONCURRENCY
    if ([string]::IsNullOrWhiteSpace($concurrency)) { $concurrency = '2' }
    return [pscustomobject]@{
        Root = $Script:Root
        AppEnv = $appEnv
        EngineEnv = $engineEnv
        EngineHost = $u.Host
        EnginePort = $u.Port
        EngineUrl = $aiUrl.TrimEnd('/')
        AppUrl = $appUrl.TrimEnd('/')
        Python = Join-Path $Script:Root '.venv\Scripts\python.exe'
        Php = if ($env:PHP_BIN) { $env:PHP_BIN } else { Find-LaragonPhp }
        Queues = $queues
        Concurrency = $concurrency
        RedisServer = if ($env:REDIS_SERVER) { $env:REDIS_SERVER } else { Find-RedisServer }
    }
}

function Find-LaragonPhp {
    $dir = 'D:\laragon\bin\php'
    if (-not (Test-Path -LiteralPath $dir)) { return '' }
    $best = Get-ChildItem -LiteralPath $dir -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^php-8\.[3-9]' } |
        Sort-Object Name -Descending | Select-Object -First 1
    if ($null -eq $best) { return '' }
    $exe = Join-Path $best.FullName 'php.exe'
    if (Test-Path -LiteralPath $exe) { return $exe }
    return ''
}

function Find-RedisServer {
    $candidates = @('D:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe')
    foreach ($c in $candidates) {
        if (Test-Path -LiteralPath $c) { return $c }
    }
    $cmd = Get-Command redis-server.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    return ''
}

function Test-TcpPort([string]$TargetHost, [int]$Port, [int]$TimeoutMs = 1500) {
    try {
        $c = New-Object Net.Sockets.TcpClient
        $r = $c.BeginConnect($TargetHost, $Port, $null, $null)
        if (-not $r.AsyncWaitHandle.WaitOne($TimeoutMs)) { $c.Close(); return $false }
        $c.EndConnect($r); $c.Close(); return $true
    } catch { return $false }
}

function Invoke-HttpJson([string]$Url, [int]$TimeoutSec = 5) {
    $sw = [Diagnostics.Stopwatch]::StartNew()
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec $TimeoutSec
        $sw.Stop()
        $body = $null
        try { $body = $r.Content | ConvertFrom-Json } catch { $body = $r.Content }
        return @{ ok = ($r.StatusCode -ge 200 -and $r.StatusCode -lt 300); status = $r.StatusCode; ms = [int]$sw.ElapsedMilliseconds; body = $body }
    } catch {
        $sw.Stop()
        return @{ ok = $false; status = 0; ms = [int]$sw.ElapsedMilliseconds; body = $null; error = $_.Exception.Message }
    }
}

function Get-ManagedPid([string]$Name) {
    $f = Join-Path $Script:RuntimeDir "$Name.pid"
    if (-not (Test-Path -LiteralPath $f)) { return $null }
    try { return [int]((Get-Content -LiteralPath $f -Raw).Trim()) } catch { return $null }
}

function Set-ManagedPid([string]$Name, [int]$procId) {
    if (-not (Test-Path -LiteralPath $Script:RuntimeDir)) {
        New-Item -ItemType Directory -Path $Script:RuntimeDir -Force | Out-Null
    }
    Set-Content -LiteralPath (Join-Path $Script:RuntimeDir "$Name.pid") -Value "$procId" -NoNewline
}

function Clear-ManagedPid([string]$Name) {
    $f = Join-Path $Script:RuntimeDir "$Name.pid"
    if (Test-Path -LiteralPath $f) { Remove-Item -LiteralPath $f -Force }
}

function Test-PidAlive([int]$procId, [string]$MustContain = '') {
    try {
        $p = Get-Process -Id $procId -ErrorAction Stop
    } catch { return $false }
    if ($MustContain -ne '') {
        try {
            $w = Get-CimInstance Win32_Process -Filter "ProcessId=$procId" -ErrorAction Stop
            if ($w.CommandLine -notlike "*$MustContain*") { return $false }
        } catch { return $false }
    }
    return -not $p.HasExited
}

function Find-ForeignProcess([string]$MustContain) {
    # Processes whose command line matches but that this manager does not
    # track (no pidfile). Returns their PIDs. Never kills anything.
    $found = @()
    try {
        foreach ($w in (Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object { $_.CommandLine -like "*$MustContain*" })) {
            $found += [int]$w.ProcessId
        }
    } catch { }
    return $found
}

function Adopt-OrBlock([string]$Name, [string]$MustContain) {
    # Returns $true when the caller may start a fresh instance.
    # A single untracked instance with OUR command line is adopted (pidfile
    # written, reported as WARN); several are left alone with an ERROR so the
    # operator cleans up instead of us guessing which one is ours.
    if ((Get-ManagedPid $Name) -ne $null) { return $true }
    $foreign = @(Find-ForeignProcess $MustContain)
    if ($foreign.Count -eq 0) { return $true }
    if ($foreign.Count -eq 1) {
        Set-ManagedPid $Name $foreign[0]
        Write-StatusLine 'WARN' $Name "adopted untracked process (PID $($foreign[0]))"
        Write-ManagerLog("adopted $Name pid=$($foreign[0])")
        return $false
    }
    Write-StatusLine 'ERROR' $Name ("$($foreign.Count) untracked instances (PIDs $($foreign -join ',')): stop them manually first")
    return $false
}

function Stop-ManagedProcess([string]$Name, [string]$MustContain, [int]$GraceMs = 8000) {
    $procId = Get-ManagedPid $Name
    if ($null -eq $procId) { return 'not-tracked' }
    if (-not (Test-PidAlive $procId $MustContain)) { Clear-ManagedPid $Name; return 'already-dead' }
    try { Stop-Process -Id $procId -Force:$false -ErrorAction Stop } catch { }
    $waited = 0
    while ($waited -lt $GraceMs) {
        Start-Sleep -Milliseconds 500; $waited += 500
        if (-not (Test-PidAlive $procId)) { break }
    }
    if (Test-PidAlive $procId) {
        try { Stop-Process -Id $procId -Force -ErrorAction Stop } catch { }
        Start-Sleep -Milliseconds 800
    }
    if (Test-PidAlive $procId) { return 'still-alive' }
    Clear-ManagedPid $Name
    return 'stopped'
}

function Write-ManagerLog([string]$Message) {
    if (-not (Test-Path -LiteralPath $Script:EngineLogDir)) {
        New-Item -ItemType Directory -Path $Script:EngineLogDir -Force | Out-Null
    }
    Add-Content -LiteralPath (Join-Path $Script:EngineLogDir 'manager.log') -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message)
}

function Write-StatusLine([string]$State, [string]$Name, [string]$Detail = '') {
    $color = switch ($State) { 'OK' { 'Green' } 'WARN' { 'Yellow' } 'ERROR' { 'Red' } default { 'Gray' } }
    Write-Host ("[{0}] " -f $State) -NoNewline -ForegroundColor $color
    if ($Detail -ne '') { Write-Host ("{0} - {1}" -f $Name, $Detail) }
    else { Write-Host $Name }
}
