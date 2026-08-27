'use strict';

const $ = id => document.getElementById(id);
const inputs = [...document.querySelectorAll('#codeInputs input')];
let ws, pc, viewerId, statsTimer, previousBytes = 0, previousTime = performance.now();
let controlAvailable = false, controlEnabled = false, pendingMove = null, moveFrame = 0;
let viewerScannerStream = null, viewerScannerTimer = 0;
const iceServers = [{ urls: 'stun:stun.l.google.com:19302' }];
const phpMode = location.pathname.includes('/mirrorpc/');
const appBase = phpMode ? location.pathname.slice(0, location.pathname.indexOf('/mirrorpc/') + 9) : '';

function toast(message, error = false) { const el = $('viewerToast'); el.textContent = message; el.className = `toast show${error ? ' error' : ''}`; clearTimeout(el.t); el.t = setTimeout(() => el.className = 'toast', 2800); }
function codeValue() { return inputs.map(i => i.value).join(''); }

async function accessProof(code, challenge) {
  const password = $('accessPassword')?.value || '';
  if (!password || !challenge) return '';
  const verifier = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(password));
  const key = await crypto.subtle.importKey('raw', verifier, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const proof = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(`${code}:${challenge}`));
  return [...new Uint8Array(proof)].map(byte => byte.toString(16).padStart(2, '0')).join('');
}

inputs.forEach((input, index) => {
  input.addEventListener('input', () => { input.value = input.value.replace(/\D/g, '').slice(-1); if (input.value && inputs[index + 1]) inputs[index + 1].focus(); });
  input.addEventListener('keydown', e => { if (e.key === 'Backspace' && !input.value && inputs[index - 1]) inputs[index - 1].focus(); });
  input.addEventListener('paste', e => { const value = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6); if (value.length === 6) { e.preventDefault(); inputs.forEach((i, n) => i.value = value[n]); } });
});

function populateCode() { const code = new URLSearchParams(location.search).get('code')?.replace(/\D/g, '').slice(0, 6); if (code?.length === 6) inputs.forEach((input, i) => input.value = code[i]); }

function signal(data) { if (ws?.readyState === WebSocket.OPEN) ws.send(JSON.stringify(data)); }

async function connect() {
  const code = codeValue(); if (code.length !== 6) return toast('Inserisci tutte le 6 cifre', true);
  $('connectButton').disabled = true; $('connectButton').textContent = 'Connessione…';
  $('joinScreen').classList.add('hidden'); $('display').classList.remove('hidden');
  if (phpMode) {
    ws = createPhpSocket('viewer', { code });
    attachSignalHandlers(code);
    return;
  }
  const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
  ws = new WebSocket(`${protocol}//${location.host}/signal`);
  attachSignalHandlers(code);
}

function attachSignalHandlers(code) {
  ws.onopen = async () => signal({ type: 'viewer_join', code, proof: await accessProof(code, new URLSearchParams(location.search).get('challenge') || '') });
  ws.onmessage = async event => {
    const msg = JSON.parse(event.data);
    if (msg.type === 'viewer_ready') { viewerId = msg.viewerId; $('connectDetail').textContent = 'Il PC sta preparando il flusso…'; }
    if (msg.type === 'signal') await receiveSignal(msg.data);
    if (msg.type === 'error') fail(msg.message);
  };
  ws.onclose = () => { if (!$('display').classList.contains('hidden') && !$('connecting').classList.contains('hidden')) fail('Sessione terminata sul PC'); };
}

