'use strict';

const $ = id => document.getElementById(id);
const state = { stream: null, ws: null, session: null, peers: new Map(), statsTimer: null, countdown: null, joinUrl: '', mode: 'mirror', systemDisplay: null, permanentRequest: null, starting: false };
const iceServers = [{ urls: 'stun:stun.l.google.com:19302' }];
const phpMode = location.pathname.includes('/mirrorpc/');
const appBase = phpMode ? location.pathname.slice(0, location.pathname.indexOf('/mirrorpc/') + 9) : '';
const localInstalled = location.port === '4177' && /^(?:localhost|127\.0\.0\.1|10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)/.test(location.hostname);
const relayBase = phpMode ? appBase : (localInstalled ? 'https://tubetv.online/mirrorpc' : '');
const phpRelay = Boolean(relayBase);

function toast(message, error = false) {
  const el = $('toast'); el.textContent = message; el.className = `toast show${error ? ' error' : ''}`;
  clearTimeout(el.hideTimer); el.hideTimer = setTimeout(() => el.className = 'toast', 3000);
}

function setMode(mode) {
  if (state.stream) return toast('Termina prima la condivisione attiva', true);
  state.mode = mode === 'extend' ? 'extend' : 'mirror';
  document.querySelectorAll('.mode').forEach(button => button.classList.toggle('active', button.dataset.mode === state.mode));
  $('startButton').querySelector('span').textContent = state.mode === 'extend' ? 'Avvia desktop esteso' : 'Avvia duplicazione';
}

async function refreshDisplayStatus() {
  const extendButton = document.querySelector('[data-mode="extend"]');
  try {
    const response = await fetch('/api/system/display', { cache: 'no-store' });
    if (!response.ok) throw new Error('local-api-unavailable');
    state.systemDisplay = await response.json();
    if (state.systemDisplay.driverInstalled) {
      $('extendStatus').textContent = 'Driver pronto';
      extendButton.classList.add('ready');
      extendButton.classList.remove('needs-setup');
    } else {
      $('extendStatus').textContent = state.systemDisplay.supported ? 'Setup necessario' : 'Richiede Windows';
      extendButton.classList.add('needs-setup');
      extendButton.classList.remove('ready');
    }
  } catch {
    state.systemDisplay = null;
    $('extendStatus').textContent = 'Richiede app Windows';
    extendButton.classList.add('needs-setup');
  }
}

function openExtendDialog(message) {
  $('extendMessage').textContent = message || 'Installa il componente firmato e configura Windows in modalità Estendi.';
  $('extendDialog').classList.remove('hidden');
  $('openDisplaySettings').disabled = !state.systemDisplay?.supported;
}

function closeExtendDialog() { $('extendDialog').classList.add('hidden'); }

async function openWindowsDisplaySettings() {
  try {
    const response = await fetch('/api/system/open-display-settings', { method: 'POST' });
    if (!response.ok) throw new Error();
    toast('Impostazioni schermo aperte su Windows');
  } catch {
    toast('Apri Impostazioni → Sistema → Schermo sul PC host', true);
  }
}

function signal(message) {
  if (state.ws?.readyState === WebSocket.OPEN) state.ws.send(JSON.stringify(message));
}

async function createSession() {
  const endpoint = phpRelay ? `${relayBase}/api/session.php` : '/api/session';
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
  if (localInstalled) fetch('/api/device/session', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ code: state.session.code }) }).catch(() => {});
  connectSignal();
}

function connectSignal() {
  if (phpRelay) {
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
    if (msg.type === 'host_ready') {
      $('serverStatus').textContent = 'Sessione protetta attiva';
      if (state.permanentRequest) fetch('/api/device/session', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ code: state.session.code, requestId: state.permanentRequest.id }) }).catch(() => {});
      return;
    }
    if (msg.type === 'viewer_join') {
      const allowed = await authorizeViewer(msg);
      if (!allowed) { signal({ type: 'signal', to: msg.viewerId, data: { accessDenied: true } }); return; }
      $('viewerCount').textContent = msg.viewers; $('linkState').classList.add('active'); await createPeer(msg.viewerId); return;
    }
    if (msg.type === 'viewer_left') { closePeer(msg.viewerId); $('viewerCount').textContent = msg.viewers; if (!msg.viewers) $('linkState').classList.remove('active'); return; }
    if (msg.type === 'signal') await receiveSignal(msg.from, msg.data);
    if (msg.type === 'error') toast(msg.message, true);
  };
  state.ws.onclose = () => { if (state.stream) $('serverStatus').textContent = 'Collegamento segnale interrotto'; };
}

