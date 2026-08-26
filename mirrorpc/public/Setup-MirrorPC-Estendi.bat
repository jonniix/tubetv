@echo off
setlocal
net session >nul 2>nul
if errorlevel 1 (
  echo Richiesta autorizzazione amministratore...
  powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
title MirrorPC - Configurazione modalita Estendi
color 0B
echo.
echo  ============================================================
echo   MirrorPC Extend - setup display virtuale firmato
echo  ============================================================
echo.
echo  Questo setup installa il pacchetto open source ufficiale:
echo  VirtualDrivers.Virtual-Display-Driver
echo.
where winget.exe >nul 2>nul
if errorlevel 1 (
  echo  ERRORE: Winget non e disponibile su questo PC.
  echo  Aggiorna "Programma di installazione app" dal Microsoft Store.
  pause
  exit /b 1
)
echo  Installazione in corso. Non chiudere questa finestra...
winget install --id VirtualDrivers.Virtual-Display-Driver -e --accept-source-agreements --accept-package-agreements --force
if errorlevel 1 (
  echo  Installazione non completata. Nessuna modifica a MirrorPC.
  pause
  exit /b 1
)
echo.
pnputil.exe /scan-devices >nul 2>nul
timeout /t 3 /nobreak >nul
set "MIRRORPC_DRIVER_FOUND="
for /f "delims=" %%L in ('pnputil.exe /enum-devices /class Display ^| findstr /i /c:"Virtual Display" /c:"IddSample"') do set "MIRRORPC_DRIVER_FOUND=1"
if not defined MIRRORPC_DRIVER_FOUND (
  echo  Il pacchetto e installato, ma Windows non ha ancora attivato il display.
  echo  Riavvia il PC, poi riapri MirrorPC e premi Estendi.
  echo.
  pause
  exit /b 0
)
echo  Driver rilevato correttamente.
echo  In "Piu schermi" scegli "Estendi questi schermi".
start "" ms-settings:display
echo  Torna su MirrorPC, scegli Estendi e premi Avvia desktop esteso.
pause
endlocal
