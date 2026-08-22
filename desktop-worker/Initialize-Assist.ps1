$ErrorActionPreference='Stop'
$root=Split-Path -Parent $MyInvocation.MyCommand.Path
$private=Join-Path $root 'private';$configPath=Join-Path $private 'worker-config.json';$laptopPath=Join-Path $private 'laptop-worker-config.json'
New-Item -ItemType Directory -Force -Path $private|Out-Null
if(-not(Test-Path -LiteralPath $configPath)){$bytes=New-Object byte[] 48;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($bytes)}finally{$rng.Dispose()};$secret=($bytes|ForEach-Object{$_.ToString('x2')})-join'';[IO.File]::WriteAllText($configPath,(@{secret=$secret;port=8788;maxJobs=2}|ConvertTo-Json),[Text.UTF8Encoding]::new($false))}
$config=Get-Content -Raw -LiteralPath $configPath|ConvertFrom-Json
[IO.File]::WriteAllText($laptopPath,(@{enabled=$true;url='https://desktop-9q1u4sk.tail4f29b5.ts.net:8443';secret=[string]$config.secret}|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
$tailscale='C:\Program Files\Tailscale\tailscale.exe';if(-not(Test-Path -LiteralPath $tailscale)){throw 'Tailscale non trovato.'}
& $tailscale serve --bg --yes --https=8443 "http://127.0.0.1:$($config.port)"|Out-Null
Write-Output 'TubeTV Assist inizializzato. Il worker resta SPENTO finché non usi l’interruttore.'
