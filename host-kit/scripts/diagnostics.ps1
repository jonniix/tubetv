$ErrorActionPreference = 'SilentlyContinue'
$kitRoot = Split-Path -Parent $PSScriptRoot
$reportRoot = Join-Path $kitRoot 'DIAGNOSTICA'
New-Item -ItemType Directory -Force -Path $reportRoot | Out-Null
$reportPath = Join-Path $reportRoot ("TubeTV-{0}.txt" -f (Get-Date -Format 'yyyyMMdd-HHmmss'))

$lines = [System.Collections.Generic.List[string]]::new()
$lines.Add('TUBETV HOST - DIAGNOSTICA')
$lines.Add("Data: $(Get-Date -Format s)")
$lines.Add("Computer: $env:COMPUTERNAME")
$lines.Add("Windows: $([Environment]::OSVersion.VersionString)")
$lines.Add("RAM GB: $([math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB, 2))")
$lines.Add("CPU: $((Get-CimInstance Win32_Processor | Select-Object -First 1).Name)")
$lines.Add('')
$lines.Add('DISCHI')
Get-CimInstance Win32_LogicalDisk | ForEach-Object {
    $lines.Add("$($_.DeviceID) $($_.VolumeName) - liberi $([math]::Round($_.FreeSpace/1GB,1)) GB / $([math]::Round($_.Size/1GB,1)) GB")
}
$lines.Add('')
$lines.Add('RETE')
Get-NetAdapter | Where-Object Status -eq 'Up' | ForEach-Object { $lines.Add("$($_.Name): $($_.LinkSpeed)") }
$lines.Add("Internet TubeTV: $((Test-NetConnection tubetv.online -Port 443 -InformationLevel Quiet))")
$lines | Set-Content -LiteralPath $reportPath -Encoding UTF8
Write-Host "Rapporto salvato in $reportPath" -ForegroundColor Green
