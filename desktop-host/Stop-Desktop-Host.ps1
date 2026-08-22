$ErrorActionPreference = 'SilentlyContinue'
$projectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$pidPath = Join-Path $projectRoot '.tubetv-local\desktop-host\gateway.pid'
if (Test-Path -LiteralPath $pidPath) {
    $serverPid = [int](Get-Content -LiteralPath $pidPath)
    Stop-Process -Id $serverPid -Force
    Remove-Item -LiteralPath $pidPath -Force
}
Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like '*desktop-host*' -or $_.CommandLine -like '*127.0.0.1:881*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
Write-Host 'TubeTV Desktop Host fermato.'
