@echo off
setlocal EnableExtensions
title MirrorPC Studio
cd /d "%~dp0"
set "MIRRORPC_URL=http://127.0.0.1:4177/"

rem Se MirrorPC e gia attivo, non avviare una seconda copia.
powershell.exe -NoProfile -Command "$ErrorActionPreference='Stop'; $h=Invoke-RestMethod -Uri 'http://127.0.0.1:4177/api/health' -TimeoutSec 2; if($h.ok -and $h.service -eq 'MirrorPC'){exit 0}else{exit 1}" >nul 2>nul
if not errorlevel 1 (
  echo MirrorPC e gia attivo. Apro la console...
  start "" "%MIRRORPC_URL%"
  exit /b 0
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
echo Avvio MirrorPC...
call npm start
if errorlevel 1 echo Impossibile avviare MirrorPC. Verifica che la porta 4177 non sia usata da un altro programma.
pause
