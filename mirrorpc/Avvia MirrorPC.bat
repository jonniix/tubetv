@echo off
title MirrorPC Studio
cd /d "%~dp0"
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
pause
