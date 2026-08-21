$ErrorActionPreference = 'Stop'
$kitRoot = Split-Path -Parent $PSScriptRoot
$backupRoot = Join-Path $kitRoot 'BACKUP-PRIVATO'
$driverRoot = Join-Path $backupRoot 'Driver'
$wifiRoot = Join-Path $backupRoot 'WiFi'

if (-not (Test-Path -LiteralPath $backupRoot)) {
    throw 'BACKUP-PRIVATO non trovato. Esegui prima il backup sul vecchio Windows.'
}

if (Test-Path -LiteralPath $driverRoot) {
    Write-Host 'Ripristino driver...' -ForegroundColor Cyan
    & pnputil.exe /add-driver (Join-Path $driverRoot '*.inf') /subdirs /install | Out-Host
}

if (Test-Path -LiteralPath $wifiRoot) {
    Write-Host 'Ripristino profili Wi-Fi...' -ForegroundColor Cyan
    Get-ChildItem -LiteralPath $wifiRoot -Filter '*.xml' | ForEach-Object {
        & netsh.exe wlan add profile filename="$($_.FullName)" user=all | Out-Host
    }
}

Write-Host 'Ripristino completato. Verifica la connessione, poi elimina BACKUP-PRIVATO se non serve più.' -ForegroundColor Green

