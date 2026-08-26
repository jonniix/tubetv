(() => {
  'use strict';

  const API = 'api.php';
  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(location.search);
  const joinToken = params.get('join') || '';
  const forceRemote = params.has('remote') || !!joinToken;
  const state = {
    mode: forceRemote ? 'sender' : 'receiver', sessionId: '', secret: '', codeExpiresAt: 0,
    peer: null, stream: null, signalSeq: 0, commandSeq: 0, pollTimer: 0, countdownTimer: 0,
    paired: false, makingOffer: false, pendingCandidates: [], closed: false
  };

  const APPS = [
    {name:'YouTube', glyph:'▶', color:'#e51b2b', url:'https://www.youtube.com/'},
    {name:'TubeTV', glyph:'TV', color:'#ed1c35', url:new URL('../', location.href).href},
    {name:'Netflix', glyph:'N', color:'#b20710', url:'https://www.netflix.com/'},
    {name:'Prime Video', glyph:'P', color:'#1475e7', url:'https://www.primevideo.com/'},
    {name:'Disney+', glyph:'D+', color:'#243fb7', url:'https://www.disneyplus.com/'},
    {name:'Spotify', glyph:'●', color:'#1db954', url:'https://open.spotify.com/'},
    {name:'Twitch', glyph:'T', color:'#7445d8', url:'https://www.twitch.tv/'},
    {name:'Foto', glyph:'◇', color:'#bf4a8e', url:'https://www.icloud.com/photos/'},
    {name:'Web', glyph:'⌕', color:'#313a48', url:'https://www.google.com/'}
  ];

  async function api(action, data = {}) {
    const response = await fetch(API, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify({action, ...data})
    });
    let body = null;
    try { body = await response.json(); } catch (_) { body = {ok:false, error:'Risposta server non valida'}; }
    if (!response.ok || !body.ok) throw new Error(body.error || `Errore ${response.status}`);
    return body;
  }

  function deviceName() {
    const ua = navigator.userAgent;
    if (/iPhone/i.test(ua)) return 'iPhone';
    if (/iPad/i.test(ua)) return 'iPad';
    if (/Android/i.test(ua)) return 'Android';
    if (/Mac/i.test(ua)) return 'Mac';
    if (/Windows/i.test(ua)) return 'PC Windows';
    return 'Dispositivo web';
  }

  function setPill(id, label, type = '') {
    const el = $(id); if (!el) return;
    el.classList.remove('waiting','offline'); if (type) el.classList.add(type);
    const text = el.querySelector('b'); if (text) text.textContent = label;
  }

  let toastTimer = 0;
  function toast(message) {
    const el = $('toast'); el.textContent = message; el.classList.add('show');
    clearTimeout(toastTimer); toastTimer = setTimeout(() => el.classList.remove('show'), 2200);
  }

  function showMode(mode) {
    state.mode = mode;
    $('receiverView').classList.toggle('is-hidden', mode !== 'receiver');
    $('senderView').classList.toggle('is-hidden', mode !== 'sender');
  }

  function joinUrl(token) {
    const url = new URL('./', location.href); url.search = ''; url.searchParams.set('join', token); return url.href;
  }

  function renderQr(url) {
    const el = $('qrCode'); el.innerHTML = '';
    if (typeof QRCode === 'function') {
      new QRCode(el, {text:url,width:190,height:190,colorDark:'#07090d',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});
    } else {
      el.innerHTML = '<span style="color:#111;font-weight:700;font-size:12px">QR non disponibile</span>';
    }
  }

  async function createReceiverSession() {
    if (state.sessionId && state.secret) api('close', auth()).catch(() => {});
    clearInterval(state.countdownTimer); state.paired = false; state.signalSeq = 0; state.commandSeq = 0;
    setPill('receiverPill','Creo sessione…','waiting'); $('pairCode').textContent = '••••';
    try {
      const result = await api('create', {deviceName: deviceName()});
      state.sessionId = result.sessionId; state.secret = result.receiverSecret; state.codeExpiresAt = result.codeExpiresAt;
      $('pairCode').textContent = result.code; renderQr(joinUrl(result.joinToken));
      setPill('receiverPill','In attesa','waiting'); startCountdown(); startPolling();
    } catch (error) {
      setPill('receiverPill','Server non disponibile','offline'); $('pairHint').textContent = readableError(error);
      setTimeout(createReceiverSession, 5000);
    }
  }

  function startCountdown() {
    const update = () => {
      if (state.paired || state.closed) return;
      const seconds = Math.max(0, state.codeExpiresAt - Math.floor(Date.now()/1000));
      $('timerText').textContent = String(seconds).padStart(2,'0');
      $('timerRing').style.strokeDashoffset = String(106.8 * (1 - seconds/60));
      if (seconds <= 0) createReceiverSession();
    };
    update(); state.countdownTimer = setInterval(update, 1000);
  }

  function auth(extra = {}) { return {sessionId:state.sessionId, role:state.mode, secret:state.secret, ...extra}; }

  async function joinSession(data) {
    setPill('senderPill','Connessione…','waiting'); $('joinError').textContent = '';
    try {
      const result = await api('join', {...data, deviceName:deviceName()});
      state.sessionId = result.sessionId; state.secret = result.senderSecret; state.paired = true;
      $('connectedDevice').textContent = result.receiverName; $('sessionDeviceName').textContent = result.receiverName;
      $('joinStage').classList.add('is-hidden'); $('remoteStage').classList.remove('is-hidden');
      setPill('senderPill','Collegato'); startPolling(); describeCaptureSupport();
      history.replaceState({},'',new URL('./?remote=1',location.href).href);
    } catch (error) {
      setPill('senderPill','Non collegato','offline'); $('joinError').textContent = readableError(error);
    }
  }

  function readableError(error) {
    const code = String(error && error.message || error);
    const map = {CODE_INVALID_OR_EXPIRED:'Codice errato o scaduto. Controlla lo schermo e riprova.',TOO_MANY_ATTEMPTS:'Troppi tentativi. Attendi qualche minuto.',SESSION_UNAUTHORIZED:'La sessione non è più valida.',STORAGE_BUSY:'Server occupato, riprovo tra poco.'};
    return map[code] || 'Connessione momentaneamente non disponibile.';
  }

  function describeCaptureSupport() {
    const notice = $('supportNotice');
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
      notice.classList.remove('is-hidden');
      if (/iPhone|iPad/i.test(navigator.userAgent)) notice.textContent = 'Su iPhone e iPad Safari non può condividere l’intero schermo da una pagina web. Il telecomando funziona già; per il mirroring completo servirà la companion app TubeTV Mirror e la conferma di iOS.';
      else notice.textContent = 'Questo browser non offre la condivisione completa dello schermo. Prova Chrome, Edge oppure la futura companion app TubeTV Mirror.';
      $('startShare').disabled = true;
    }
  }

  function makePeer() {
    if (state.peer) state.peer.close();
    const peer = new RTCPeerConnection({iceServers:[{urls:['stun:stun.l.google.com:19302','stun:stun1.l.google.com:19302']}],bundlePolicy:'max-bundle'});
    peer.onicecandidate = (event) => { if (event.candidate) sendSignal({candidate:event.candidate.toJSON()}); };
    peer.onconnectionstatechange = () => {
      const s = peer.connectionState;
      if (state.mode === 'sender') {
        $('streamState').textContent = s === 'connected' ? 'Schermo in trasmissione' : (s === 'failed' ? 'Connessione interrotta' : 'Collegamento in corso…');
        setPill('senderPill',s === 'connected' ? 'In onda' : 'Collegato',s === 'failed' ? 'offline' : '');
      } else if (s === 'connected') setPill('receiverPill','In onda');
      if (s === 'failed') restartIce();
    };
    peer.ontrack = (event) => {
      if (state.mode !== 'receiver') return;
      const video = $('remoteVideo'); video.srcObject = event.streams[0]; $('screenEmpty').classList.add('is-hidden');
      video.play().catch(() => {});
    };
    state.peer = peer; return peer;
  }

  async function startShare() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) return describeCaptureSupport();
    try {
      const stream = await navigator.mediaDevices.getDisplayMedia({video:{frameRate:{ideal:30,max:30}},audio:true});
      state.stream = stream; const peer = makePeer(); stream.getTracks().forEach(track => peer.addTrack(track,stream));
      stream.getVideoTracks()[0].addEventListener('ended', stopShare, {once:true});
      state.makingOffer = true; const offer = await peer.createOffer(); await peer.setLocalDescription(offer);
      await sendSignal({description:peer.localDescription.toJSON()}); state.makingOffer = false;
      $('startShare').classList.add('active'); $('startShare').innerHTML = '<span>■</span> Interrompi'; $('streamState').textContent = 'Autorizzazione ricevuta';
    } catch (error) {
      state.makingOffer = false;
      if (error && error.name !== 'NotAllowedError') toast('Impossibile avviare la condivisione');
      else toast('Condivisione annullata');
    }
  }

  function stopShare() {
    if (state.stream) state.stream.getTracks().forEach(track => track.stop());
    state.stream = null; if (state.peer) { state.peer.close(); state.peer = null; }
    const button = $('startShare'); button.classList.remove('active'); button.innerHTML = '<span>◉</span> Condividi'; $('streamState').textContent = 'Pronto per condividere';
  }

  async function restartIce() {
    if (!state.peer || state.mode !== 'sender' || !state.stream) return;
    try { const offer = await state.peer.createOffer({iceRestart:true}); await state.peer.setLocalDescription(offer); await sendSignal({description:state.peer.localDescription.toJSON()}); } catch (_) {}
  }

  async function sendSignal(payload) {
    try { await api('signal', auth({payload})); } catch (_) { toast('Segnale di connessione instabile'); }
  }

  async function handleSignal(signal) {
    const payload = signal.payload || {};
    if (payload.description) {
      if (!state.peer) makePeer();
      await state.peer.setRemoteDescription(payload.description);
      while (state.pendingCandidates.length) await state.peer.addIceCandidate(state.pendingCandidates.shift()).catch(() => {});
      if (payload.description.type === 'offer' && state.mode === 'receiver') {
        const answer = await state.peer.createAnswer(); await state.peer.setLocalDescription(answer); await sendSignal({description:state.peer.localDescription.toJSON()});
      }
    } else if (payload.candidate) {
      if (state.peer && state.peer.remoteDescription) await state.peer.addIceCandidate(payload.candidate).catch(() => {});
      else state.pendingCandidates.push(payload.candidate);
    }
  }

  function handleCommand(item) {
    const video = $('remoteVideo'); const command = item.command;
    if (command === 'playPause') video.paused ? video.play().catch(()=>{}) : video.pause();
    else if (command === 'mute') { video.muted = !video.muted; toast(video.muted ? 'Audio disattivato' : 'Audio attivato'); }
    else if (command === 'volumeUp') video.volume = Math.min(1,video.volume+.1);
    else if (command === 'volumeDown') video.volume = Math.max(0,video.volume-.1);
    else if (command === 'home') { $('appNotice').classList.add('is-hidden'); if (document.fullscreenElement) document.exitFullscreen().catch(()=>{}); }
    else if (command === 'back') $('appNotice').classList.add('is-hidden');
    else if (command === 'app') showAppNotice(item.value);
    else if (['up','down','left','right','select','next','previous'].includes(command)) toast(`Comando ${command}`);
  }

  function showAppNotice(url) {
    const app = APPS.find(x => x.url === url); $('appNoticeTitle').textContent = app ? app.name : 'Applicazione'; $('appNoticeIcon').textContent = app ? app.glyph : '↗';
    $('appNotice').classList.remove('is-hidden'); setTimeout(() => $('appNotice').classList.add('is-hidden'),3500);
  }

  async function poll() {
    if (state.closed || !state.sessionId || !state.secret) return;
    try {
      const result = await api('poll',auth({afterSignal:state.signalSeq,afterCommand:state.commandSeq}));
      if (result.status === 'closed') return endSession(false);
      if (state.mode === 'receiver' && result.status === 'paired' && !state.paired) {
        state.paired = true; clearInterval(state.countdownTimer); $('pairStage').classList.add('is-hidden'); $('screenStage').classList.remove('is-hidden');
        setPill('receiverPill','Collegato');
      }
      for (const signal of result.signals || []) { state.signalSeq = Math.max(state.signalSeq,Number(signal.seq)||0); await handleSignal(signal); }
      for (const command of result.commands || []) { state.commandSeq = Math.max(state.commandSeq,Number(command.seq)||0); handleCommand(command); }
      if (!result.peerOnline && state.paired) setPill(state.mode === 'receiver'?'receiverPill':'senderPill','In riconnessione','waiting');
    } catch (error) {
      if (String(error.message) === 'SESSION_UNAUTHORIZED') return endSession(false);
    }
    state.pollTimer = setTimeout(poll,850);
  }

  function startPolling() { clearTimeout(state.pollTimer); state.pollTimer = setTimeout(poll,100); }

  async function sendCommand(command,value=null) {
    try { await api('command',auth({command,value})); if (navigator.vibrate) navigator.vibrate(12); }
    catch (_) { toast('Comando non inviato'); }
  }

  async function endSession(notify=true) {
    state.closed = true; clearTimeout(state.pollTimer); clearInterval(state.countdownTimer); stopShare();
    if (notify && state.sessionId) await api('close',auth()).catch(()=>{});
    state.sessionId='';state.secret='';state.paired=false;
    if (state.mode === 'sender') { $('remoteStage').classList.add('is-hidden'); $('joinStage').classList.remove('is-hidden'); $('manualCode').value=''; setPill('senderPill','Associazione','waiting'); history.replaceState({},'',new URL('./?remote=1',location.href).href); state.closed=false; }
    else location.reload();
  }

  function renderApps() {
    $('appsGrid').innerHTML = APPS.map((app,i) => `<button type="button" data-app="${i}"><span class="app-logo" style="--app-color:${app.color}">${app.glyph}</span><b>${app.name}</b></button>`).join('');
    $('appsGrid').addEventListener('click',event => {
      const button = event.target.closest('[data-app]'); if (!button) return; const app=APPS[Number(button.dataset.app)];
      sendCommand('app',app.url); const opened=window.open(app.url,'_blank','noopener'); if (!opened) toast('Consenti l’apertura della nuova scheda');
    });
  }

  function bindEvents() {
    $('codeForm').addEventListener('submit',event => { event.preventDefault(); const code=$('manualCode').value.replace(/\D/g,'').slice(0,4); if(code.length!==4){$('joinError').textContent='Inserisci tutte e quattro le cifre.';return;} joinSession({code}); });
    $('manualCode').addEventListener('input',event => event.target.value=event.target.value.replace(/\D/g,'').slice(0,4));
    $('becomeReceiver').addEventListener('click',() => { location.href=new URL('./',location.href).href; });
    $('startShare').addEventListener('click',() => state.stream ? stopShare() : startShare());
    $('disconnectButton').addEventListener('click',() => endSession(true));
    $('exitScreen').addEventListener('click',() => endSession(true));
    document.querySelectorAll('[data-command]').forEach(button => button.addEventListener('click',() => sendCommand(button.dataset.command)));
    document.querySelectorAll('.remote-tabs button').forEach(button => button.addEventListener('click',() => {
      document.querySelectorAll('.remote-tabs button').forEach(x=>x.classList.toggle('active',x===button));
      document.querySelectorAll('.tab-panel').forEach(x=>x.classList.toggle('active',x.id===`tab-${button.dataset.tab}`));
    }));
    document.addEventListener('visibilitychange',() => { if (!document.hidden && state.sessionId) startPolling(); });
    window.addEventListener('beforeunload',stopShare);
  }

  function init() {
    bindEvents(); renderApps(); showMode(state.mode);
    if (state.mode === 'receiver') createReceiverSession();
    else if (joinToken) joinSession({joinToken});
  }

  init();
})();