function createPhpSocket(role, session) {
  const socket = { readyState: WebSocket.CONNECTING, closed: false, onopen: null, onmessage: null, onclose: null };
  const post = async payload => {
    const response = await fetch(`${relayBase}/api/signal.php`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(payload), cache: 'no-store' });
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

function askLocalApproval() {
  const dialog = $('accessRequestDialog');
  if (!dialog) return Promise.resolve(window.confirm('Un dispositivo vuole collegarsi a questo PC. Vuoi accettare?'));
  dialog.classList.remove('hidden');
  return new Promise(resolve => {
    const finish = value => { dialog.classList.add('hidden'); $('approveAccess').onclick = null; $('rejectAccess').onclick = null; resolve(value); };
    $('approveAccess').onclick = () => finish(true);
    $('rejectAccess').onclick = () => finish(false);
  });
}

async function authorizeViewer(message) {
  if (!localInstalled) return askLocalApproval();
  try {
    const response = await fetch('/api/device/authorize', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ code: state.session.code, challenge: message.challenge || state.session.challenge, proof: message.proof || '' }), cache: 'no-store' });
    const result = await response.json();
    if (result.allowed) return true;
    if (result.requiresApproval) return askLocalApproval();
    toast(result.message || 'Accesso non autorizzato', true); return false;
  } catch { return askLocalApproval(); }
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
  signal({ type: 'signal', to: viewerId, data: { controlStatus: { available: localInstalled || !phpMode, label: localInstalled || !phpMode ? 'Mouse e tastiera protetti attivi' : 'Apri MirrorPC dall’app Windows per controllare il PC' } } });
}

async function receiveSignal(viewerId, data) {
  if (data?.control) {
    if (!localInstalled && phpMode) return;
    fetch('/api/system/control', {
      method: 'POST', headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ event: data.control }), cache: 'no-store'
    }).catch(() => {});
    return;
  }
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

async function acquireDisplay(q) {
  if (window.mirrorPCNative?.captureDesktop) {
    const sourceId = await window.mirrorPCNative.captureDesktop(state.mode);
    const video = { mandatory: { chromeMediaSource: 'desktop', chromeMediaSourceId: sourceId, maxWidth: q.width, maxHeight: q.height, maxFrameRate: Number($('fps').value) } };
    try { return await navigator.mediaDevices.getUserMedia({ video, audio: { mandatory: { chromeMediaSource: 'desktop' } } }); }
    catch { return navigator.mediaDevices.getUserMedia({ video, audio: false }); }
  }
  return navigator.mediaDevices.getDisplayMedia({ video: { width: { ideal: q.width }, height: { ideal: q.height }, frameRate: { ideal: Number($('fps').value), max: Number($('fps').value) }, cursor: 'always' }, audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: { ideal: 2 }, sampleRate: { ideal: 48000 } }, systemAudio: 'include', windowAudio: 'system', surfaceSwitching: 'include' });
}

async function start(options = {}) {
  if (state.starting || state.stream) return;
  state.starting = true; state.permanentRequest = options.permanentRequest || null;
  try {
    if (state.mode === 'extend' && !state.systemDisplay?.driverInstalled) {
      openExtendDialog(state.systemDisplay?.supported
        ? 'Il display virtuale firmato non risulta ancora installato su questo PC.'
        : 'Per Estendi devi avviare MirrorPC dall’app Windows sul PC host.');
      return;
    }
    $('startButton').disabled = true; $('startButton').querySelector('span').textContent = 'Seleziona lo schermo…';
    const q = qualityMap[$('quality').value];
    state.stream = await acquireDisplay(q);
    const audioTrack = state.stream.getAudioTracks()[0];
    $('audioHud').textContent = audioTrack ? 'AUDIO PC ON' : 'AUDIO ASSENTE';
    $('audioHud').classList.toggle('audio-missing', !audioTrack);
    if (!audioTrack) toast('Il browser non ha condiviso l’audio: riavvia e seleziona “Condividi audio di sistema”', true);
    $('preview').srcObject = state.stream; $('emptyPreview').classList.add('hidden');
    $('liveBadge').className = 'live-badge'; $('liveBadge').innerHTML = '<span></span> LIVE';
    $('stopButton').disabled = false; $('startButton').querySelector('span').textContent = state.mode === 'extend' ? 'Desktop esteso attivo' : 'Duplicazione attiva';
    state.stream.getVideoTracks()[0].addEventListener('ended', stop);
    await createSession(); await applyQuality(); startStats();
  } catch (error) {
    $('startButton').disabled = false; $('startButton').querySelector('span').textContent = state.mode === 'extend' ? 'Avvia desktop esteso' : 'Avvia duplicazione';
    if (error.name !== 'NotAllowedError') toast(error.message || 'Avvio non riuscito', true);
    if (state.permanentRequest) fetch('/api/device/reject', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ requestId: state.permanentRequest.id }) }).catch(()=>{});
    state.permanentRequest = null;
  } finally {
    state.starting = false;
  }
}

