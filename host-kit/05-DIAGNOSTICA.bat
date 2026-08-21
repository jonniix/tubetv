@echo off
setlocal
title TubeTV Host - Diagnostica
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\diagnostics.ps1"
echo.
pause

