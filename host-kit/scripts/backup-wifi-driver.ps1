$ErrorActionPreference = 'Stop'
$kitRoot = Split-Path -Parent $PSScriptRoot
$backupRoot = Join-Path $kitRoot 'BACKUP-PRIVATO'
$driverRoot = Join-Path $backupRoot 'Driver'
$wifiRoot = Join-Path $backupRoot 'WiFi'
New-Item -ItemType Directory -Force -Path $driverRoot, $wifiRoot | Out-Null

Write-Host 'Esporto i driver Windows...' -ForegroundColor Cyan
Export-WindowsDriver -Online -Destination $driverRoot | Out-Null

Write-Host 'Esporto i profili Wi-Fi...' -ForegroundColor Cyan
& netsh.exe wlan export profile key=clear folder="$wifiRoot" | Out-Host

@(
    "Creato: $(Get-Date -Format s)"
    "Computer: $env:COMPUTERNAME"
    'ATTENZIONE: i file Wi-Fi possono contenere password in chiaro.'
) | Set-Content -LiteralPath (Join-Path $backupRoot 'LEGGIMI-SICUREZZA.txt') -Encoding UTF8

Write-Host "Backup completato in $backupRoot" -ForegroundColor Green

