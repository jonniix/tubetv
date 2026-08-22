$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$runtimeRoot = Join-Path $projectRoot '.tubetv-local\desktop-host'
$appRoot = Join-Path $runtimeRoot 'app'
$privateRoot = Join-Path $appRoot 'private'
$pidPath = Join-Path $runtimeRoot 'gateway.pid'

if (Test-Path -LiteralPath $pidPath) {
    $oldPid = [int](Get-Content -LiteralPath $pidPath -ErrorAction SilentlyContinue)
    if (Get-Process -Id $oldPid -ErrorAction SilentlyContinue) {
        Write-Host 'TubeTV Desktop Host gia attivo.' -ForegroundColor Green
        exit 0
    }
}

$php = (Get-Command php.exe -ErrorAction Stop).Source
$node = (Get-Command node.exe -ErrorAction Stop).Source
$env:TUBETV_DESKTOP_APP = $appRoot
$env:TUBETV_DESKTOP_PHP = $php
$env:TUBETV_DESKTOP_PHP_INI = Join-Path $appRoot 'php.ini'
$env:TUBETV_DESKTOP_WORKERS = '8'
$env:TUBETV_DESKTOP_PORT = '8765'
$env:TUBETV_SEGMENT_CACHE_DIR = Join-Path $runtimeRoot 'segment-cache'
New-Item -ItemType Directory -Force -Path $privateRoot, $env:TUBETV_SEGMENT_CACHE_DIR | Out-Null

$process = Start-Process -FilePath $node -ArgumentList @((Join-Path $projectRoot 'desktop-host\gateway.js')) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeRoot 'gateway-out.log') -RedirectStandardError (Join-Path $runtimeRoot 'gateway-error.log')
$process.Id | Set-Content -LiteralPath $pidPath -Encoding ASCII
Start-Sleep -Seconds 2
$health = Invoke-RestMethod -UseBasicParsing 'http://127.0.0.1:8765/health' -TimeoutSec 5
if (-not $health.ok) { throw 'TubeTV Desktop Host non risponde.' }
Write-Host "TubeTV Desktop Host ATTIVO - PID $($process.Id)" -ForegroundColor Green
Write-Host 'Console: http://127.0.0.1:8765/dashboard'