function createPhpSocket(role, session) {
  const socket = { readyState: WebSocket.CONNECTING, closed: false, onopen: null, onmessage: null, onclose: null, viewerId: '' };
  const post = async payload => {
    const response = await fetch(`${appBase}/api/signal.php`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(payload), cache: 'no-store' });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Errore di collegamento');
    return data;
  };
  const emit = message => socket.onmessage?.({ data: JSON.stringify(message) });
  const poll = async () => {
    if (socket.closed || !socket.viewerId) return;
    try {
      const result = await post({ action: 'poll', role, code: session.code, viewerId: socket.viewerId });
      (result.messages || []).forEach(emit);
    } catch (error) { emit({ type: 'error', message: error.message }); }
    setTimeout(poll, 350);
  };
  socket.send = raw => {
    const msg = JSON.parse(raw);
    if (msg.type === 'viewer_join') {
      post({ action: 'info', code: msg.code }).then(async info => post({ action: 'join', code: msg.code, proof: await accessProof(msg.code, info.challenge) })).then(result => { socket.viewerId = result.viewerId; socket.readyState = WebSocket.OPEN; emit({ type: 'viewer_ready', viewerId: result.viewerId }); poll(); }).catch(error => emit({ type: 'error', message: error.message }));
    } else if (msg.type === 'signal') {
      post({ action: 'send', role, code: session.code, viewerId: socket.viewerId, data: msg.data }).catch(error => emit({ type: 'error', message: error.message }));
    }
  };
  socket.close = () => { socket.closed = true; socket.readyState = WebSocket.CLOSED; if (socket.viewerId) post({ action: 'leave', role, code: session.code, viewerId: socket.viewerId }).catch(()=>{}); socket.onclose?.(); };
  setTimeout(() => { socket.readyState = WebSocket.OPEN; socket.onopen?.(); }, 0);
  return socket;
}

async function ensurePeer() {
  if (pc) return pc;
  pc = new RTCPeerConnection({ iceServers });
  pc.onicecandidate = e => { if (e.candidate) signal({ type: 'signal', data: { candidate: e.candidate } }); };
  pc.ontrack = e => {
    const video = $('remoteVideo'); video.srcObject = e.streams[0];
    video.play().catch(() => toast('Tocca lo schermo per avviare il video'));
    video.onplaying = () => {
      $('connecting').classList.add('hidden'); startStats();
      setTimeout(() => {
        const hasAudio = (video.srcObject?.getAudioTracks().length || 0) > 0;
        $('muteButton').classList.toggle('unavailable', !hasAudio);
        $('muteButton').title = hasAudio ? 'Attiva o disattiva audio' : 'Il PC non ha condiviso una traccia audio';
      }, 500);
      setTimeout(() => document.body.classList.add('hud-idle'), 4000);
    };
  };
  pc.onconnectionstatechange = () => { if (pc.connectionState === 'connected') toast('Display collegato'); if (['failed','disconnected'].includes(pc.connectionState)) fail('Collegamento interrotto'); };
  return pc;
}

async function receiveSignal(data) {
  if (data.accessDenied) return fail('Accesso rifiutato dal PC o password non valida');
  if (data.controlStatus) {
    controlAvailable = Boolean(data.controlStatus.available);
    $('controlButton').classList.toggle('unavailable', !controlAvailable);
    $('controlButton').title = data.controlStatus.label || '';
    if (!controlAvailable) $('controlButton').innerHTML = '<span>◎</span>Solo visuale';
    return;
  }
  const peer = await ensurePeer();
  if (data.description) {
    await peer.setRemoteDescription(data.description);
    if (data.description.type === 'offer') { const answer = await peer.createAnswer(); await peer.setLocalDescription(answer); signal({ type: 'signal', data: { description: peer.localDescription } }); }
  }
  if (data.candidate) { try { await peer.addIceCandidate(data.candidate); } catch {} }
}

function startStats() {
  clearInterval(statsTimer); statsTimer = setInterval(async () => {
    if (!pc) return; let bytes = 0, fps = 0, width = 0, height = 0, jitter = 0;
    for (const r of (await pc.getStats()).values()) if (r.type === 'inbound-rtp' && r.kind === 'video') { bytes += r.bytesReceived || 0; fps = r.framesPerSecond || 0; width = r.frameWidth || 0; height = r.frameHeight || 0; jitter = r.jitter || 0; }
    const now = performance.now(), mbps = previousBytes ? (bytes - previousBytes) * 8 / (now - previousTime) / 1000 : 0;
    $('rxResolution').textContent = width ? `${width}×${height}` : 'AUTO'; $('rxFps').textContent = `${Math.round(fps)} FPS`; $('rxBitrate').textContent = `${Math.max(0, mbps).toFixed(1)} Mbps`; $('latency').textContent = jitter ? `Jitter ${Math.round(jitter * 1000)} ms` : 'LAN'; previousBytes = bytes; previousTime = now;
  }, 1000);
}

