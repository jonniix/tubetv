'use strict';

(() => {
  const byId = id => document.getElementById(id);
  const STORAGE_KEY = 'mirrorpc_devices_v1';
  const newId = () => globalThis.crypto?.randomUUID?.() || `pc-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const defaults = [];
  let devices = loadDevices(), scannerStream = null, scanTimer = 0;

  function loadDevices() {
    try { const saved = JSON.parse(localStorage.getItem(STORAGE_KEY)); if (Array.isArray(saved)) return saved; } catch {}
    localStorage.setItem(STORAGE_KEY, JSON.stringify(defaults)); return defaults;
  }
  function saveDevices() { localStorage.setItem(STORAGE_KEY, JSON.stringify(devices)); }
  function esc(value) { const node = document.createElement('span'); node.textContent = String(value || ''); return node.innerHTML; }
  function initials(name) { return String(name || 'PC').split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase(); }
  function notify(message, error = false) { const toast = byId('toast'); toast.textContent = message; toast.className = `toast show${error ? ' error' : ''}`; clearTimeout(toast.t); toast.t = setTimeout(() => toast.className = 'toast', 3000); }

  function render() {
    byId('deviceCount').textContent = devices.length;
    byId('deviceList').innerHTML = devices.map(device => `<article class="saved-device" data-id="${device.id}"><button class="device-main" data-action="connect"><span class="pc-avatar">${esc(initials(device.name))}</span><span class="device-copy"><b>${esc(device.name)}</b><small>${esc(device.ip)}</small></span><i class="device-state checking" title="Verifica in corso"></i></button><div class="device-actions"><button data-action="wake" title="Accendi con Wake-on-LAN">ϟ</button><button data-action="edit" title="Modifica">•••</button></div></article>`).join('') || '<div class="empty-devices">Aggiungi il tuo primo PC</div>';
    checkAll();
  }
  async function checkDevice(device, card) {
    const indicator = card.querySelector('.device-state');
    try {
      const response = await fetch(`/api/devices/status?host=${encodeURIComponent(device.ip)}`, { cache: 'no-store', signal: AbortSignal.timeout(2800) });
      if (!response.ok) throw new Error(); const result = await response.json();
      indicator.className = `device-state ${result.online ? 'online' : 'offline'}`; indicator.title = result.online ? `Online · ${result.latencyMs} ms` : 'Spento o non raggiungibile'; card.dataset.online = result.online ? '1' : '0';
    } catch { indicator.className = 'device-state unknown'; indicator.title = 'Stato disponibile dall’app locale'; card.dataset.online = '0'; }
    byId('onlineCount').textContent = document.querySelectorAll('.saved-device[data-online="1"]').length;
  }
  function checkAll() { devices.forEach(device => { const card = document.querySelector(`[data-id="${device.id}"]`); if (card) checkDevice(device, card); }); }
  async function wake(device) {
    if (!device.mac) { openDeviceDialog(device); return notify('Inserisci prima il MAC Ethernet del PC', true); }
    try {
      const response = await fetch('/api/devices/wake', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ mac: device.mac }) });
      const result = await response.json(); if (!response.ok) throw new Error(result.message); notify(`${device.name}: comando di accensione inviato`); setTimeout(checkAll, 4500);
    } catch (error) { notify(error.message || 'Wake-on-LAN richiede il relay locale', true); }
  }
  function connectDevice(device) { if (!device.url) return notify('Aggiungi il link MirrorPC del dispositivo', true); location.href = device.url; }
  function openDeviceDialog(device = null) {
    byId('deviceForm').dataset.editing = device?.id || ''; byId('deviceTitle').textContent = device ? `Modifica ${device.name}` : 'Aggiungi un PC';
    byId('deviceName').value = device?.name || ''; byId('deviceIp').value = device?.ip || ''; byId('deviceMac').value = device?.mac || ''; byId('deviceUrl').value = device?.url || ''; byId('deviceDialog').classList.remove('hidden');
  }
  function openScanner() { byId('scannerDialog').classList.remove('hidden'); byId('manualCode').focus(); }
  function closeScanner() { clearInterval(scanTimer); scanTimer = 0; scannerStream?.getTracks().forEach(track => track.stop()); scannerStream = null; byId('scannerVideo').srcObject = null; byId('scannerDialog').classList.add('hidden'); }
  async function startCamera() {
    try {
      scannerStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }); byId('scannerVideo').srcObject = scannerStream; await byId('scannerVideo').play(); byId('cameraMessage').textContent = 'Inquadra il QR MirrorPC';
      if ('BarcodeDetector' in window) {
        const detector = new BarcodeDetector({ formats: ['qr_code'] }); scanTimer = setInterval(async () => { try { const codes = await detector.detect(byId('scannerVideo')); if (codes[0]?.rawValue) joinValue(codes[0].rawValue); } catch {} }, 450);
      } else if (typeof window.jsQR === 'function') {
        const canvas = document.createElement('canvas'), context = canvas.getContext('2d', { willReadFrequently: true });
        scanTimer = setInterval(() => { const video = byId('scannerVideo'); if (video.readyState < 2) return; const scale = Math.min(1, 720 / video.videoWidth); canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale); context.drawImage(video, 0, 0, canvas.width, canvas.height); const image = context.getImageData(0, 0, canvas.width, canvas.height); const result = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' }); if (result?.data) joinValue(result.data); }, 500);
      } else byId('cameraMessage').textContent = 'Scanner QR non disponibile: usa il codice manuale';
    } catch { byId('cameraMessage').textContent = 'Fotocamera non disponibile · inserisci il codice'; }
  }
  function joinValue(value) { const match = String(value || '').match(/(?:code=)?(\d{6})/); if (!match) return notify('Questo QR non contiene un codice MirrorPC', true); closeScanner(); location.href = `display.html?code=${match[1]}`; }

  async function checkRelay() {
    try {
      const response = await fetch('/api/health', { cache: 'no-store', signal: AbortSignal.timeout(2500) }); if (!response.ok) throw new Error(); const health = await response.json();
      byId('serverStatus').textContent = `Relay ${health.address} pronto`; byId('relayDetail').textContent = `${health.address}:${health.port}`; byId('relayLed').className = 'status-led online';
    } catch { byId('serverStatus').textContent = 'Modalità web sicura'; byId('relayDetail').textContent = 'Apri l’app locale per Wake-on-LAN'; byId('relayLed').className = 'status-led'; }
  }

  byId('deviceList').addEventListener('click', event => { const button = event.target.closest('[data-action]'), card = event.target.closest('.saved-device'); if (!button || !card) return; const device = devices.find(item => item.id === card.dataset.id); if (!device) return; if (button.dataset.action === 'wake') wake(device); if (button.dataset.action === 'connect') connectDevice(device); if (button.dataset.action === 'edit') openDeviceDialog(device); });
  byId('addDevice').addEventListener('click', () => openDeviceDialog());
  byId('deviceForm').addEventListener('submit', event => { event.preventDefault(); const item = { id: event.currentTarget.dataset.editing || newId(), name: byId('deviceName').value.trim(), ip: byId('deviceIp').value.trim(), mac: byId('deviceMac').value.trim().toUpperCase(), url: byId('deviceUrl').value.trim() }; const index = devices.findIndex(device => device.id === item.id); if (index >= 0) devices[index] = item; else devices.push(item); saveDevices(); byId('deviceDialog').classList.add('hidden'); render(); notify('Dispositivo salvato'); });
  byId('openScanner').addEventListener('click', openScanner); byId('startCamera').addEventListener('click', startCamera); byId('joinManual').addEventListener('click', () => joinValue(byId('manualCode').value));
  document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => button.dataset.close === 'scannerDialog' ? closeScanner() : byId(button.dataset.close).classList.add('hidden')));
  byId('manualCode').addEventListener('input', event => { event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6); });
  render(); checkRelay();
})();
