param([string]$SourceDrive = '')
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-Step([string]$message) { Write-Host "`n== $message ==" -ForegroundColor Cyan }
function Find-Executable([string[]]$names, [string[]]$paths) {
    foreach ($name in $names) {
        $command = Get-Command $name -ErrorAction SilentlyContinue
        if ($command) { return $command.Source }
    }
    foreach ($path in $paths) { if ($path -and (Test-Path -LiteralPath $path)) { return $path } }
    return ''
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Esegui questo installer come amministratore.'
}

$installRoot = Join-Path $env:ProgramData 'TubeTVHost'
$appRoot = Join-Path $installRoot 'app'
$apiRoot = Join-Path $appRoot 'api'
$privateRoot = Join-Path $appRoot 'private'
New-Item -ItemType Directory -Force -Path $installRoot, $appRoot, $apiRoot, $privateRoot | Out-Null

Write-Step 'Controllo componenti'
$winget = Find-Executable @('winget.exe') @((Join-Path $env:LOCALAPPDATA 'Microsoft\WindowsApps\winget.exe'))
$tailscale = Find-Executable @('tailscale.exe') @('C:\Program Files\Tailscale\tailscale.exe')
if (-not $tailscale) {
    if (-not $winget) { throw 'WinGet non è disponibile. Aggiorna App Installer da Microsoft Store e riprova.' }
    & $winget install --id Tailscale.Tailscale -e --source winget --accept-package-agreements --accept-source-agreements
    $tailscale = Find-Executable @('tailscale.exe') @('C:\Program Files\Tailscale\tailscale.exe')
}
if (-not $tailscale) { throw 'Installazione Tailscale non riuscita.' }