function fail(message) { $('connectDetail').textContent = message; $('connecting').classList.remove('hidden'); $('connecting').classList.add('failed'); toast(message, true); }
function disconnect() { clearInterval(statsTimer); pc?.close(); ws?.close(); pc = ws = null; $('remoteVideo').srcObject = null; $('display').classList.add('hidden'); $('joinScreen').classList.remove('hidden'); $('connectButton').disabled = false; $('connectButton').textContent = 'Connetti display'; }

function sendControl(event) {
  if (controlEnabled && ws?.readyState === WebSocket.OPEN) signal({ type: 'signal', data: { control: event } });
}

function videoPoint(event) {
  const video = $('remoteVideo'), box = video.getBoundingClientRect();
  let left = box.left, top = box.top, width = box.width, height = box.height;
  if (!video.classList.contains('fill') && video.videoWidth && video.videoHeight) {
    const mediaRatio = video.videoWidth / video.videoHeight, boxRatio = box.width / box.height;
    if (mediaRatio > boxRatio) { height = box.width / mediaRatio; top += (box.height - height) / 2; }
    else { width = box.height * mediaRatio; left += (box.width - width) / 2; }
  }
  return { x: Math.max(0, Math.min(1, (event.clientX - left) / width)), y: Math.max(0, Math.min(1, (event.clientY - top) / height)) };
}

function queueMove(event) {
  pendingMove = videoPoint(event);
  if (moveFrame) return;
  moveFrame = requestAnimationFrame(() => { moveFrame = 0; if (pendingMove) sendControl({ type: 'move', ...pendingMove }); });
}

function toggleControl() {
  if (!controlAvailable) return toast('Avvia MirrorPC dall’app Windows sul PC da controllare', true);
  controlEnabled = !controlEnabled;
  $('controlButton').classList.toggle('active', controlEnabled);
  $('controlButton').innerHTML = controlEnabled ? '<span>●</span>Controllo ON' : '<span>◎</span>Controlla';
  $('controlHint').classList.toggle('hidden', !controlEnabled);
  $('remoteVideo').classList.toggle('control-active', controlEnabled);
  toast(controlEnabled ? 'Mouse e tastiera attivi' : 'Controllo disattivato');
}

