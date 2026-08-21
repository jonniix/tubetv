@echo off
setlocal
title TubeTV Host - Installazione
net session >nul 2>&1
if not "%errorlevel%"=="0" (
  echo Richiesta autorizzazione amministratore...
  powershell.exe -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
set "INSTALLER=%TEMP%\TubeTV-Host-Install.ps1"
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -UseBasicParsing 'https://tubetv.online/host/downloads/TubeTV-Host-Install.ps1' -OutFile '%INSTALLER%'"
if not exist "%INSTALLER%" (
  echo Download installer non riuscito.
  pause
  exit /b 1
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%INSTALLER%" -SourceDrive "%~d0"
echo.
pause

