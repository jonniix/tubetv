@echo off
setlocal
title TubeTV Host - Ripristino Wi-Fi e driver
net session >nul 2>&1
if not "%errorlevel%"=="0" (
  echo Richiesta autorizzazione amministratore...
  powershell.exe -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\restore-wifi-driver.ps1"
echo.
pause

