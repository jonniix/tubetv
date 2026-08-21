$ErrorActionPreference = 'Stop'
$kitRoot = Split-Path -Parent $PSScriptRoot
$stateRoot = Join-Path $kitRoot 'STATO'
New-Item -ItemType Directory -Force -Path $stateRoot | Out-Null

$activeText = (& powercfg.exe /getactivescheme | Out-String)
$activeMatch = [regex]::Match($activeText, '[0-9a-fA-F-]{36}')
if ($activeMatch.Success) {
    $activeMatch.Value | Set-Content -LiteralPath (Join-Path $stateRoot 'profilo-energia-originale.txt') -Encoding ASCII
}

$duplicateText = (& powercfg.exe -duplicatescheme SCHEME_MIN | Out-String)
$duplicateMatch = [regex]::Match($duplicateText, '[0-9a-fA-F-]{36}')
if (-not $duplicateMatch.Success) { throw 'Impossibile creare il profilo energia TubeTV.' }
$tubeScheme = $duplicateMatch.Value

& powercfg.exe -changename $tubeScheme 'TubeTV Host' 'Profilo server sicuro e reversibile' | Out-Null
& powercfg.exe -setacvalueindex $tubeScheme SUB_SLEEP STANDBYIDLE 0 | Out-Null
& powercfg.exe -setacvalueindex $tubeScheme SUB_SLEEP HIBERNATEIDLE 0 | Out-Null
& powercfg.exe -setacvalueindex $tubeScheme SUB_VIDEO VIDEOIDLE 600 | Out-Null
& powercfg.exe -setactive $tubeScheme | Out-Null
$tubeScheme | Set-Content -LiteralPath (Join-Path $stateRoot 'profilo-energia-tubetv.txt') -Encoding ASCII

Write-Host 'Profilo TubeTV Host attivo.' -ForegroundColor Green
Write-Host 'A corrente: nessuna sospensione; schermo spento dopo 10 minuti.'
Write-Host 'Non sono stati disattivati antivirus, aggiornamenti o servizi Windows.'

