$ErrorActionPreference = 'Stop'
$kitRoot = Split-Path -Parent $PSScriptRoot
$stateRoot = Join-Path $kitRoot 'STATO'
$originalPath = Join-Path $stateRoot 'profilo-energia-originale.txt'
$tubePath = Join-Path $stateRoot 'profilo-energia-tubetv.txt'
if (-not (Test-Path -LiteralPath $originalPath)) { throw 'Profilo energia originale non trovato.' }
$original = (Get-Content -LiteralPath $originalPath -Raw).Trim()
& powercfg.exe -setactive $original | Out-Null
if (Test-Path -LiteralPath $tubePath) {
    $tube = (Get-Content -LiteralPath $tubePath -Raw).Trim()
    if ($tube -match '^[0-9a-fA-F-]{36}$' -and $tube -ne $original) { & powercfg.exe -delete $tube | Out-Null }
}
Write-Host 'Profilo energia originale ripristinato.' -ForegroundColor Green

