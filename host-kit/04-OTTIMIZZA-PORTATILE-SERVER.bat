@echo off
setlocal
title TubeTV Host - Ottimizzazione sicura
net session >nul 2>&1
if not "%errorlevel%"=="0" (
  echo Richiesta autorizzazione amministratore...
  powershell.exe -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\optimize-server.ps1"
echo.
pause