$php = Find-Executable @('php.exe') @(
    (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Links\php.exe'),
    'C:\Program Files\PHP\php.exe'
)
if (-not $php) {
    if (-not $winget) { throw 'PHP non trovato e WinGet non disponibile.' }
    & $winget install --id PHP.PHP.8.4 -e --source winget --accept-package-agreements --accept-source-agreements
    $php = Find-Executable @('php.exe') @(
        (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Links\php.exe'),
        'C:\Program Files\PHP\php.exe'
    )
    if (-not $php) {
        $php = Get-ChildItem (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages') -Filter php.exe -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
    }
}
if (-not $php) { throw 'Installazione PHP non riuscita.' }

Write-Step 'Scarico TubeTV Host'
$base = 'https://tubetv.online/host/agent'
$rawApi = 'https://raw.githubusercontent.com/jonniix/tubetv/main/api'
$downloads = @{
    (Join-Path $appRoot 'router.php') = "$base/router.php"
    (Join-Path $appRoot 'health.php') = "$base/health.php"
    (Join-Path $appRoot 'catalog.php') = "$base/catalog.php"
    (Join-Path $apiRoot 'iptv-lib.php') = "$rawApi/iptv-lib.php"
    (Join-Path $apiRoot 'iptv-stream.php') = "$rawApi/iptv-stream.php"
    (Join-Path $apiRoot 'iptv-transcode.php') = "$rawApi/iptv-transcode.php"
    (Join-Path $apiRoot 'iptv-epg.php') = "$rawApi/iptv-epg.php"
}
foreach ($entry in $downloads.GetEnumerator()) {
    Invoke-WebRequest -UseBasicParsing $entry.Value -OutFile $entry.Key
}

Write-Step 'Configuro PHP leggero'
$phpDir = Split-Path -Parent $php
$extensionDir = Join-Path $phpDir 'ext'
$phpIni = Join-Path $installRoot 'php.ini'
@(
    'display_errors=Off'
    'log_errors=On'
    "error_log=`"$($installRoot -replace '\\','/')/php-error.log`""
    'memory_limit=768M'
    'max_execution_time=0'
    'output_buffering=Off'
    'zlib.output_compression=Off'
    'allow_url_fopen=On'
    "extension_dir=`"$($extensionDir -replace '\\','/')`""
    'extension=curl'
    'extension=openssl'
    'extension=mbstring'
) | Set-Content -LiteralPath $phpIni -Encoding ASCII

Write-Step 'Dati del provider IPTV'
$modeAnswer = (Read-Host 'Tipo playlist: X = Xtream, M = link M3U [X]').Trim().ToUpperInvariant()
$config = [ordered]@{
    enabled = $true
    label = 'Catalogo IPTV completo'
    mode = if ($modeAnswer -eq 'M') { 'm3u' } else { 'xtream' }
    m3uUrl = ''
    epgUrl = ''
    serverUrl = ''
    username = ''
    password = ''
    accessPinHash = ''
    updatedAt = (Get-Date).ToUniversalTime().ToString('o')
}
if ($config.mode -eq 'm3u') {
    $config.m3uUrl = (Read-Host 'Incolla il link completo M3U').Trim()
} else {
    $config.serverUrl = (Read-Host 'Indirizzo server Xtream, esempio http://provider:porta').Trim().TrimEnd('/')
    $config.username = (Read-Host 'Username IPTV').Trim()
    $securePassword = Read-Host 'Password IPTV' -AsSecureString
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    try { $config.password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer) }
}
$pin = (Read-Host 'PIN catalogo [6594]').Trim()
if (-not $pin) { $pin = '6594' }
if ($pin -notmatch '^\d{4,12}$') { throw 'Il PIN deve contenere da 4 a 12 cifre.' }
$config.accessPinHash = (& $php -c $phpIni -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $pin)
$configPath = Join-Path $privateRoot 'iptv-config.json'
$configJson = $config | ConvertTo-Json -Depth 4
[IO.File]::WriteAllText($configPath, $configJson, [Text.UTF8Encoding]::new($false))

Write-Step 'Proteggo credenziali e avvio servizio'
& icacls.exe $installRoot /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "$($identity.Name):(OI)(CI)F" /T /C | Out-Null
$taskAction = New-ScheduledTaskAction -Execute $php -Argument "-c `"$phpIni`" -S 127.0.0.1:8765 `"$(Join-Path $appRoot 'router.php')`"" -WorkingDirectory $appRoot
$taskPrincipal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$taskTrigger = New-ScheduledTaskTrigger -AtStartup
$taskSettings = New-ScheduledTaskSettingsSet -RestartCount 99 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
Register-ScheduledTask -TaskName 'TubeTV Host' -Action $taskAction -Principal $taskPrincipal -Trigger $taskTrigger -Settings $taskSettings -Force | Out-Null
Start-ScheduledTask -TaskName 'TubeTV Host'
Start-Sleep -Seconds 2
$health = Invoke-RestMethod -UseBasicParsing 'http://127.0.0.1:8765/health'
if (-not $health.ok) { throw 'Il servizio locale TubeTV Host non risponde.' }

Write-Step 'Accesso Tailscale e HTTPS privato'
& $tailscale up
& $tailscale serve --bg --yes --https=443 http://127.0.0.1:8765
$status = (& $tailscale status --json | ConvertFrom-Json)
$dnsName = [string]$status.Self.DNSName
if (-not $dnsName) { throw 'Nome HTTPS Tailscale non disponibile.' }
$hostUrl = 'https://' + $dnsName.TrimEnd('.')

$result = @(
    'TUBETV HOST INSTALLATO'
    "Indirizzo: $hostUrl"
    'Stato locale: OK'
    'Installa Tailscale anche su PC e telefoni autorizzati.'
    "Poi apri: https://tubetv.online/host/?endpoint=$([uri]::EscapeDataString($hostUrl))"
)
$resultPath = Join-Path $installRoot 'COLLEGAMENTO.txt'
$result | Set-Content -LiteralPath $resultPath -Encoding UTF8
if ($SourceDrive -and (Test-Path "$SourceDrive\")) {
    $result | Set-Content -LiteralPath (Join-Path "$SourceDrive\" 'COLLEGAMENTO-TUBETV-HOST.txt') -Encoding UTF8
}
Write-Host ($result -join "`n") -ForegroundColor Green
