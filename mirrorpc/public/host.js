'use strict';

const $ = id => document.getElementById(id);
const state = { stream: null, ws: null, session: null, peers: new Map(), statsTimer: null, countdown: null, joinUrl: '' };
const iceServers = [{ urls: 'stun:stun.l.google.com:19302' }];
const phpMode = location.pathname.includes('/mirrorpc/');
const appBase = phpMode ? location.pathname.slice(0, location.pathname.indexOf('/mirrorpc/') + 9) : '';

function toast(message, error = false) {
  const el = $('toast'); el.textContent = message; el.className = `toast show${error ? ' error' : ''}`;
  clearTimeout(el.hideTimer); el.hideTimer = setTimeout(() => el.className = 'toast', 3000);
}

function signal(message) {
  if (state.ws?.readyState === WebSocket.OPEN) state.ws.send(JSON.stringify(message));
}

async function createSession() {
  const endpoint = phpMode ? `${appBase}/api/session.php` : '/api/session';
  const response = await fetch(endpoint, { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' });
  if (!response.ok) throw new Error('Impossibile creare la sessione');
  state.session = await response.json();
  state.joinUrl = state.session.joinUrl;
  if (state.session.qr) $('qrImage').src = state.session.qr;
  else {
    const qr = qrcode(0, 'M'); qr.addData(state.session.joinUrl); qr.make();
    $('qrImage').src = qr.createDataURL(7, 12);
  }
  $('qrFrame').classList.remove('waiting');
  $('code').textContent = `${state.session.code.slice(0, 3)} ${state.session.code.slice(3)}`;
  $('copyLink').disabled = false;
  startCountdown(state.session.expiresIn);
  connectSignal();
}

function connectSignal() {
  if (phpMode) {
    state.ws = createPhpSocket('host', state.session);
    attachSignalHandlers();
    return;
  }
  const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
  state.ws = new WebSocket(`${protocol}//${location.host}/signal`);
  attachSignalHandlers();
}

function attachSignalHandlers() {
  state.ws.onopen = () => signal({ type: 'host_register', code: state.session.code, token: state.session.token });
  state.ws.onmessage = async event => {
    const msg = JSON.parse(event.data);
    if (msg.type === 'host_ready') { $('serverStatus').textContent = 'Sessione protetta attiva'; return; }
    if (msg.type === 'viewer_join') { $('viewerCount').textContent = msg.viewers; $('linkState').classList.add('active'); await createPeer(msg.viewerId); return; }
    if (msg.type === 'viewer_left') { closePeer(msg.viewerId); $('viewerCount').textContent = msg.viewers; if (!msg.viewers) $('linkState').classList.remove('active'); return; }
    if (msg.type === 'signal') await receiveSignal(msg.from, msg.data);
    if (msg.type === 'error') toast(msg.message, true);
  };
  state.ws.onclose = () => { if (state.stream) $('serverStatus').textContent = 'Collegamento segnale interrotto'; };
}

function createPhpSocket(role, session) {
  const socket = { readyState: WebSocket.CONNECTING, closed: false, onopen: null, onmessage: null, onclose: null };
  const post = async payload => {
    const response = await fetch(`${appBase}/api/signal.php`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(payload), cache: 'no-store' });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Errore di collegamento');
    return data;
  };
  const emit = message => socket.onmessage?.({ data: JSON.stringify(message) });
  const poll = async () => {
    if (socket.closed) return;
    try {
      const result = await post({ action: 'poll', role, code: session.code, token: session.token });
      (result.messages || []).forEach(emit);
    } catch (error) { emit({ type: 'error', message: error.message }); }
    setTimeout(poll, 350);
  };
  socket.send = raw => {
    const msg = JSON.parse(raw);
    if (msg.type === 'host_register') {
      post({ action: 'register_host', code: msg.code, token: msg.token }).then(() => { socket.readyState = WebSocket.OPEN; emit({ type: 'host_ready' }); poll(); }).catch(error => emit({ type: 'error', message: error.message }));
    } else if (msg.type === 'signal') {
      post({ action: 'send', role, code: session.code, token: session.token, to: msg.to, data: msg.data }).catch(error => emit({ type: 'error', message: error.message }));
    }
  };
  socket.close = () => { socket.closed = true; socket.readyState = WebSocket.CLOSED; post({ action: 'leave', role, code: session.code, token: session.token }).catch(()=>{}); socket.onclose?.(); };
  setTimeout(() => { socket.readyState = WebSocket.OPEN; socket.onopen?.(); }, 0);
  return socket;
}

async function createPeer(viewerId) {
  const pc = new RTCPeerConnection({ iceServers });
  state.peers.set(viewerId, pc);
  state.stream.getTracks().forEach(track => pc.addTrack(track, state.stream));
  pc.onicecandidate = e => { if (e.candidate) signal({ type: 'signal', to: viewerId, data: { candidate: e.candidate } }); };
  pc.onconnectionstatechange = () => {
    if (pc.connectionState === 'connected') toast('Display collegato');
    if (['failed', 'closed', 'disconnected'].includes(pc.connectionState)) closePeer(viewerId);
  };
  await tuneSender(pc);
  const offer = await pc.createOffer({ offerToReceiveAudio: false, offerToReceiveVideo: false });
  await pc.setLocalDescription(offer);
  signal({ type: 'signal', to: viewerId, data: { description: pc.localDescription } });
}

async function receiveSignal(viewerId, data) {
  const pc = state.peers.get(viewerId); if (!pc) return;
  if (data.description) await pc.setRemoteDescription(data.description);
  if (data.candidate) { try { await pc.addIceCandidate(data.candidate); } catch {} }
}

function closePeer(id) { const pc = state.peers.get(id); if (pc) pc.close(); state.peers.delete(id); }

const qualityMap = {
  auto: { width: 1920, height: 1080, bitrate: 12000000 },
  1080: { width: 1920, height: 1080, bitrate: 8500000 },
  720: { width: 1280, height: 720, bitrate: 4800000 },
  540: { width: 960, height: 540, bitrate: 2600000 }
};

async function applyQuality() {
  if (!state.stream) return;
  const q = qualityMap[$('quality').value], fps = Number($('fps').value);
  const track = state.stream.getVideoTracks()[0];
  try { await track.applyConstraints({ width: { ideal: q.width }, height: { ideal: q.height }, frameRate: { ideal: fps, max: fps } }); } catch {}
  for (const pc of state.peers.values()) await tuneSender(pc);
  updateTrackHud();
}

async function tuneSender(pc) {
  const sender = pc.getSenders().find(s => s.track?.kind === 'video'); if (!sender) return;
  const params = sender.getParameters();
  if (!params.encodings?.length) params.encodings = [{}];
  params.encodings[0].maxBitrate = qualityMap[$('quality').value].bitrate;
  params.encodings[0].maxFramerate = Number($('fps').value);
  params.degradationPreference = $('quality').value === 'auto' ? 'balanced' : 'maintain-resolution';
  try { await sender.setParameters(params); } catch {}
}

function updateTrackHud() {
  const settings = state.stream?.getVideoTracks()[0]?.getSettings() || {};
  $('resolutionHud').textContent = settings.width ? `${settings.width}×${settings.height}` : 'AUTO';
  $('fpsHud').textContent = `${Math.round(settings.frameRate || Number($('fps').value))} FPS`;
  $('screenName').textContent = state.stream?.getVideoTracks()[0]?.label || 'Schermo condiviso';
}

function startStats() {
  clearInterval(state.statsTimer); let previousBytes = 0, previousTime = performance.now();
  state.statsTimer = setInterval(async () => {
    let bytes = 0;
    for (const pc of state.peers.values()) for (const report of (await pc.getStats()).values()) if (report.type === 'outbound-rtp' && report.kind === 'video') bytes += report.bytesSent || 0;
    const now = performance.now(), mbps = previousBytes ? ((bytes - previousBytes) * 8 / (now - previousTime) / 1000) : 0;
    $('bitrateHud').textContent = `${Math.max(0, mbps).toFixed(1)} Mbps`; previousBytes = bytes; previousTime = now;
    updateTrackHud();
  }, 1000);
}

function startCountdown(seconds) {
  clearInterval(state.countdown); let left = seconds;
  state.countdown = setInterval(() => { left = Math.max(0, left - 1); $('timer').textContent = `${String(Math.floor(left / 60)).padStart(2,'0')}:${String(left % 60).padStart(2,'0')}`; if (!left) clearInterval(state.countdown); }, 1000);
}

async function start() {
  try {
    $('startButton').disabled = true; $('startButton').querySelector('span').textContent = 'Seleziona lo schermo…';
    const q = qualityMap[$('quality').value];
    state.stream = await navigator.mediaDevices.getDisplayMedia({
      video: { width: { ideal: q.width }, height: { ideal: q.height }, frameRate: { ideal: Number($('fps').value), max: Number($('fps').value) }, cursor: 'always' },
      audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: { ideal: 2 }, sampleRate: { ideal: 48000 } },
      systemAudio: 'include', windowAudio: 'system', surfaceSwitching: 'include'
    });
    const audioTrack = state.stream.getAudioTracks()[0];
    $('audioHud').textContent = audioTrack ? 'AUDIO PC ON' : 'AUDIO ASSENTE';
    $('audioHud').classList.toggle('audio-missing', !audioTrack);
    if (!audioTrack) toast('Il browser non ha condiviso l’audio: riavvia e seleziona “Condividi audio di sistema”', true);
    $('preview').srcObject = state.stream; $('emptyPreview').classList.add('hidden');
    $('liveBadge').className = 'live-badge'; $('liveBadge').innerHTML = '<span></span> LIVE';
    $('stopButton').disabled = false; $('startButton').querySelector('span').textContent = 'Condivisione attiva';
    state.stream.getVideoTracks()[0].addEventListener('ended', stop);
    await createSession(); await applyQuality(); startStats();
  } catch (error) {
    $('startButton').disabled = false; $('startButton').querySelector('span').textContent = 'Avvia condivisione';
    if (error.name !== 'NotAllowedError') toast(error.message || 'Avvio non riuscito', true);
  }
}

