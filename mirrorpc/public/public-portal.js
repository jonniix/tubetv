'use strict';

(() => {
  const byId = id => document.getElementById(id); let stream = null, timer = 0;
  function toast(message, error = false) { const node = byId('toast'); node.textContent = message; node.className = `toast show${error ? ' error' : ''}`; clearTimeout(node.t); node.t = setTimeout(() => node.className = 'toast', 2800); }
  function openJoin() { byId('joinDialog').classList.remove('hidden'); byId('manualCode').focus(); }
  function closeJoin() { clearInterval(timer); timer = 0; stream?.getTracks().forEach(track => track.stop()); stream = null; byId('scannerVideo').srcObject = null; byId('joinDialog').classList.add('hidden'); }
  function join(value) {
    const raw = String(value || ''), device = raw.match(/[?&]device=(\d{12})/) || raw.match(/^\D*(\d{12})\D*$/);
    if (device) { findPermanent(device[1]); return; }
    const code = raw.match(/[?&]code=(\d{6})/) || raw.match(/^\D*(\d{6})\D*$/);
    if (!code) return toast('Inserisci un codice di 6 cifre o un ID PC di 12 cifre', true);
    closeJoin(); location.href = `display.html?code=${code[1]}`;
  }
  async function findPermanent(value) {
    const deviceId = String(value || '').replace(/\D/g, '').slice(0, 12);
    if (deviceId.length !== 12) return toast('Inserisci tutte le 12 cifre dell’ID PC', true);
    const button = byId('joinPermanent'); button.disabled = true; button.textContent = 'Ricerca…';
    try {
      const response = await fetch('api/device.php', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ action: 'resolve', deviceId }), cache: 'no-store' });
      const result = await response.json(); if (!response.ok) throw new Error(result.message);
      closeJoin(); location.href = `display.html?code=${result.code}`;
    } catch (error) { toast(error.message || 'PC non raggiungibile', true); }
    finally { button.disabled = false; button.textContent = 'Trova PC'; }
  }
  async function startCamera() {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }); const video = byId('scannerVideo'); video.srcObject = stream; await video.play(); byId('cameraMessage').textContent = 'Inquadra il QR mostrato sul PC';
      if ('BarcodeDetector' in window) { const detector = new BarcodeDetector({ formats: ['qr_code'] }); timer = setInterval(async () => { try { const codes = await detector.detect(video); if (codes[0]?.rawValue) join(codes[0].rawValue); } catch {} }, 450); }
      else if (typeof window.jsQR === 'function') { const canvas = document.createElement('canvas'), context = canvas.getContext('2d', { willReadFrequently: true }); timer = setInterval(() => { if (video.readyState < 2) return; const scale = Math.min(1, 720 / video.videoWidth); canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale); context.drawImage(video, 0, 0, canvas.width, canvas.height); const image = context.getImageData(0, 0, canvas.width, canvas.height), result = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' }); if (result?.data) join(result.data); }, 500); }
    } catch { byId('cameraMessage').textContent = 'Fotocamera non disponibile · usa il codice manuale'; }
  }
  byId('openJoin').addEventListener('click', openJoin); byId('openJoinHero').addEventListener('click', openJoin); byId('closeJoin').addEventListener('click', closeJoin); byId('startCamera').addEventListener('click', startCamera); byId('joinManual').addEventListener('click', () => join(byId('manualCode').value)); byId('manualCode').addEventListener('input', event => event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6)); byId('manualCode').addEventListener('keydown', event => { if (event.key === 'Enter') join(event.currentTarget.value); });
  byId('joinPermanent').addEventListener('click', () => findPermanent(byId('permanentPortalId').value)); byId('permanentPortalId').addEventListener('input', event => event.target.value = event.target.value.replace(/\D/g, '').slice(0, 12)); byId('permanentPortalId').addEventListener('keydown', event => { if (event.key === 'Enter') findPermanent(event.currentTarget.value); });
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js?v=7', { updateViaCache: 'none' }).then(registration => registration.update()).catch(()=>{});
})();
