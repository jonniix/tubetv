@echo off
setlocal EnableExtensions
title MirrorPC - Installazione app Windows
color 0B
set "INSTALL_DIR=%LOCALAPPDATA%\MirrorPC"
set "SETUP_DIR=%TEMP%\MirrorPC-Setup-%RANDOM%"
set "ARCHIVE=%SETUP_DIR%\mirrorpc.zip"

echo.
echo  ============================================================
echo   MirrorPC Control - installazione app locale
echo  ============================================================
echo.
echo  La console privata verra installata in:
echo  %INSTALL_DIR%
echo.

where powershell.exe >nul 2>nul || goto :missing_powershell
where node.exe >nul 2>nul
if errorlevel 1 (
  echo  Installazione di Node.js LTS necessaria...
  where winget.exe >nul 2>nul || goto :missing_node
  winget install --id OpenJS.NodeJS.LTS -e --accept-source-agreements --accept-package-agreements
  if errorlevel 1 goto :failed
  set "PATH=%ProgramFiles%\nodejs;%PATH%"
)

mkdir "%SETUP_DIR%" >nul 2>nul
echo  Download sicuro dell'app...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -UseBasicParsing 'https://github.com/jonniix/tubetv/archive/refs/heads/mirrorpc-production.zip' -OutFile '%ARCHIVE%'"
if errorlevel 1 goto :failed

echo  Preparazione dei file...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Expand-Archive -LiteralPath '%ARCHIVE%' -DestinationPath '%SETUP_DIR%\files' -Force"
if errorlevel 1 goto :failed
if not exist "%SETUP_DIR%\files\tubetv-mirrorpc-production\mirrorpc\server.js" goto :failed
if not exist "%INSTALL_DIR%" mkdir "%INSTALL_DIR%"
robocopy "%SETUP_DIR%\files\tubetv-mirrorpc-production\mirrorpc" "%INSTALL_DIR%" /E /PURGE /XD node_modules >nul
if errorlevel 8 goto :failed

echo  Installazione dei componenti locali...
pushd "%INSTALL_DIR%"
call npm install --omit=dev
if errorlevel 1 (popd & goto :failed)
if not exist "node_modules\electron\dist\electron.exe" node "node_modules\electron\install.js"
if errorlevel 1 (popd & goto :failed)
popd

echo  Creazione collegamento sul Desktop...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$w=New-Object -ComObject WScript.Shell;$s=$w.CreateShortcut([IO.Path]::Combine([Environment]::GetFolderPath('Desktop'),'MirrorPC.lnk'));$s.TargetPath='%INSTALL_DIR%\Avvia MirrorPC.bat';$s.WorkingDirectory='%INSTALL_DIR%';$s.Save()"

rmdir /S /Q "%SETUP_DIR%" >nul 2>nul
echo.
echo  MirrorPC installato correttamente.
echo  Avvio della console privata...
start "" "%INSTALL_DIR%\Avvia MirrorPC.bat"
exit /b 0

:missing_powershell
echo  PowerShell non disponibile su questo PC.
goto :failed_pause
:missing_node
echo  Node.js non e installato e Winget non e disponibile.
echo  Installa Node.js LTS da https://nodejs.org/ e riprova.
goto :failed_pause
:failed
echo.
echo  Installazione non completata. Nessun dato personale e stato pubblicato.
:failed_pause
pause
exit /b 1