async function pollPermanentRequest() {
  if (!localInstalled || state.stream || state.starting) return;
  try {
    const response = await fetch('/api/device/pending', { cache: 'no-store' }); if (!response.ok) return;
    const result = await response.json(), request = result.request; if (!request || state.permanentRequest?.id === request.id) return;
    const authResponse = await fetch('/api/device/authorize', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ requestId: request.id, challenge: request.challenge, proof: request.proof }) });
    const auth = await authResponse.json(); let allowed = Boolean(auth.allowed);
    if (auth.requiresApproval) allowed = await askLocalApproval();
    if (!allowed || !window.mirrorPCNative?.captureDesktop) {
      await fetch('/api/device/reject', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ requestId: request.id }) });
      if (!window.mirrorPCNative?.captureDesktop) toast('Accesso permanente richiede la nuova app nativa Windows', true);
      return;
    }
    await start({ permanentRequest: request });
  } catch {}
}

function stop() {
  if (localInstalled) fetch('/api/device/session', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ code: '' }) }).catch(() => {});
  state.stream?.getTracks().forEach(t => t.stop()); state.stream = null;
  for (const id of [...state.peers.keys()]) closePeer(id);
  state.ws?.close(); state.ws = null; clearInterval(state.statsTimer); clearInterval(state.countdown);
  state.permanentRequest = null;
  $('preview').srcObject = null; $('emptyPreview').classList.remove('hidden'); $('liveBadge').className = 'live-badge offline'; $('liveBadge').innerHTML = '<span></span> OFFLINE';
  $('startButton').disabled = false; $('startButton').querySelector('span').textContent = state.mode === 'extend' ? 'Avvia desktop esteso' : 'Avvia duplicazione'; $('stopButton').disabled = true;
  $('qrFrame').classList.add('waiting'); $('code').textContent = '••• •••'; $('viewerCount').textContent = '0'; $('copyLink').disabled = true; $('serverStatus').textContent = 'Server locale pronto';
  $('audioHud').textContent = 'AUDIO —'; $('audioHud').classList.remove('audio-missing');
}

$('startButton').addEventListener('click', start); $('stopButton').addEventListener('click', stop);
document.querySelectorAll('.mode').forEach(button => button.addEventListener('click', () => setMode(button.dataset.mode)));
$('closeExtendDialog').addEventListener('click', closeExtendDialog);
$('extendDialog').addEventListener('click', event => { if (event.target === $('extendDialog')) closeExtendDialog(); });
$('openDisplaySettings').addEventListener('click', openWindowsDisplaySettings);
$('continueExtend').addEventListener('click', async () => {
  if (phpMode) {
    state.systemDisplay = { supported: true, driverInstalled: true, manuallyConfirmed: true };
    $('extendStatus').textContent = 'Configurazione confermata';
    const extendButton = document.querySelector('[data-mode="extend"]');
    extendButton.classList.add('ready'); extendButton.classList.remove('needs-setup');
    closeExtendDialog();
    toast('Ora premi Avvia desktop esteso e scegli il display virtuale');
    return;
  }
  await refreshDisplayStatus();
  if (state.systemDisplay?.driverInstalled) {
    closeExtendDialog();
    await openWindowsDisplaySettings();
    toast('Imposta Estendi, poi premi Avvia desktop esteso');
  } else toast('Driver non rilevato: completa prima il setup', true);
});
$('quality').addEventListener('change', applyQuality); $('fps').addEventListener('change', applyQuality);
$('copyLink').addEventListener('click', async () => { await navigator.clipboard.writeText(state.joinUrl); toast('Link copiato'); });
window.addEventListener('beforeunload', () => state.ws?.close());
refreshDisplayStatus();
if (localInstalled) setInterval(pollPermanentRequest, 1800);
