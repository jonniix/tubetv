@echo off
setlocal
title TubeTV Host - Backup Wi-Fi e driver
net session >nul 2>&1
if not "%errorlevel%"=="0" (
  echo Richiesta autorizzazione amministratore...
  powershell.exe -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\backup-wifi-driver.ps1"
echo.
pause

