@echo off
setlocal EnableExtensions
title MirrorPC Studio
cd /d "%~dp0"
set "MIRRORPC_URL=http://127.0.0.1:4177/"

rem Se MirrorPC e gia attivo, non avviare una seconda copia.
powershell.exe -NoProfile -Command "$ErrorActionPreference='Stop'; $h=Invoke-RestMethod -Uri 'http://127.0.0.1:4177/api/health' -TimeoutSec 2; if($h.ok -and $h.service -eq 'MirrorPC'){exit 0}else{exit 1}" >nul 2>nul
if not errorlevel 1 (
  goto :open_app
)

where node >nul 2>nul
if errorlevel 1 (
  echo Node.js non e installato. Scaricalo da https://nodejs.org/
  pause
  exit /b 1
)
if not exist "node_modules\" (
  echo Preparazione iniziale MirrorPC...
  call npm install
  if errorlevel 1 pause & exit /b 1
)
echo Avvio MirrorPC in background...
powershell.exe -NoProfile -Command "$env:AUTO_OPEN='0'; Start-Process -FilePath (Get-Command node.exe).Source -ArgumentList 'server.js' -WorkingDirectory '%~dp0' -WindowStyle Hidden" >nul 2>nul
for /L %%G in (1,1,15) do (
  powershell.exe -NoProfile -Command "$ErrorActionPreference='Stop'; $h=Invoke-RestMethod -Uri 'http://127.0.0.1:4177/api/health' -TimeoutSec 1; if($h.ok -and $h.service -eq 'MirrorPC'){exit 0}else{exit 1}" >nul 2>nul
  if not errorlevel 1 goto :open_app
  timeout /t 1 /nobreak >nul
)
echo Impossibile avviare MirrorPC. Verifica che la porta 4177 non sia usata da un altro programma.
pause
exit /b 1

:open_app
echo Apro MirrorPC come applicazione...
set "EDGE=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%EDGE%" set "EDGE=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if exist "%EDGE%" (
  start "" "%EDGE%" --app="%MIRRORPC_URL%" --start-maximized
  exit /b 0
)
set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if exist "%CHROME%" (
  start "" "%CHROME%" --app="%MIRRORPC_URL%" --start-maximized
  exit /b 0
)
start "" "%MIRRORPC_URL%"
exit /b 0