function openViewerScanner() { $('viewerScannerDialog').classList.remove('hidden'); }
function closeViewerScanner() {
  clearInterval(viewerScannerTimer); viewerScannerTimer = 0;
  viewerScannerStream?.getTracks().forEach(track => track.stop()); viewerScannerStream = null;
  $('viewerScannerVideo').srcObject = null; $('viewerScannerDialog').classList.add('hidden');
}
function applyScannedValue(value) {
  const match = String(value || '').match(/(?:code=)?(\d{6})/);
  if (!match) return;
  inputs.forEach((input, index) => input.value = match[1][index]);
  closeViewerScanner(); toast('Codice QR acquisito');
}
async function startViewerScanner() {
  try {
    viewerScannerStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
    const video = $('viewerScannerVideo'); video.srcObject = viewerScannerStream; await video.play(); $('viewerCameraMessage').textContent = 'Inquadra il QR mostrato sul PC';
    if ('BarcodeDetector' in window) {
      const detector = new BarcodeDetector({ formats: ['qr_code'] });
      viewerScannerTimer = setInterval(async () => { try { const codes = await detector.detect(video); if (codes[0]?.rawValue) applyScannedValue(codes[0].rawValue); } catch {} }, 450);
    } else if (typeof window.jsQR === 'function') {
      const canvas = document.createElement('canvas'), context = canvas.getContext('2d', { willReadFrequently: true });
      viewerScannerTimer = setInterval(() => { if (video.readyState < 2) return; const scale = Math.min(1, 720 / video.videoWidth); canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale); context.drawImage(video, 0, 0, canvas.width, canvas.height); const image = context.getImageData(0, 0, canvas.width, canvas.height); const result = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' }); if (result?.data) applyScannedValue(result.data); }, 500);
    } else $('viewerCameraMessage').textContent = 'Scanner non disponibile su questo dispositivo';
  } catch { $('viewerCameraMessage').textContent = 'Fotocamera non disponibile: usa il codice manuale'; }
}

$('connectButton').addEventListener('click', connect);
$('openViewerScanner').addEventListener('click', openViewerScanner);
$('closeViewerScanner').addEventListener('click', closeViewerScanner);
$('startViewerCamera').addEventListener('click', startViewerScanner);
$('controlButton').addEventListener('click', toggleControl);
$('fullscreen').addEventListener('click', () => document.documentElement.requestFullscreen?.());
$('fitButton').addEventListener('click', () => { $('remoteVideo').classList.remove('fill'); $('fitButton').classList.add('active'); $('fillButton').classList.remove('active'); });
$('fillButton').addEventListener('click', () => { $('remoteVideo').classList.add('fill'); $('fillButton').classList.add('active'); $('fitButton').classList.remove('active'); });
$('muteButton').addEventListener('click', async e => {
  const video = $('remoteVideo');
  if (!(video.srcObject?.getAudioTracks().length || 0)) return toast('Nessun audio ricevuto: sul PC riavvia e abilita “Condividi audio di sistema”', true);
  video.muted = !video.muted; video.volume = 1;
  try { await video.play(); } catch { return toast('Safari ha bloccato l’audio: tocca nuovamente Audio', true); }
  e.currentTarget.classList.toggle('active', !video.muted);
  e.currentTarget.innerHTML = video.muted ? '<span>◉</span>Audio' : '<span>◉</span>Audio ON';
  toast(video.muted ? 'Audio disattivato' : 'Audio attivato');
});
$('disconnectButton').addEventListener('click', disconnect);
$('remoteVideo').addEventListener('pointermove', event => { if (controlEnabled) { event.preventDefault(); queueMove(event); } });
$('remoteVideo').addEventListener('pointerdown', event => {
  if (!controlEnabled) return;
  event.preventDefault(); $('remoteVideo').setPointerCapture?.(event.pointerId); queueMove(event);
  sendControl({ type: 'button', button: ['left', 'middle', 'right'][event.button] || 'left', state: 'down' });
});
$('remoteVideo').addEventListener('pointerup', event => {
  if (!controlEnabled) return;
  event.preventDefault(); queueMove(event);
  sendControl({ type: 'button', button: ['left', 'middle', 'right'][event.button] || 'left', state: 'up' });
});
$('remoteVideo').addEventListener('contextmenu', event => { if (controlEnabled) event.preventDefault(); });
$('remoteVideo').addEventListener('wheel', event => { if (controlEnabled) { event.preventDefault(); sendControl({ type: 'wheel', delta: Math.sign(event.deltaY) * 120 }); } }, { passive: false });
document.addEventListener('keydown', event => { if (controlEnabled && !event.repeat) { event.preventDefault(); sendControl({ type: 'key', code: event.code, state: 'down' }); } });
document.addEventListener('keyup', event => { if (controlEnabled) { event.preventDefault(); sendControl({ type: 'key', code: event.code, state: 'up' }); } });
$('display').addEventListener('pointerdown', () => { document.body.classList.remove('hud-idle'); setTimeout(() => document.body.classList.add('hud-idle'), 4000); $('remoteVideo').play().catch(()=>{}); });
populateCode(); if (codeValue().length === 6) setTimeout(connect, 350);
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(()=>{});
