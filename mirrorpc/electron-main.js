'use strict';
const path = require('path');
const { app, BrowserWindow, desktopCapturer, ipcMain, screen } = require('electron');
const URL = 'http://127.0.0.1:4177/';
const lock = app.requestSingleInstanceLock();
if (!lock) app.quit();
let windowRef = null;
async function serverReady() { try { const response = await fetch('http://127.0.0.1:4177/api/health'); const data = await response.json(); return data.ok && data.service === 'MirrorPC'; } catch { return false; } }
async function ensureServer() { if (await serverReady()) return; process.env.AUTO_OPEN = '0'; require('./server'); for (let i = 0; i < 30 && !(await serverReady()); i++) await new Promise(resolve => setTimeout(resolve, 200)); }
async function createWindow() {
  await ensureServer();
  windowRef = new BrowserWindow({ title: 'MirrorPC Control', show: false, fullscreen: true, autoHideMenuBar: true, backgroundColor: '#03070c', webPreferences: { preload: path.join(__dirname, 'electron-preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: false } });
  windowRef.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  windowRef.webContents.on('will-navigate', (event, target) => { if (!target.startsWith(URL)) event.preventDefault(); });
  windowRef.removeMenu(); await windowRef.loadURL(URL); windowRef.show(); windowRef.focus();
}
ipcMain.handle('mirrorpc:capture-primary', async (_event, mode) => {
  const primary = screen.getPrimaryDisplay();
  const sources = await desktopCapturer.getSources({ types: ['screen'], thumbnailSize: { width: 0, height: 0 }, fetchWindowIcons: false });
  const source = mode === 'extend' ? (sources.find(item => item.display_id !== String(primary.id)) || sources[0]) : (sources.find(item => item.display_id === String(primary.id)) || sources[0]);
  if (!source) throw new Error('Schermo principale non trovato'); return source.id;
});
app.on('second-instance', () => { if (windowRef) { if (windowRef.isMinimized()) windowRef.restore(); windowRef.show(); windowRef.focus(); } });
app.whenReady().then(createWindow);
app.on('window-all-closed', () => app.quit());

