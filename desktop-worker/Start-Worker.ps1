$ErrorActionPreference='Stop'
$root=Split-Path -Parent $MyInvocation.MyCommand.Path;$private=Join-Path $root 'private';$config=Get-Content -Raw -LiteralPath (Join-Path $private 'worker-config.json')|ConvertFrom-Json;$pidPath=Join-Path $private 'worker.pid'
if(Test-Path -LiteralPath $pidPath){$running=Get-Process -Id ([int](Get-Content $pidPath)) -ErrorAction SilentlyContinue;if($running){Write-Output 'TubeTV Assist già attivo.';exit 0}}
$env:TUBETV_WORKER_SECRET=[string]$config.secret;$env:TUBETV_WORKER_PORT=[string]$config.port;$env:TUBETV_WORKER_MAX_JOBS=[string]$config.maxJobs;$env:TUBETV_WORKER_FFMPEG=(Get-Command ffmpeg.exe -ErrorAction Stop).Source
$process=Start-Process -FilePath (Get-Command node.exe -ErrorAction Stop).Source -ArgumentList @((Join-Path $root 'worker.js')) -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $private 'worker-out.log') -RedirectStandardError (Join-Path $private 'worker-error.log');$process.Id|Set-Content -LiteralPath $pidPath -Encoding ASCII
Start-Sleep -Milliseconds 600;$health=Invoke-RestMethod "http://127.0.0.1:$($config.port)/health" -TimeoutSec 3;if(-not $health.ok){Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue;throw 'Worker non avviato.'}
Write-Output 'TubeTV Assist ATTIVO.'