function stop() {
  state.stream?.getTracks().forEach(t => t.stop()); state.stream = null;
  for (const id of [...state.peers.keys()]) closePeer(id);
  state.ws?.close(); state.ws = null; clearInterval(state.statsTimer); clearInterval(state.countdown);
  $('preview').srcObject = null; $('emptyPreview').classList.remove('hidden'); $('liveBadge').className = 'live-badge offline'; $('liveBadge').innerHTML = '<span></span> OFFLINE';
  $('startButton').disabled = false; $('startButton').querySelector('span').textContent = 'Avvia condivisione'; $('stopButton').disabled = true;
  $('qrFrame').classList.add('waiting'); $('code').textContent = '••• •••'; $('viewerCount').textContent = '0'; $('copyLink').disabled = true; $('serverStatus').textContent = 'Server locale pronto';
  $('audioHud').textContent = 'AUDIO —'; $('audioHud').classList.remove('audio-missing');
}

$('startButton').addEventListener('click', start); $('stopButton').addEventListener('click', stop);
$('quality').addEventListener('change', applyQuality); $('fps').addEventListener('change', applyQuality);
$('copyLink').addEventListener('click', async () => { await navigator.clipboard.writeText(state.joinUrl); toast('Link copiato'); });
window.addEventListener('beforeunload', () => state.ws?.close());
