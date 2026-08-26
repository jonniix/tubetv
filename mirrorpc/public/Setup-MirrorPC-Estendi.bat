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
set "MIRRORPC_VDD_CONTROL="
for /d %%D in ("%LOCALAPPDATA%\Microsoft\WinGet\Packages\VirtualDrivers.Virtual-Display-Driver_*") do if exist "%%~fD\VDD Control.exe" set "MIRRORPC_VDD_CONTROL=%%~fD\VDD Control.exe"
if not defined MIRRORPC_VDD_CONTROL (
  echo  VDD Control non trovato dopo il download.
  pause
  exit /b 1
)
echo  Apro VDD Control. Nel programma scegli:
echo  Virtual Display Driver ^> System ^> Install Driver
echo  e conferma la richiesta di Windows.
start "" "%MIRRORPC_VDD_CONTROL%"
echo.
echo  Dopo aver premuto Install Driver, torna qui e premi un tasto.
pause >nul
pnputil.exe /scan-devices >nul 2>nul
timeout /t 3 /nobreak >nul
set "MIRRORPC_DRIVER_FOUND="
for /f "delims=" %%L in ('pnputil.exe /enum-devices /class Display ^| findstr /i /c:"Virtual Display" /c:"IddSample"') do set "MIRRORPC_DRIVER_FOUND=1"
if not defined MIRRORPC_DRIVER_FOUND (
  echo  Il driver non risulta installato.
  echo  In VDD Control premi Virtual Display Driver ^> System ^> Install Driver.
  echo  Se lo hai gia fatto, riavvia il PC e riprova.
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
