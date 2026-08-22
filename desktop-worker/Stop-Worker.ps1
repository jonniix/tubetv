$root=Split-Path -Parent $MyInvocation.MyCommand.Path;$pidPath=Join-Path $root 'private\worker.pid'
if(Test-Path -LiteralPath $pidPath){$workerPid=[int](Get-Content $pidPath -ErrorAction SilentlyContinue);Stop-Process -Id $workerPid -Force -ErrorAction SilentlyContinue;Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue}
Write-Output 'TubeTV Assist SPENTO.'
