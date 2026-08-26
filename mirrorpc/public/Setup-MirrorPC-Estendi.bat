@echo off
setlocal
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
echo  Installazione in corso. Windows potrebbe chiedere conferma...
winget install --id VirtualDrivers.Virtual-Display-Driver -e --accept-source-agreements --accept-package-agreements
if errorlevel 1 (
  echo  Installazione non completata. Nessuna modifica a MirrorPC.
  pause
  exit /b 1
)
echo.
echo  Driver installato. In "Piu schermi" scegli "Estendi questi schermi".
start "" ms-settings:display
echo  Torna su MirrorPC, scegli Estendi e premi Avvia desktop esteso.
pause
endlocal
