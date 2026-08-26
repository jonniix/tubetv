'use strict';

const $ = id => document.getElementById(id);
const inputs = [...document.querySelectorAll('#codeInputs input')];
let ws, pc, viewerId, statsTimer, previousBytes = 0, previousTime = performance.now();
const iceServers = [{ urls: 'stun:stun.l.google.com:19302' }];
const phpMode = location.pathname.includes('/mirrorpc/');
const appBase = phpMode ? location.pathname.slice(0, location.pathname.indexOf('/mirrorpc/') + 9) : '';

function toast(message, error = false) { const el = $('viewerToast'); el.textContent = message; el.className = `toast show${error ? ' error' : ''}`; clearTimeout(el.t); el.t = setTimeout(() => el.className = 'toast', 2800); }
function codeValue() { return inputs.map(i => i.value).join(''); }

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
  ws.onopen = () => signal({ type: 'viewer_join', code });
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
      post({ action: 'join', code: msg.code }).then(result => { socket.viewerId = result.viewerId; socket.readyState = WebSocket.OPEN; emit({ type: 'viewer_ready', viewerId: result.viewerId }); poll(); }).catch(error => emit({ type: 'error', message: error.message }));
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
    video.onplaying = () => { $('connecting').classList.add('hidden'); startStats(); setTimeout(() => document.body.classList.add('hud-idle'), 4000); };
  };
  pc.onconnectionstatechange = () => { if (pc.connectionState === 'connected') toast('Display collegato'); if (['failed','disconnected'].includes(pc.connectionState)) fail('Collegamento interrotto'); };
  return pc;
}

async function receiveSignal(data) {
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

$('connectButton').addEventListener('click', connect);
$('fullscreen').addEventListener('click', () => document.documentElement.requestFullscreen?.());
$('fitButton').addEventListener('click', () => { $('remoteVideo').classList.remove('fill'); $('fitButton').classList.add('active'); $('fillButton').classList.remove('active'); });
$('fillButton').addEventListener('click', () => { $('remoteVideo').classList.add('fill'); $('fillButton').classList.add('active'); $('fitButton').classList.remove('active'); });
$('muteButton').addEventListener('click', e => { const v = $('remoteVideo'); v.muted = !v.muted; e.currentTarget.classList.toggle('active', !v.muted); });
$('disconnectButton').addEventListener('click', disconnect);
$('display').addEventListener('pointerdown', () => { document.body.classList.remove('hud-idle'); setTimeout(() => document.body.classList.add('hud-idle'), 4000); $('remoteVideo').play().catch(()=>{}); });
populateCode(); if (codeValue().length === 6) setTimeout(connect, 350);
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(()=>{});
