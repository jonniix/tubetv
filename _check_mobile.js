
const forceMobile = new URLSearchParams(location.search).get('mobile') === '1';
if (!forceMobile && window.innerWidth >= 768) {
  const base = location.pathname.includes('/tube/') ? '/tube/' : './';
  location.replace(base + 'index.html?desktop=1');
}



function iconSvg(name){
  const icons = {
    play:'<svg class="icon-svg" viewBox="0 0 24 24"><path d="m8 5 11 7-11 7V5Z"/></svg>',
    pause:'<svg class="icon-svg" viewBox="0 0 24 24"><path d="M10 4H6v16h4V4Z"/><path d="M18 4h-4v16h4V4Z"/></svg>',
    volume:'<svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/></svg>',
    mute:'<svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="m22 9-6 6"/><path d="m16 9 6 6"/></svg>'
  };
  return icons[name] || '';
}
function setBtnIcon(id, name){ const el=document.getElementById(id); if(el) el.innerHTML = iconSvg(name); }

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// DATA
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
const DB = {
  videos: [], channels: [], series: [], seriesEpisodes: {}, events: [],
  liveQueue: [], palinsesto: [], topContent: [], videoLibrary: {},
  liveState: null,
  settings: { name:'TubeTV', color:'#e63946' }
};

let _lastDataSig = null;
let _currentView = 'home';
let _liveVideoId = null;
let _liveVideoEnded = true;
let _lastLiveScheduleVersion = '';
let _liveAdvTimer = null;
let _livePollTimer = null;
let _refreshTimer = null;
const MAX_LIVE_RETRY = 5;
let _liveRetryState = { videoId: null, attempts: 0, skipRequested: false };
let _catFilter = 'all';
let _catalogExpanded = {};
let _lastAppliedLiveScheduleSigMobile = '';
let _livePhaseWaitWarnKeyMobile = '';
let _mobileLivePreloadedVideoId = '';

// â”€â”€ LocalStorage helpers
function save(k, v){ try { localStorage.setItem('tutv_' + k, JSON.stringify(v)); } catch(e){} }
function load(k, def){ try { const v = localStorage.getItem('tutv_' + k); return v !== null ? JSON.parse(v) : def; } catch(e){ return def; } }
function readPublicLiveSchedule(){
  return null;
}

function getBasePath(){
  return location.pathname.includes('/tube/') ? '/tube/' : './';
}

function getDataJsonUrl(){
  return getBasePath() + 'tubetv-data.json';
}

function getLiveQueueUnified(){
  return Array.isArray(DB.liveQueue) ? DB.liveQueue.slice(0,3) : [];
}

function getLiveOffsetFromState(state){
  if(!state?.currentStartedAt) return 0;

  let offset = Math.floor((Date.now() - new Date(state.currentStartedAt).getTime()) / 1000);

  if(String(state?.phase || 'content') === 'ad' && state.adState?.active){
    const adStarted = new Date(state.adState.startedAt).getTime();
    const adElapsed = Math.floor((Date.now() - adStarted) / 1000);
    offset -= Math.max(0, adElapsed);
  }

  offset = Math.max(0, offset);
  const duration = Number(state.currentDurationSeconds || 0);
  if(duration) offset = Math.min(offset, duration - 1);
  return offset;
}

function getLiveStatePhaseMobile(state){
  const phase = String(state?.phase || '').toLowerCase();
  if(phase === 'transition' || phase === 'ad' || phase === 'content') return phase;
  return 'content';
}

function getPendingNextMobile(state){
  const pendingId = String(state?.pendingNext?.videoId || '');
  if(pendingId) {
    const match = (getLiveSource() || []).find(item => String(item?.videoId || ytId(item?.url || '')) === pendingId);
    return match || state.pendingNext;
  }
  return (getLiveSource() || [])[1] || null;
}

function getLivePhaseElapsedMobile(state){
  const phase = getLiveStatePhaseMobile(state);
  if(phase === 'transition') {
    const started = new Date(state?.transitionState?.startedAt || 0).getTime();
    if(!Number.isFinite(started)) return 0;
    return Math.max(0, Math.floor((Date.now() - started) / 1000));
  }
  if(phase === 'ad') {
    const started = new Date(state?.adState?.startedAt || 0).getTime();
    if(!Number.isFinite(started)) return 0;
    return Math.max(0, Math.floor((Date.now() - started) / 1000));
  }
  return 0;
}

function isMobileLivePhaseExpired(state, graceSeconds=5){
  const phase = getLiveStatePhaseMobile(state);
  if(phase === 'content') return false;

  const startedAt =
    phase === 'transition'
      ? state?.transitionState?.startedAt
      : state?.adState?.startedAt;

  const duration =
    phase === 'transition'
      ? Number(state?.transitionState?.durationSeconds || 3)
      : Number(state?.adState?.durationSeconds || 0);

  const started = new Date(startedAt || 0).getTime();
  if(!Number.isFinite(started) || started <= 0) return true;

  return Date.now() > started + (duration + graceSeconds) * 1000;
}

function preloadMobileNextLive(next){
  if(!next) return;
  const vid = String(next.videoId || ytId(next.url || ''));
  if(!vid || vid === _mobileLivePreloadedVideoId) return;
  const iframe = document.getElementById('mobile-live-preload-iframe');
  if(!iframe) return;
  _mobileLivePreloadedVideoId = vid;
  iframe.src = `https://www.youtube.com/embed/${vid}?autoplay=0&mute=1&controls=0&playsinline=1&start=0&rel=0&modestbranding=1&hl=it&cc_lang_pref=it&cc_load_policy=0&iv_load_policy=3`;
}

function applyLivePhaseUiMobile(state){
  const phase = getLiveStatePhaseMobile(state);
  const trOv = document.getElementById('mobile-live-transition-overlay');
  const trTitle = document.getElementById('mobile-live-transition-title');
  const adOv = document.getElementById('mobile-live-ad-overlay');
  const adCountdown = document.getElementById('mobile-live-ad-countdown');

  if(phase === 'transition') {
    const next = getPendingNextMobile(state);
    if(next) preloadMobileNextLive(next);
    if(trTitle) trTitle.textContent = next?.title || state?.transitionState?.label || 'Contenuto in arrivo';
    trOv?.classList.add('open');
    adOv?.classList.remove('open');
    try { _ytLivePlayer?.pauseVideo?.(); } catch(e) {}

    const duration = Math.max(1, Number(state?.transitionState?.durationSeconds || 3));
    const elapsed = getLivePhaseElapsedMobile(state);
    const warnKey = `transition:${String(state?.transitionState?.startedAt || '')}`;
    if(elapsed > (duration + 3) && _livePhaseWaitWarnKeyMobile !== warnKey) {
      _livePhaseWaitWarnKeyMobile = warnKey;
      console.warn('[LIVE] transition expired, waiting bot content phase');
    }
    return;
  }

  trOv?.classList.remove('open');

  if(phase === 'ad') {
    adOv?.classList.add('open');
    const duration = Math.max(1, Number(state?.adState?.durationSeconds || 0));
    const elapsed = getLivePhaseElapsedMobile(state);
    const remaining = Math.max(0, duration - elapsed);
    const adFree = !!window.TubeTVAccount?.isAdFree?.();
    if(adFree) {
      adOv?.classList.remove('open');
      try { _ytLivePlayer?.pauseVideo?.(); } catch(e) {}
      return;
    }
    if(adCountdown) adCountdown.textContent = `${remaining}s`;
    try { _ytLivePlayer?.pauseVideo?.(); } catch(e) {}

    const warnKey = `ad:${String(state?.adState?.startedAt || '')}`;
    if(elapsed > (duration + 3) && _livePhaseWaitWarnKeyMobile !== warnKey) {
      _livePhaseWaitWarnKeyMobile = warnKey;
      console.warn('[LIVE] ad expired, waiting bot content phase');
    }
    return;
  }

  _livePhaseWaitWarnKeyMobile = '';
  adOv?.classList.remove('open');
}

async function fetchLiveState(){
  const fallback = {
    queue: Array.isArray(DB.liveQueue) ? DB.liveQueue.slice(0,3) : [],
    liveState: (DB.liveState && typeof DB.liveState === 'object') ? DB.liveState : null
  };
  for (let attempt = 1; attempt <= 2; attempt++) {
    try {
      const r = await fetch(getDataJsonUrl() + '?live=' + Date.now() + '&attempt=' + attempt, { cache: 'no-store' });
      if (!r.ok) {
        if (attempt === 2) return fallback;
        continue;
      }
      const text = await r.text();
      const data = JSON.parse(text);
      if (!data?.liveState) {
        console.warn('liveState mancante: sync non garantita');
      }
      return {
        queue: data?.publicLiveSchedule?.liveQueue || data?.liveQueue || fallback.queue,
        liveState: data?.liveState || fallback.liveState
      };
    } catch(e) {
      console.warn('[LIVE MOBILE] fetch server live state failed', { attempt, error: String(e?.message || e) });
      if (attempt === 2) return fallback;
    }
}
  return fallback;
}

function applyGlobalAdStateMobile(liveState){
  if(!_ytLivePlayer) return;
  const phase = getLiveStatePhaseMobile(liveState);
  if(phase === 'ad' || phase === 'transition'){
    try { _ytLivePlayer.pauseVideo?.(); } catch(e) {}
    return;
  }
  try { _ytLivePlayer.playVideo?.(); } catch(e) {}
}

async function fetchServerLiveScheduleMobile(){
  const data = await refreshLiveFromServer();
  return Array.isArray(data?.queue) ? data.queue.slice(0,3) : [];
}
async function refreshLiveFromServer(){
  const state = await fetchLiveState();
  const q = (Array.isArray(state.queue) ? state.queue : []).slice(0,3);
  // Only overwrite liveState when we receive a real object â€” never set to null
  if (state.liveState && typeof state.liveState === 'object') {
    DB.liveState = state.liveState;
  }
  console.log('[MOBILE LIVE] data url', getDataJsonUrl());
  console.log('[MOBILE LIVE] liveState', DB.liveState);
  console.log('[MOBILE LIVE] currentVideoId', DB.liveState?.currentVideoId);
  console.log('[MOBILE LIVE] phase', DB.liveState?.phase);

  const sig = JSON.stringify({
    queue: q.map(x => ({
      videoId: String(x?.videoId || ''),
      startDateTime: String(x?.startDateTime || ''),
      endDateTime: String(x?.endDateTime || '')
    })),
    phase: String(DB.liveState?.phase || 'content'),
    phaseStartedAt: String(DB.liveState?.transitionState?.startedAt || DB.liveState?.adState?.startedAt || '')
  });
  const changed = sig !== _lastAppliedLiveScheduleSigMobile;
  _lastAppliedLiveScheduleSigMobile = sig;
  DB.liveQueue = q;
  DB.palinsesto = q;
  return { changed, queue: q, liveState: DB.liveState };
}

async function refreshLiveScheduleFromBot(){
  const data = await refreshLiveFromServer();
  return !!data?.changed;
}

function ensureLivePlayback(){
  if (_liveVideoId && !_liveVideoEnded) return;
  if (_currentView === 'live') renderLive();
}

// â”€â”€ Escape HTML
function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,"&#39;").replace(/"/g,'&quot;'); }

function showToast(message){
  if (!message) return;
  console.warn('[MOBILE]', message);
}

function getItalianTitle(item){
  return getTextValue(
    item?.title_it ||
    item?.italianTitle ||
    item?.translatedTitle ||
    item?.localized?.it?.title ||
    item?.localized?.['it-IT']?.title ||
    item?.snippet?.localized?.it?.title ||
    item?.snippet?.localized?.['it-IT']?.title ||
    item?.title ||
    item?.originalTitle ||
    ''
  );
}

function getTextValue(value){
  if(!value) return '';
  if(typeof value === 'string') return value;
  if(typeof value === 'object'){
    return value.it || value.italian || value.title || value.name || value.en || Object.values(value).find(v => typeof v === 'string') || '';
  }
  return String(value);
}

// â”€â”€ YouTube helpers
function ytId(url){
  if(!url) return null;
  const m = url.match(/(?:youtu\.be\/|v=|\/embed\/|\/shorts\/)([A-Za-z0-9_-]{11})/);
  return m ? m[1] : null;
}
function bestThumb(id){ return `https://i.ytimg.com/vi/${id}/maxresdefault.jpg`; }
function ytThumb(id){ return `https://img.youtube.com/vi/${id}/hqdefault.jpg`; }
function ytEmbedMobile(videoId, start=0){
  return `https://www.youtube.com/embed/${videoId}?autoplay=1&controls=0&disablekb=1&fs=0&rel=0&modestbranding=1&playsinline=1&enablejsapi=1&hl=it&cc_lang_pref=it&cc_load_policy=0&iv_load_policy=3&start=${Math.max(0,Math.floor(start||0))}`;
}
function ytEmbedItalian(videoId, start=0){ return ytEmbedMobile(videoId, start); }
function ytEmbed(id, start=0){ return ytEmbedMobile(id, start); }

function forceCaptionsOff(player){
  try{ player?.unloadModule?.('captions'); }catch(e){}
  try{ player?.setOption?.('captions','track',{}); }catch(e){}
  try{ player?.setOption?.('captions','reload',false); }catch(e){}
}
function hardDisableCaptions(player){
  const disable=()=>forceCaptionsOff(player);
  disable();
  [300,800,1500,3000,5000].forEach(ms=>setTimeout(disable,ms));
}
window._mobileAudioTracks = window._mobileAudioTracks || [];
window._liveAudioTracks = window._liveAudioTracks || [];

function getYoutubeAudioTracks(player){
  try{
    if(typeof player?.getAvailableAudioTracks==='function'){
      return (player.getAvailableAudioTracks()||[]).map(t=>({
        id:t?.id,
        languageCode:t?.languageCode||t?.language||'',
        displayName:t?.displayName||t?.label||t?.languageCode||'Unknown'
      }));
    }
  }catch(e){
    console.warn('Audio tracks error',e);
  }
  return [];
}
function getYoutubeAudioTracksSafe(player){
  try{
    if(player&&typeof player.getAvailableAudioTracks==='function'){
      return player.getAvailableAudioTracks()||[];
    }
  }catch(e){
    console.warn('getAvailableAudioTracks non disponibile',e);
  }
  return [];
}
function normalizeAudioTrack(t){
  return {
    type:'youtube_track',
    id:t?.id||t?.audioTrackId||t?.languageCode||t?.language||'',
    code:String(t?.languageCode||t?.language||'').toLowerCase(),
    label:t?.displayName||t?.label||t?.languageCode||t?.language||'Audio'
  };
}
function refreshAudioLanguagesAfterReady(player,item,scope){
  setTimeout(()=>{
    const tracks=getYoutubeAudioTracksSafe(player).map(normalizeAudioTrack).filter(t=>t.code||t.id);
    if(scope==='mobile')window._mobileAudioTracks=tracks;
    if(scope==='live')window._liveAudioTracks=tracks;

    const it=tracks.find(t=>String(t.code||'').startsWith('it'));
    if(it&&typeof player?.setAudioTrack==='function'){
      try{player.setAudioTrack(it.id||it);}catch(e){}
    }

    renderMobileLanguageMenu?.();
    renderMobileLiveLanguageMenu?.();
  },600);
}
function getRealAudioLanguages(player,item){
  const ytTracks=getYoutubeAudioTracksSafe(player).map(normalizeAudioTrack);
  if(ytTracks.length){
    const seen=new Set();
    return ytTracks.map(t=>({...t,track:t.id||t})).filter(l=>{
      const key=l.code||String(l.id||'').toLowerCase();
      if(!key||seen.has(key))return false;
      seen.add(key);
      return true;
    });
  }
  const localTracks=[
    ...(item?.availableLanguages||[]),
    ...(item?.audioVersions||[]),
    ...(item?.languageVariants||[])
  ]
    .filter(l=>l&&l.videoId)
    .map(l=>({
      type:'video_variant',
      code:String(l.code||l.languageCode||l.language||'').toLowerCase(),
      label:l.label||l.name||l.code||'Unknown',
      videoId:l.videoId
    }))
    .filter(l=>l.code);
  const seen=new Set();
  return localTracks.filter(l=>{
    if(seen.has(l.code))return false;
    seen.add(l.code);
    return true;
  });
}
async function trySelectItalianAudio(player,item){
  try{
    const ytTracks=getYoutubeAudioTracks(player);
    const itTrack=ytTracks.find(t=>String(t.languageCode||'').toLowerCase().startsWith('it'));
    if(itTrack&&typeof player?.setAudioTrack==='function'){
      player.setAudioTrack(itTrack);
      console.log('Italian audio track selected');
      return true;
    }
  }catch(e){
    console.warn(e);
  }
  const localItalian=(item?.availableLanguages||[]).find(l=>
    String(l.code||l.languageCode||l.language||'').toLowerCase().startsWith('it')&&l.videoId
  );
  if(localItalian?.videoId){
    item.videoId=localItalian.videoId;
    return 'variant';
  }
  return false;
}

function resolvePlayableVideoIdItalian(item){
  const it = (item?.availableLanguages || []).find(l =>
    String(l.code || l.languageCode || l.language).toLowerCase().startsWith('it') && l.videoId
  );
  return it?.videoId || item?.videoId || ytId(item?.url || '') || '';
}
function resolveItalianVideoId(item){
  return resolvePlayableVideoIdItalian(item);
}

// â”€â”€ Watch progress
function wpGet(vid){ return load('wp_' + vid, null); }
function wpSet(vid, data){ save('wp_' + vid, data); }
function wpGetAll(){ return load('watchProgress', {}); }
function saveWatchProgress(vid, currentTime, duration){
  if(!vid || !duration) return;
  const pct = currentTime / duration;
  const status = pct >= 0.92 ? 'completed' : 'started';
  const all = wpGetAll();
  all[vid] = { status, pct, currentTime, duration, lastWatchedAt: new Date().toISOString() };
  save('watchProgress', all);
}

// â”€â”€ Time helpers
function now(){ return new Date(); }
function timeStr(d){ return d.toTimeString().slice(0,5); }

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// DATA LOADING
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
async function loadData(){
  try {
    let r = await fetch(getDataJsonUrl() + '?v=' + Date.now(), {cache:'no-store'});
    if (!r.ok) return;
    const d = await r.json();
    applyData(d);
  } catch(e) {}
}

async function refreshData(force){
  try {
    let r = await fetch(getDataJsonUrl() + '?v=' + Date.now(), {cache:'no-store'});
    if (!r.ok) return false;
    const d = await r.json();
    const sig = (d.exportedAt||'') + '|' + (d.version||'');
    if (!force && sig && sig === _lastDataSig) return false;
    _lastDataSig = sig;
    applyData(d);
    return true;
  } catch(e){ return false; }
}

function applyData(d){
  if (!d || typeof d !== 'object') return;
  if (Array.isArray(d.videos))          DB.videos         = d.videos;
  if (Array.isArray(d.channels))        DB.channels       = d.channels;
  if (Array.isArray(d.series))          DB.series         = d.series;
  if (d.seriesEpisodes)                 DB.seriesEpisodes = d.seriesEpisodes;
  if (Array.isArray(d.events))          DB.events         = d.events;
  if (Array.isArray(d.liveQueue))       DB.liveQueue      = d.liveQueue;
  if (Array.isArray(d.schedule))        DB.palinsesto     = d.schedule;
  if (Array.isArray(d.topContent))      DB.topContent     = d.topContent;
  if (d.videoLibrary)                   DB.videoLibrary   = d.videoLibrary;
  const prevCurrent = String(DB.liveState?.currentVideoId || '');
  if (d.liveState && typeof d.liveState === 'object') {
    DB.liveState = d.liveState;
    const nextCurrent = String(DB.liveState?.currentVideoId || '');
    if(prevCurrent && nextCurrent && prevCurrent !== nextCurrent) {
      const by = String(DB.liveState?.currentChangedBy || DB.liveState?.lastAuthority?.requestedBy || 'unknown');
      const reason = String(DB.liveState?.currentChangeReason || DB.liveState?.lastAuthority?.reason || 'unspecified');
      console.log('[LIVE AUTHORITY]', { oldVideoId: prevCurrent, newVideoId: nextCurrent, reason, by });
      if(by !== 'botWakeCycle') {
        console.error('[LIVE AUTHORITY]', { error: 'current change source is not botWakeCycle', oldVideoId: prevCurrent, newVideoId: nextCurrent, reason, by });
      }
    }
  }
  if (d.settings) {
    DB.settings = d.settings;
    if (d.settings.color) document.documentElement.style.setProperty('--accent', d.settings.color);
    if (d.settings.name)  document.querySelector('.logo').innerHTML = d.settings.name.slice(0,4) + '<span>' + d.settings.name.slice(4) + '</span>';
  }
  normalizeTvFlagsMobile();
}

function normalizeTvFlagsMobile(){
  DB.settings=DB.settings||{};
  if(DB.settings.hideTvFromCatalog===undefined)DB.settings.hideTvFromCatalog=false;
  DB.videos=(DB.videos||[]).map(v=>({...v,isTv:!!(v.isTv||v.category==='TV'||v.contentType==='tv'),contentType:(v.isTv||v.category==='TV'||v.contentType==='tv')?'tv':(v.isKids?'kids':'web'),programTitle:(v.isTv||v.category==='TV'||v.contentType==='tv')?(v.programTitle||v.sourcePlaylistTitle||v.playlistTitle||v.sourceChannelTitle||v.channel||''):(v.programTitle||'')}));
  DB.series=(DB.series||[]).map(s=>({...s,isTv:!!(s.isTv||s.category==='TV'||s.contentType==='tv'),contentType:(s.isTv||s.category==='TV'||s.contentType==='tv')?'tv':'web'}));
  Object.keys(DB.seriesEpisodes||{}).forEach(id=>{
    const ser=(DB.series||[]).find(s=>s.id===id);
    DB.seriesEpisodes[id]=(DB.seriesEpisodes[id]||[]).map(ep=>({...ep,isTv:!!(ep.isTv||ser?.isTv||ep.category==='TV'||ep.contentType==='tv'),contentType:(ep.isTv||ser?.isTv||ep.category==='TV'||ep.contentType==='tv')?'tv':'web',programTitle:(ep.isTv||ser?.isTv||ep.category==='TV'||ep.contentType==='tv')?(ep.programTitle||ep.sourcePlaylistTitle||ser?.sourcePlaylistTitle||ser?.title||''):(ep.programTitle||'')}));
  });
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// NAVIGATION
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function switchView(v){
  document.querySelectorAll('.view').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.nav-links button').forEach(b => b.classList.remove('active'));
  const target = document.getElementById('view-' + v);
  if (target) target.classList.add('active');
  const nb = document.getElementById('nav-' + v);
  if (nb) nb.classList.add('active');
  _currentView = v;

  if (v !== 'live') stopLive();

  if      (v === 'home')    renderHome();
  else if (v === 'live') {
    (async ()=>{
      try{
        await refreshLiveFromServer();
      }catch(e){}
      renderLive();
      setTimeout(async ()=>{
        try{
          if(document.querySelectorAll('#live-player-div iframe').length === 0){
            console.error('[MOBILE LIVE] nessun iframe dopo renderLive, retry');
            await refreshLiveFromServer();
            renderLive();
          }
        }catch(err){}
      }, 2000);
    })();
  }
  else if (v === 'catalog') renderCatalog();
  else if (v === 'series')  renderSeries();
  else if (v === 'kids')    renderKids();
  else if (v === 'tv')      renderTV();
  else if (v === 'eventi')  renderEventi();
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// RENDER: HOME
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function renderHome(){
  buildMobileHeroSlides();
  renderMobileHero();
  const top = DB.topContent.length
    ? DB.topContent.map(tc => DB.videos.find(v => (v.videoId||ytId(v.url||'')) === (tc.videoId||tc.id))).filter(Boolean)
    : [];
  const featured = top.length ? top.slice(0,12) : DB.videos.filter(v=>v.featured).slice(0,12);
  const recent = [...DB.videos].reverse().slice(0,20);

  renderVideoGridTo('home-top-grid', featured.length ? featured : DB.videos.slice(0,12));
  renderVideoGridTo('home-recent-grid', recent);
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// RENDER: LIVE
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function getLiveSource(){
  const unified = getLiveQueueUnified();
  return (Array.isArray(unified) && unified.length) ? unified
  : (Array.isArray(DB.liveQueue) ? DB.liveQueue : []);
}
function getLiveCurrent(){
  const state = DB.liveState || null;
  const source = getLiveSource() || [];
  const stateCurrentId = String(state?.currentVideoId || '');
  if (!stateCurrentId) return null;
  return source.find(item => String(item?.videoId || ytId(item?.url || '')) === stateCurrentId) || null;
}

function resolveCurrentFromLiveState(state){
  const currentId = String(state?.currentVideoId || '');
  if (!currentId) return null;
  return (getLiveSource() || []).find(item => String(item?.videoId || ytId(item?.url || '')) === currentId) || null;
}

function showLiveLoading(){
  document.getElementById('live-title').textContent = 'Live in sincronizzazione';
  document.getElementById('live-meta').textContent = 'In attesa di liveState dal server';
}

function updateLiveTexts(current, state){
  const startLabel = current.startDateTime
    ? new Date(current.startDateTime).toLocaleTimeString('it', {hour:'2-digit',minute:'2-digit'})
    : (current.time || '');
  document.getElementById('live-title').textContent = getItalianTitle(current) || 'In onda ora';
  document.getElementById('live-meta').textContent = (startLabel ? 'Ore ' + startLabel + ' - ' : '') + (current.channel || 'TubeTV');

  const liveLangBtn = document.getElementById('mobile-live-lang-btn');
  if(liveLangBtn) liveLangBtn.textContent = String(_mobileLiveLanguage || 'it').toUpperCase();
  setMobileLiveCCState();
  applyGlobalAdStateMobile(state);
  applyLivePhaseUiMobile(state);
}

function correctLiveDrift(expectedOffset, state){
  if (!_ytLivePlayer || getLiveStatePhaseMobile(state) !== 'content') return;
  try {
    const actual = _ytLivePlayer.getCurrentTime?.() || 0;
    if (Math.abs(actual - expectedOffset) > 10) {
      _ytLivePlayer.seekTo(expectedOffset, true);
    }
  } catch(e) {}
}

function ensureLivePlayer(current, state){
  const phase = getLiveStatePhaseMobile(state);
  const phaseExpired = isMobileLivePhaseExpired(state, 5);
  if(phase !== 'content' && !phaseExpired) return;
  if(phase !== 'content' && phaseExpired){
    console.warn('[MOBILE LIVE] phase expired, fallback content player');
  }

  const expectedOffset = getLiveOffsetFromState(state);
  const currentId = String(state?.currentVideoId || current?.videoId || ytId(current?.url || ''));
  if (!currentId) return;

  if (_liveVideoId === currentId && _ytLivePlayer) {
    correctLiveDrift(expectedOffset, state);
    return;
  }

  _liveVideoId = currentId;
  _liveVideoEnded = false;
  console.log('[MOBILE LIVE] startSeconds', expectedOffset);
  createLivePlayer(currentId, expectedOffset);
}
function getLiveNextItems(current){
  const nowMs = Date.now();
  return getLiveSource().filter(item => {
    if (!item || item.type === 'spot') return false;
    if (current && item.videoId && item.videoId === current.videoId) return false;
    const s = new Date(item.startDateTime || item.start || 0).getTime();
    return Number.isFinite(s) && s > nowMs;
  }).sort((a,b) => new Date(a.startDateTime||a.start) - new Date(b.startDateTime||b.start)).slice(0,5);
}
function setMobileLiveCCState(){
  const btn=document.getElementById('mobile-live-cc-btn');
  if(!btn)return;
  btn.classList.toggle('active',_mobileLiveCaptionsEnabled);
  btn.textContent=_mobileLiveCaptionsEnabled?'CC ON':'CC';
}

function renderMobileLiveLanguageMenu(){
  const menu=document.getElementById('mobile-live-language-menu');
  if(!menu)return;
  const current=getLiveCurrent();
  const langs=(window._liveAudioTracks||[]).length?(window._liveAudioTracks||[]):getRealAudioLanguages(_ytLivePlayer,current||{});
  menu.innerHTML=langs.length
    ? langs.map(l=>`<button onclick="selectMobileLiveLanguage('${esc(l.code)}')">${esc(l.label||l.code)}</button>`).join('')
    : '<button disabled>Audio originale</button>';
}

function toggleMobileLiveLanguageMenu(){
  const menu=document.getElementById('mobile-live-language-menu');
  if(!menu)return;
  renderMobileLiveLanguageMenu();
  menu.classList.toggle('open');
}

function selectMobileLiveLanguage(code){
  const current=getLiveCurrent();
  const pool=(window._liveAudioTracks||[]).length?(window._liveAudioTracks||[]):getRealAudioLanguages(_ytLivePlayer,current||{});
  const lang=pool.find(l=>l.code===(code||'it'));
  if(!lang){
    showToast?.('Audio originale â€” lingua alternativa non disponibile');
    document.getElementById('mobile-live-language-menu')?.classList.remove('open');
    return;
  }
  if(lang.type==='youtube_track'){
    try{
      _ytLivePlayer?.setAudioTrack?.(lang.id||lang);
      showToast?.('Lingua cambiata');
    }catch(e){console.warn(e);}
    document.getElementById('mobile-live-language-menu')?.classList.remove('open');
    return;
  }
  if(!lang.videoId){
    showToast?.('Audio originale â€” lingua alternativa non disponibile');
    document.getElementById('mobile-live-language-menu')?.classList.remove('open');
    return;
  }
  _mobileLiveLanguage=code||'it';
  localStorage.setItem('tutv_live_language',_mobileLiveLanguage);
  const btn=document.getElementById('mobile-live-lang-btn');
  if(btn)btn.textContent=_mobileLiveLanguage.toUpperCase();
  showToast?.('Versione audio separata non consentita in client read-only');
  document.getElementById('mobile-live-language-menu')?.classList.remove('open');
}

function toggleMobileLiveCaptions(){
  _mobileLiveCaptionsEnabled=!_mobileLiveCaptionsEnabled;
  try{
    if(_mobileLiveCaptionsEnabled){
      _ytLivePlayer?.loadModule?.('captions');
      _ytLivePlayer?.setOption?.('captions','track',{languageCode:_mobileLiveLanguage||'it'});
    }else{
      _ytLivePlayer?.unloadModule?.('captions');
      hardDisableCaptions(_ytLivePlayer);
    }
  }catch(e){}
  setMobileLiveCCState();
}

function renderLive(){
  const state = DB.liveState;
  if(!state || !state.currentVideoId){
    showLiveLoading();
    renderLiveSchedule(null, getLiveNextItems(null));
    return;
  }
  const phase = getLiveStatePhaseMobile(state);
  const phaseExpired = isMobileLivePhaseExpired(state, 5);
  // Try to find current in queue; if not present, synthesize from liveState fields
  const current = resolveCurrentFromLiveState(state) || {
    videoId: state.currentVideoId,
    title: state.currentTitle || state.title || 'Live TubeTV',
    channel: state.currentChannel || 'TubeTV',
    durationSeconds: state.currentDurationSeconds || 1800,
    startDateTime: state.currentStartedAt
  };
  console.log('[MOBILE LIVE] phase', phase);
  console.log('[MOBILE LIVE] player div', document.getElementById('live-player-div'));
  console.log('[MOBILE LIVE CHECK]', {
    liveState: DB.liveState,
    phase: DB.liveState?.phase,
    currentVideoId: DB.liveState?.currentVideoId,
    currentStartedAt: DB.liveState?.currentStartedAt,
    currentResolved: resolveCurrentFromLiveState(DB.liveState),
    ytReady: !!(window.YT && YT.Player),
    apiReady: _ytApiReady,
    liveVideoId: _liveVideoId,
    playerExists: !!_ytLivePlayer,
    iframeCount: document.querySelectorAll('#live-player-div iframe').length,
    livePlayerHtml: document.getElementById('live-player-div')?.innerHTML?.slice(0,300)
  });
  updateLiveTexts(current, state);
  const nextItems = getLiveNextItems(current);
  const pending = getPendingNextMobile(state);
  if(pending) preloadMobileNextLive(pending);

  if(phase === 'transition' && !phaseExpired) {
    document.getElementById('live-title').textContent = 'Transizione in corso';
    document.getElementById('live-meta').textContent = pending ? ('Prossimo: ' + (getItalianTitle(pending) || 'Contenuto in arrivo')) : 'Attendo fase content dal bot';
    renderLiveSchedule(current, nextItems);
    return;
  }

  if(phase === 'ad' && !phaseExpired) {
    document.getElementById('live-meta').textContent = 'Pausa ADV sincronizzata';
    renderLiveSchedule(current, nextItems);
    return;
  }

  if(phase !== 'content' && phaseExpired) {
    console.warn('[MOBILE LIVE] phase expired in render, forcing content playback');
  }

  const offset = getLiveOffsetFromState(state);
  const remaining = Math.max(0, Number(state.currentDurationSeconds || current.durationSeconds || 0) - offset);
  if(remaining <= 20 && nextItems[0]) preloadMobileNextLive(nextItems[0]);
  ensureLivePlayer(current, state);
  renderLiveSchedule(current, nextItems);
}

function renderLiveSchedule(current, nextItems){
  const wrap = document.getElementById('live-sched-wrap');
  if (!wrap) return;
  const rows = current ? [current, ...nextItems] : nextItems;
  if (!rows.length) { wrap.innerHTML = ''; return; }
  const labels = current ? ['Ora in onda', ...nextItems.map((_,i)=>i===0?'Prossimo':'PiÃ¹ tardi')]
                         : nextItems.map((_,i)=>i===0?'Prossimo':'PiÃ¹ tardi');
  wrap.innerHTML = '<div class="live-sched-header">Palinsesto</div>' +
    rows.map((e,i) => {
      const t = e.startDateTime ? new Date(e.startDateTime).toLocaleTimeString('it',{hour:'2-digit',minute:'2-digit'}) : 'â€”';
      return `<div class="live-sched-item${i===0&&current?' current':''}">
        <div class="live-sched-time">${labels[i]} Â· ${t}</div>
        <div class="live-sched-title">${esc(getItalianTitle(e)||'Video')}</div>
      </div>`;
    }).join('');
}

function stopLive(){
  if (_liveAdvTimer)  { clearTimeout(_liveAdvTimer);  _liveAdvTimer = null; }
  if (_livePollTimer) { clearInterval(_livePollTimer); _livePollTimer = null; }
  if (_liveDriftTimer) { clearInterval(_liveDriftTimer); _liveDriftTimer = null; }
  _liveRetryState = { videoId: null, attempts: 0, skipRequested: false };
  if (_ytLivePlayer) { try{_ytLivePlayer.stopVideo();}catch(e){} try{_ytLivePlayer.destroy();}catch(e){} _ytLivePlayer = null; }
  const liveOuterReset = document.getElementById('live-player-outer');
  if (liveOuterReset) liveOuterReset.classList.remove('live-fullscreen-fallback');
  _liveVideoId = null;
  _liveVideoEnded = true;
  const outer = document.getElementById('live-player-outer');
  if (outer) outer.innerHTML = '<div id="live-player-div"></div>';
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// RENDER: CATALOG
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function renderCatalog(){
  const allVids = (DB.videos||[]).filter(v=>(!DB.settings?.hideKidsFromCatalog||!v.isKids)&&(!DB.settings?.hideTvFromCatalog||!v.isTv));
  const cats = ['all', ...new Set(allVids.map(v => v.category || 'Generale').filter(Boolean))];

  const tabsWrap = document.getElementById('cat-tabs-wrap');
  tabsWrap.innerHTML = cats.map(c =>
    `<button class="cat-tab${c === _catFilter ? ' active' : ''}" onclick="setCatFilter('${esc(c)}')">${esc(c === 'all' ? 'Tutti' : c)}</button>`
  ).join('');

  renderCatalogContent();
}

function setCatFilter(cat){
  _catFilter = cat;
  _catalogExpanded = {};
  document.querySelectorAll('.cat-tab').forEach(b => b.classList.toggle('active', b.textContent === (cat === 'all' ? 'Tutti' : cat)));
  renderCatalogContent();
}

function renderCatalogContent(){
  const wrap = document.getElementById('catalog-content');
  if (!wrap) return;
  const allVids = (DB.videos||[]).filter(v=>(!DB.settings?.hideKidsFromCatalog||!v.isKids)&&(!DB.settings?.hideTvFromCatalog||!v.isTv));

  if (_catFilter !== 'all') {
    const filtered = allVids.filter(v => (v.category||'Generale') === _catFilter);
    const limit = _catalogExpanded['__filtered'] ? filtered.length : 12;
    const shown = filtered.slice(0, limit);
    wrap.innerHTML = `<div class="video-grid">${shown.map(v => buildVideoCard(v)).join('')}</div>` +
      (filtered.length > limit
        ? `<button class="show-more-btn" onclick="expandCat('__filtered')">Mostra altri (${filtered.length - limit})</button>`
        : '');
    return;
  }

  // All categories as horizontal rows
  const bycat = {};
  allVids.forEach(v => {
    const c = v.category || 'Generale';
    if (!bycat[c]) bycat[c] = [];
    bycat[c].push(v);
  });

  wrap.innerHTML = Object.entries(bycat).map(([cat, vids]) => {
    const limit = _catalogExpanded[cat] ? vids.length : 12;
    const shown = vids.slice(0, limit);
    return `<div class="nfx-section">
      <div class="nfx-row-head">${esc(cat)}
        <span class="nfx-see-all" onclick="setCatFilter('${esc(cat)}')">Vedi tutti</span>
      </div>
      <div class="nfx-row">${shown.map(v => buildVideoCard(v)).join('')}</div>
      ${vids.length > limit ? `<button class="show-more-btn" onclick="expandCat('${esc(cat)}')">Mostra altri (${vids.length - limit})</button>` : ''}
    </div>`;
  }).join('');
}

function expandCat(cat){
  _catalogExpanded[cat] = true;
  renderCatalogContent();
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// RENDER: SERIES
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// SERIE WEB - Unified rendering for desktop and mobile
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function getSeriesCategory(series){
  const text = [
    series.category,
    series.genre,
    series.title,
    series.description,
    series.tags
  ].filter(Boolean).join(' ').toLowerCase();

  if(text.includes('document')) return 'Documentari';
  if(text.includes('gaming')) return 'Gaming';
  if(text.includes('cucina') || text.includes('food')) return 'Cucina';
  if(text.includes('tech') || text.includes('tecnologia')) return 'Tecnologia';
  if(text.includes('kids') || text.includes('bambini')) return 'Kids';
  if(text.includes('sport')) return 'Sport';
  if(text.includes('travel') || text.includes('viaggi')) return 'Viaggi';

  return 'Intrattenimento';
}

function groupSeriesByCategory(seriesList){
  const groups = seriesList.reduce((acc,s)=>{
    const cat = getSeriesCategory(s);
    acc[cat] ||= [];
    acc[cat].push(s);
    return acc;
  },{});
  return Object.keys(groups).sort().reduce((acc,k)=>{
    acc[k] = groups[k];
    return acc;
  },{});
}

function getAllWebSeries(){
  return (DB.series || []).filter(s => !s.isKids && !s.isTv);
}

function getEpisodesForSeries(series){
  const eps = DB.seriesEpisodes[series.id] || series.episodes || [];
  return eps.sort((a,b)=>{
    const aSeason = Number(a.seasonNumber || a.season || 0);
    const bSeason = Number(b.seasonNumber || b.season || 0);
    if(aSeason !== bSeason) return aSeason - bSeason;
    const aEp = Number(a.episodeNumber || a.episodeNum || 0);
    const bEp = Number(b.episodeNumber || b.episodeNum || 0);
    if(aEp !== bEp) return aEp - bEp;
    const aDate = new Date(a.publishedAt || a.date || 0).getTime();
    const bDate = new Date(b.publishedAt || b.date || 0).getTime();
    if(aDate !== bDate) return aDate - bDate;
    return (a.title || '').localeCompare(b.title || '');
  });
}

function renderSeriesCovers(seriesList, opts={}){
  const mobile = !!opts.mobile;
  if(!seriesList.length) return '';
  
  const cards = seriesList.map(s => {
    const videoId = ytId(s.thumbnail || '') || (DB.seriesEpisodes[s.id]?.[0]?.videoId);
    const thumb = s.thumbnail || (videoId ? ytThumb(videoId) : '');
    const title = getItalianTitle(s);
    return `<div class="series-cover-card" onclick="scrollToSeriesExpanded('${esc(s.id)}')">
      <img src="${esc(thumb)}" alt="${esc(title)}" loading="lazy">
    </div>`;
  }).join('');
  
  return `<section class="series-cover-section">
    <h1 style="margin-bottom:14px;">Serie Web</h1>
    <div class="series-cover-row">${cards}</div>
  </section>`;
}

function scrollToSeriesExpanded(seriesId){
  const el = document.querySelector(`[data-series-id="${seriesId}"]`);
  if(el){
    el.scrollIntoView({behavior: 'smooth', block: 'start'});
  }
}

function renderSeriesEpisodeCard(series, episode, idx, opts={}){
  const mobile = !!opts.mobile;
  const videoId = ytId(episode.url || '') || episode.videoId;
  const allWp = wpGetAll();
  const wp = videoId ? allWp[videoId] : null;
  const completed = wp && wp.status === 'completed';
  const started = wp && wp.status === 'started' && (wp.percent || 0) > 2;
  const progress = wp && wp.progressSeconds ? wp.progressSeconds : 0;
  const duration = episode.durationSeconds || episode.durationSecs || 0;
  const pct = duration ? Math.min(100, Math.round((progress / duration) * 100)) : 0;
  const thumb = episode.thumbnail || (videoId ? ytThumb(videoId) : '');
  const epTitle = getItalianTitle(episode) || `Episodio ${idx + 1}`;
  const epNum = episode.episodeNumber || episode.episodeNum || idx + 1;
  const badge = completed ? '<span class="ep-status-badge seen">âœ“ Visto</span>' : (started ? '<span class="ep-status-badge started">Iniziato</span>' : '');
  const barHtml = pct > 0 ? `<div class="ep-progress-wrap"><div class="ep-progress" style="width:${pct}%"></div></div>` : '';
  const durationStr = episode.duration || (duration ? fmtSecs(duration) : '');
  
  return `<div class="episode-card ${completed ? 'completed' : ''} ${started ? 'started' : ''}" onclick="playSeriesEpisode('${esc(series.id)}',${idx})">
    <div class="episode-thumb-wrap"><img src="${esc(thumb)}" alt="${esc(epTitle)}" loading="lazy">${completed ? '<div class="ep-thumb-overlay"></div>' : ''}</div>
    <div class="episode-info">
      <div class="episode-num">Ep. ${epNum}${badge}</div>
      <div class="episode-title">${esc(epTitle)}</div>
      ${durationStr ? `<div class="episode-dur">${durationStr}</div>` : ''}
      ${barHtml}
    </div>
  </div>`;
}

function renderContinueWatchingSeries(opts={}){
  const mobile = !!opts.mobile;
  const series = getAllWebSeries();
  const allWp = wpGetAll();
  const inProgress = [];
  
  for(const s of series){
    const episodes = getEpisodesForSeries(s);
    for(let i = 0; i < episodes.length; i++){
      const ep = episodes[i];
      const vid = ytId(ep.url || '') || ep.videoId;
      const wp = vid ? allWp[vid] : null;
      if(wp && wp.status === 'started' && (wp.percent || 0) < 99){
        inProgress.push({series: s, episode: ep, index: i, wp});
        break;
      }
    }
  }
  
  if(!inProgress.length) return '';
  
  const items = inProgress.slice(0, 12).map(item => renderSeriesEpisodeCard(item.series, item.episode, item.index, opts)).join('');
  return `<section class="continue-watching-section">
    <h2>Continua a guardare</h2>
    <div class="episode-row">${items}</div>
  </section>`;
}

function renderExpandedSeriesByCategory(opts={}){
  const mobile = !!opts.mobile;
  const series = getAllWebSeries();
  const grouped = groupSeriesByCategory(series);
  
  let html = '';
  for(const [category, seriesList] of Object.entries(grouped)){
    html += `<section class="series-category-section">
      <h2 class="series-category-title">${esc(category)}</h2>
      <div class="series-category-list">`;
    
    for(const s of seriesList){
      const episodes = getEpisodesForSeries(s);
      const videoId = ytId(s.thumbnail || episodes[0]?.url || '') || episodes[0]?.videoId;
      const thumb = s.thumbnail || s.cover || (videoId ? ytThumb(videoId) : '');
      const title = getItalianTitle(s);
      const desc = s.description || s.desc || '';
      const truncDesc = desc.length > 120 ? desc.substring(0, 120) + '...' : desc;
      
      const epCards = episodes.slice(0, 8).map((ep, idx) => renderSeriesEpisodeCard(s, ep, idx, opts)).join('');
      
      html += `<article class="series-expanded-card" data-series-id="${esc(s.id)}">
        <div class="series-expanded-head">
          <img class="series-expanded-cover" src="${esc(thumb)}" alt="${esc(title)}" loading="lazy">
          <div class="series-expanded-info">
            <div class="series-expanded-title">${esc(title)}</div>
            <div class="series-expanded-meta">${episodes.length} episodi</div>
            <div class="series-expanded-desc">${esc(truncDesc)}</div>
          </div>
        </div>
        <div class="episode-row">${epCards}</div>
      </article>`;
    }
    
    html += `</div></section>`;
  }
  
  return html;
}

function renderSeriesWebPage(opts={}){
  const mobile = !!opts.mobile;
  const targetId = opts.targetId || (mobile ? 'mobile-content' : 'main-content');
  const el = document.getElementById(targetId);
  if(!el) return;
  
  const series = getAllWebSeries();
  const coversHtml = renderSeriesCovers(series, opts);
  const continueHtml = renderContinueWatchingSeries(opts);
  const categoriesHtml = renderExpandedSeriesByCategory(opts);
  
  el.innerHTML = `<div class="series-web-page">
    ${coversHtml}
    ${continueHtml}
    ${categoriesHtml}
  </div>`;
}

function playSeriesEpisode(seriesId, episodeIdx, opts={}){
  const s = (DB.series || []).find(x => String(x?.id) === String(seriesId));
  if(!s) return;
  const episodes = getEpisodesForSeries(s);
  if(!episodes[episodeIdx]) return;
  const episode = episodes[episodeIdx];
  const videoId = ytId(episode.url || '') || episode.videoId;
  if(!videoId) return;
  const allWp = wpGetAll();
  const wp = allWp[videoId] || {};
  const startSeconds = wp.progressSeconds ? Math.floor(wp.progressSeconds) : 0;

  playVideo(videoId, {
    title: getTextValue(episode.title) || `${getTextValue(s.title) || getItalianTitle(s)} - Ep. ${episodeIdx + 1}`,
    description: getTextValue(episode.description || s.description),
    sourceType: 'series',
    seriesId: s.id,
    episodeIndex: Number(episodeIdx),
    episodeId: episode.id || episode.videoId || videoId,
    startSeconds
  });
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function renderSeries(){
  renderSeriesWebPage({targetId: 'series-web-content', mobile: true});
}

function buildSeriesCard(s){
  const epList = DB.seriesEpisodes[s.id] || s.episodes || [];
  const epCount = epList.length;
  const firstVid = (epList[0] && (epList[0].videoId || ytId(epList[0].url||''))) || '';
  const poster = s.poster || s.posterUrl || s.coverPortrait || s.cover || s.thumbnail || (firstVid ? bestThumb(firstVid) : ytThumb('0'));
  const title = getItalianTitle(s);
  const allWp = wpGetAll();
  const completed = epList.filter(ep => {
    const vid = ytId(ep.url||'')||ep.videoId;
    return allWp[vid]?.status === 'completed';
  }).length;
  const pct = epCount ? Math.round((completed/epCount)*100) : 0;
  return `<div class="series-card" onclick="openSeriesDetail('${esc(s.id)}')">
    <img class="sc-thumb" src="${esc(poster)}" alt="${esc(title)}" loading="lazy" onerror="this.style.opacity=.2">
    <div class="series-card-info">
      <div class="series-card-title">${esc(title)}</div>
      <div class="series-card-meta">${esc(s.category||'Serie')}</div>
      <div class="series-progress-wrap"><div class="series-progress" style="width:${pct}%"></div></div>
    </div>
    ${s.isKids ? '<div style="position:absolute;top:5px;right:5px;"><span class="kids-badge">Kids</span></div>' : ''}
  </div>`;
}

function openSeriesDetail(id){
  const s = DB.series.find(x => x.id === id);
  if (!s) return;
  const epList = DB.seriesEpisodes[s.id] || s.episodes || [];
  const firstVid = (epList[0] && (epList[0].videoId || ytId(epList[0].url||''))) || '';
  const poster = s.poster || s.posterUrl || s.coverPortrait || s.cover || s.thumbnail || (firstVid ? bestThumb(firstVid) : ytThumb('0'));
  const thumb  = s.thumbnail || poster;
  const title = getItalianTitle(s);

  document.getElementById('sd-backdrop').src = thumb;
  document.getElementById('sd-thumb').src    = poster;
  document.getElementById('sd-title').textContent = title;
  document.getElementById('sd-desc').textContent  = s.description || '';

  const allWp = wpGetAll();
  document.getElementById('sd-episodes').innerHTML = epList.map((ep, i) => {
    const vid = ytId(ep.url||'')||ep.videoId||'';
    const thumbUrl = ep.thumbnail || (vid ? ytThumb(vid) : '');
    const wp = allWp[vid] || {};
    const pct = wp.pct ? Math.round(wp.pct * 100) : 0;
    return `<div class="ep-card" onclick="playEpisode('${esc(s.id)}',${i})"><div class="ep-thumb-wrap"><img class="ep-thumb" src="${esc(thumbUrl)}" alt="" loading="lazy" onerror="this.style.opacity=.2"></div>
      <div class="ep-info">
        <div class="ep-num">Ep. ${ep.episodeNum||i+1}</div>
        <div class="ep-title">${esc(getItalianTitle(ep)||'Episodio '+(i+1))}</div>
        ${ep.duration ? `<div class="ep-dur">${ep.duration}</div>` : ''}
        ${pct>0 ? `<div style="height:3px;background:rgba(255,255,255,.15);border-radius:999px;margin-top:5px;overflow:hidden;"><div style="height:100%;width:${pct}%;background:var(--accent);"></div></div>` : ''}
      </div>
    </div>`;
  }).join('');

  document.getElementById('series-detail').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeSeriesDetail(){
  document.getElementById('series-detail').classList.remove('open');
  document.body.style.overflow = '';
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// RENDER: KIDS
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function renderKids(){
  renderKidsHighlight();
  const kidsVids = DB.videos.filter(v => v.isKids).slice(0, 20);
  const kidsSeries = DB.series.filter(s => s.isKids);
  renderVideoGridTo('kids-video-grid', kidsVids);
  const el = document.getElementById('kids-series-grid');
  if (el) el.innerHTML = kidsSeries.map(s => buildSeriesCard(s)).join('');
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// RENDER: TV
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function tvItemVideoId(item){return item.videoId||ytId(item.url||'')||item.id||'';}
function tvNormalizeItem(item,extra={}){
  const videoId=tvItemVideoId(item);
  return {...item,...extra,videoId,url:item.url||(videoId?`https://www.youtube.com/watch?v=${videoId}`:''),isTv:true,contentType:'tv',sourceType:'tv'};
}
function tvGroupMatches(group,q){
  if(!q)return {match:true,items:group.items};
  const hay=[group.title,group.description,group.desc,group.type].filter(Boolean).join(' ').toLowerCase();
  const titleMatch=hay.includes(q);
  const items=group.items.filter(item=>[getItalianTitle(item),item.originalTitle,item.channel,item.category,item.programTitle,item.sourcePlaylistTitle,item.sourceChannelTitle,item.desc,item.description].filter(Boolean).join(' ').toLowerCase().includes(q));
  return {match:titleMatch||items.length>0,items:titleMatch?group.items:items};
}
function groupTvContent(){
  const groups={};
  (DB.series||[]).filter(s=>s.isTv&&!s.isKids).forEach(series=>{
    const firstEp=((DB.seriesEpisodes||{})[series.id]||[])[0]||{};
    const firstVid=firstEp.videoId||ytId(firstEp.url||'');
    const seriesTitle=getItalianTitle(series)||'Programma TV';
    groups[series.id]={id:String(series.id),title:series.sourcePlaylistTitle||series.programTitle||seriesTitle||series.sourceChannelTitle||series.channel||'Programma TV',type:'series',poster:series.poster||series.posterUrl||series.coverPortrait||series.cover||series.thumbnail||(firstVid?bestThumb(firstVid):''),description:series.desc||series.description||'',items:((DB.seriesEpisodes||{})[series.id]||[]).map((ep,index)=>tvNormalizeItem(ep,{seriesId:series.id,episodeIndex:index,programTitle:seriesTitle})),_hasPlaylistTitle:!!series.sourcePlaylistTitle};
  });
  const tvFlatVideos=[...(DB.videos||[]),...Object.values(DB.videoLibrary||{}).flat()].filter(v=>v&&v.isTv&&!v.isKids);
  tvFlatVideos.forEach(video=>{
    const title=video.sourcePlaylistTitle||video.programTitle||video.sourceChannelTitle||video.channel||'TV Generale';
    const groupId=video.sourcePlaylistId||video.playlistId||video.sourceChannelId||video.channelId||video.channel||'tv-generale';
    const vid=video.videoId||ytId(video.url||'');
    if(!groups[groupId])groups[groupId]={id:String(groupId),title,type:video.sourcePlaylistTitle||video.playlistTitle?'playlist':(video.sourceChannelTitle||video.channel?'channel':'mixed'),poster:video.poster||video.posterUrl||video.coverPortrait||video.thumbnail||(vid?bestThumb(vid):''),description:video.sourcePlaylistDescription||video.sourceChannelDescription||'',items:[],_hasPlaylistTitle:!!(video.sourcePlaylistTitle||video.playlistTitle)};
    groups[groupId].items.push(tvNormalizeItem(video,{programTitle:video.programTitle||title}));
    if(!groups[groupId].poster&&video.thumbnail)groups[groupId].poster=video.thumbnail;
  });
  const general={id:'tv-generale',title:'TV Generale',type:'mixed',poster:'',description:'',items:[],_hasPlaylistTitle:false};
  Object.values(groups).forEach(g=>{
    if(g.type!=='series'&&g.items.length<3&&!g._hasPlaylistTitle&&g.id!=='tv-generale'){
      general.items.push(...g.items);if(!general.poster&&g.poster)general.poster=g.poster;delete groups[g.id];
    }
  });
  if(general.items.length){if(groups['tv-generale'])groups['tv-generale'].items.push(...general.items);else groups['tv-generale']=general;}
  return Object.values(groups).filter(g=>g.items.length).sort((a,b)=>(a.title||'').localeCompare(b.title||'','it',{sensitivity:'base'}));
}
function tvSortItems(items,groupType){
  return items.slice().sort((a,b)=>{
    const ea=Number(a.episodeNum||a.episodeNumber||0), eb=Number(b.episodeNum||b.episodeNumber||0);
    if(ea&&eb&&ea!==eb)return ea-eb;
    const da=new Date(a.publishedAt||a.date||a.addedAt||0).getTime(), db=new Date(b.publishedAt||b.date||b.addedAt||0).getTime();
    if(da&&db&&da!==db)return groupType==='series'?da-db:db-da;
    return (a.title||'').localeCompare(b.title||'','it',{sensitivity:'base'});
  });
}
function getTvFilteredGroups(){
  const q=(document.getElementById('tv-search')?.value||'').toLowerCase().trim();
  return groupTvContent().map(g=>{const r=tvGroupMatches(g,q);return r.match?{...g,items:r.items}:null;}).filter(Boolean).sort((a,b)=>(a.title||'').localeCompare(b.title||'','it',{sensitivity:'base'}));
}
function renderTvProgramGrid(groups){return `<div class="section" style="padding:0;margin-bottom:1.5rem;"><div class="section-title">Programmi <span>TV</span></div><div class="series-grid tv-program-grid">${groups.map(g=>buildTvProgramCard(g)).join('')}</div></div>`;}
function buildTvProgramCard(group){
  const firstItem=group.items.find(x=>x.videoId||ytId(x.url||''))||{};
  const firstVid=firstItem.videoId||ytId(firstItem.url||'');
  const poster=group.poster||group.items.find(x=>x.poster)?.poster||group.items.find(x=>x.posterUrl)?.posterUrl||group.items.find(x=>x.coverPortrait)?.coverPortrait||group.items.find(x=>x.thumbnail)?.thumbnail||(firstVid?bestThumb(firstVid):'');
  return `<div class="series-card tv-program-card" onclick="openTvProgram('${esc(group.id)}')">${poster?`<img class="sc-thumb" src="${esc(poster)}" loading="lazy" alt="${esc(group.title)}">`:'<div class="sc-thumb" style="background:var(--bg3);"></div>'}<div class="series-card-info"><div class="series-card-title">${esc(group.title)}</div><div class="series-card-meta">${group.items.length} contenuti</div></div><div class="tv-badge">TV</div></div>`;
}
function renderTvRows(groups){return groups.map(group=>`<div class="nfx-section"><div class="nfx-row-head">${esc(group.title)}<span class="nfx-see-all" onclick="openTvProgram('${esc(group.id)}')">Vedi tutto</span></div><div class="nfx-row">${tvSortItems(group.items,group.type).slice(0,12).map((item,index)=>buildTvItemCard(group,item,index)).join('')}</div></div>`).join('');}
function renderTvContinueWatching(groups){
  const items=[];groups.forEach(group=>group.items.forEach((item,index)=>{const wp=wpGetAll()[tvItemVideoId(item)];if(wp&&wp.status==='started'&&(wp.pct||0)>0.02&&(wp.pct||0)<0.92)items.push({group,item,index,watched:wp.lastWatchedAt||''});}));
  items.sort((a,b)=>b.watched.localeCompare(a.watched));
  return items.length?`<div class="nfx-section"><div class="nfx-row-head">Continua a guardare TV</div><div class="nfx-row">${items.slice(0,12).map(x=>buildTvItemCard(x.group,x.item,x.index)).join('')}</div></div>`:'';
}
function buildTvItemCard(group,item,index){
  const v=tvNormalizeItem(item,{programId:group.id}), id=tvItemVideoId(v), thumb=v.thumbnail||(id?ytThumb(id):''), wp=wpGetAll()[id];
  const pct=wp?.status==='completed'?100:Math.round((wp?.pct||0)*100);
  const meta=group.type==='series'?(v.episodeNum?`Ep. ${v.episodeNum}`:'Programma TV'):(v.category||v.programTitle||group.title||'TV');
  const title=getItalianTitle(v)||'Video TV';
  return `<div class="video-card" onclick="openTvItem('${esc(group.id)}',${index})"><div class="video-thumb">${thumb?`<img src="${esc(thumb)}" alt="${esc(title)}" loading="lazy">`:'<div style="background:var(--bg3);width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2rem;">TV</div>'}<div class="play-overlay"><div class="play-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="white"><polygon points="5,3 19,12 5,21"/></svg></div></div>${group.type==='series'?'<div class="video-badge">TV</div>':''}${pct?`<div class="watch-bar-wrap"><div class="watch-bar" style="width:${pct}%"></div></div>`:''}</div><div class="video-info"><div class="video-title">${esc(title)}</div><div class="video-meta">${esc(meta)}</div></div></div>`;
}
function getAllTvVideos(groups){const seen=new Set();return groups.flatMap(g=>g.items).filter(v=>{const id=tvItemVideoId(v);if(!id||seen.has(id))return false;seen.add(id);return true;}).sort((a,b)=>(getItalianTitle(a)||'').localeCompare(getItalianTitle(b)||'','it',{sensitivity:'base'}));}
function renderTV(){
  const root=document.getElementById('tv-content');if(!root)return;
  const groups=getTvFilteredGroups();
  const queryValue=document.getElementById('tv-search')?.value||'';
  if(!groups.length){root.innerHTML=`<input id="tv-search" class="tv-search" placeholder="Cerca programmi TV, episodi o canali..." value="${esc(queryValue)}" oninput="renderTV()"><p style="color:var(--muted);font-size:.82rem;">Nessun contenuto TV disponibile.</p>`;return;}
  const allVideos=getAllTvVideos(groups);
  root.innerHTML=`<input id="tv-search" class="tv-search" placeholder="Cerca programmi TV, episodi o canali..." value="${esc(queryValue)}" oninput="renderTV()">${renderTvProgramGrid(groups)}${renderTvContinueWatching(groups)}${renderTvRows(groups)}<div class="section" style="padding:0;margin-top:1.5rem;"><div class="section-title">Tutti i video TV <span>A-Z</span></div><div class="video-grid">${allVideos.map(v=>buildVideoCard(tvNormalizeItem(v))).join('')}</div></div>`;
  const search=document.getElementById('tv-search');if(search){const len=search.value.length;search.focus();search.setSelectionRange(len,len);}
}
function ensureTvDetailOverlay(){let el=document.getElementById('tv-detail-overlay');if(!el){el=document.createElement('div');el.id='tv-detail-overlay';el.className='tv-detail-overlay';document.body.appendChild(el);}return el;}
function buildTvDetailEpisodeCard(group,item,index,currentIndex){
  const v=tvNormalizeItem(item,{programId:group.id}), id=tvItemVideoId(v), wp=wpGetAll()[id], thumb=v.thumbnail||(id?ytThumb(id):'');
  const seen=wp?.status==='completed', started=wp?.status==='started'&&(wp.pct||0)>0.02;
  const mins=wp?.currentTime?Math.floor(wp.currentTime/60)+':'+String(Math.floor(wp.currentTime%60)).padStart(2,'0'):'';
  const title=getItalianTitle(v)||'Video TV';
  return `<div class="ep-card tv-episode-card ${seen?'seen':''} ${index===currentIndex?'current':''}" onclick="openTvItem('${esc(group.id)}',${index})"><div class="ep-thumb-wrap"><img src="${esc(thumb)}" alt="${esc(title)}" loading="lazy" onerror="this.style.opacity=.2"></div><div class="ep-info"><div class="ep-num">${v.episodeNum?'Ep. '+v.episodeNum:'TV'} ${seen?'<span class="ep-status-badge seen">Visto</span>':(started?'<span class="ep-status-badge started">Iniziato</span>':'')}</div><div class="ep-title">${esc(title)}</div>${started&&mins?`<div class="ep-resume">Continua da ${mins}</div>`:''}${(seen||started)?`<div style="height:3px;background:rgba(255,255,255,.15);border-radius:999px;margin-top:5px;overflow:hidden;"><div style="height:100%;width:${seen?100:Math.round((wp.pct||0)*100)}%;background:var(--accent);"></div></div>`:''}</div></div>`;
}
function openTvProgram(programId){
  const group=groupTvContent().find(g=>String(g.id)===String(programId));if(!group)return;
  const overlay=ensureTvDetailOverlay(), items=tvSortItems(group.items,group.type), poster=group.poster||items.find(x=>x.thumbnail)?.thumbnail||'';
  const currentIndex=Math.max(0,items.findIndex(item=>{const wp=wpGetAll()[tvItemVideoId(item)];return wp&&wp.status==='started'&&(wp.pct||0)<0.92;}));
  overlay.innerHTML=`<button class="pm-close tv-detail-close" onclick="closeTvProgram()">Ã—</button><div class="tv-detail-hero">${poster?`<img src="${esc(poster)}" alt="">`:''}<div class="tv-detail-body"><div class="tv-badge" style="position:static;display:inline-flex;margin-bottom:.75rem;">TV</div><div class="section-title">${esc(group.title)}</div>${group.description?`<p style="color:var(--muted);font-size:.85rem;">${esc(group.description)}</p>`:''}<p style="color:var(--muted);font-size:.82rem;margin:.5rem 0 1rem;">${items.length} contenuti</p><button class="show-more-btn" onclick="openTvItem('${esc(group.id)}',${currentIndex})">Riprendi</button></div></div><div class="ep-grid" style="padding:1rem;">${items.map((item,index)=>buildTvDetailEpisodeCard(group,item,index,currentIndex)).join('')}</div>`;
  overlay.classList.add('open');document.body.style.overflow='hidden';
}
function closeTvProgram(){const overlay=document.getElementById('tv-detail-overlay');if(overlay)overlay.classList.remove('open');document.body.style.overflow='';}
function openTvItem(programId,index){const group=groupTvContent().find(g=>String(g.id)===String(programId));if(!group)return;const item=tvSortItems(group.items,group.type)[index];if(!item)return;if(group.type==='series'){playEpisode(group.id,Number(item.episodeIndex??index));return;}openPlayer(tvNormalizeItem(item),{sourceType:'tv'});}

function openTVChannel(chId){
  const ch=(DB.channels||[]).find(c=>c.id===chId);
  if(!ch)return;
  const handle=ch.handle||'';
  const libVideos=handle?(DB.videoLibrary||{})[handle]||[]:[];
  const latest=libVideos.find(v=>v.url);
  if(latest){
    const vid=ytId(latest.url||'')||'';
    if(vid)playVideo(vid,ch.name,ch.description||'');
    else window.open(ch.url,'_blank');
  } else {
    window.open(ch.url,'_blank');
  }
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// RENDER: EVENTI
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function renderEventi(){
  const el = document.getElementById('eventi-grid');
  if (!el) return;
  const nowMs = Date.now();
  const sorted = [...(DB.events||[])].sort((a,b) => {
    const ta = new Date(a.date||a.startDate||0).getTime();
    const tb = new Date(b.date||b.startDate||0).getTime();
    return ta - tb;
  });

  el.innerHTML = sorted.map(ev => {
    const ts = new Date(ev.date || ev.startDate || 0).getTime();
    const isLive = ts <= nowMs && nowMs < ts + 3*3600*1000;
    const isEnded = ts + 4*3600*1000 < nowMs;
    const thumb = ev.thumbnail || ev.thumb || '';
    const vid = ev.videoId || ytId(ev.url||'') || '';
    const diff = ts - nowMs;
    let cdText = '';
    if (!isEnded && !isLive && diff > 0) {
      const d = Math.floor(diff/86400000);
      const h = Math.floor((diff%86400000)/3600000);
      const m = Math.floor((diff%3600000)/60000);
      cdText = d > 0 ? `Tra ${d}g ${h}h` : h > 0 ? `Tra ${h}h ${m}min` : `Tra ${m}min`;
    }
    const badgeCls = isLive ? 'live' : isEnded ? 'ended' : '';
    const badgeTxt = isLive ? 'LIVE' : isEnded ? 'Terminato' : 'Prossimamente';
    const title = getItalianTitle(ev) || 'Evento';
    return `<div class="event-card" onclick="${vid ? `playVideo('${esc(vid)}','${esc(title)}','${esc(ev.description||'')}')` : ''}">
      <div class="event-thumb-wrap">
        ${thumb ? `<img src="${esc(thumb)}" alt="${esc(title)}" loading="lazy">` : '<div style="background:var(--bg3);width:100%;height:100%;"></div>'}
        <div class="event-status-badge ${badgeCls}">${badgeTxt}</div>
      </div>
      <div class="event-body">
        <div class="event-title">${esc(title)}</div>
        <div class="event-meta">${esc(ev.type||'Evento')} Â· ${ts ? new Date(ts).toLocaleDateString('it') : ''}</div>
        ${cdText ? `<div class="event-countdown">${cdText}</div>` : ''}
      </div>
    </div>`;
  }).join('') || '<p style="color:var(--muted);padding:.5rem 0;">Nessun evento programmato.</p>';
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// VIDEO CARD + GRID
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function buildVideoCard(v){
  const vid = v.videoId || ytId(v.url || '') || '';
  const thumb = v.thumbnail || (vid ? ytThumb(vid) : '');
  const title = getItalianTitle(v) || 'Video';
  const allWp = wpGetAll();
  const wp = allWp[vid] || {};
  const pct = wp.pct ? Math.round(wp.pct * 100) : 0;
  const isNew = v.isNew || v.new;
  return `<div class="video-card" onclick="playVideo('${esc(vid)}','${esc(title)}','${esc(v.description||'')}',${Math.round(wp.currentTime||0)})">
    <div class="video-thumb">
      <img src="${esc(thumb)}" alt="${esc(title)}" loading="lazy" onerror="this.style.opacity=.2">
      <div class="play-overlay"><div class="play-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="white"><polygon points="5,3 19,12 5,21"/></svg></div></div>
      ${pct > 0 ? `<div class="watch-bar-wrap"><div class="watch-bar" style="width:${pct}%"></div></div>` : ''}
      ${isNew ? '<div class="video-badge">NEW</div>' : ''}
    </div>
    <div class="video-info">
      <div class="video-title">${esc(title)}</div>
      <div class="video-meta">${esc(v.category||'')}${v.duration ? ' Â· '+esc(v.duration) : ''}</div>
    </div>
  </div>`;
}

function renderVideoGridTo(id, videos){
  const el = document.getElementById(id);
  if (!el) return;
  if (!videos.length) { el.innerHTML = '<p style="color:var(--muted);font-size:.82rem;">Nessun contenuto.</p>'; return; }
  el.innerHTML = videos.map(v => buildVideoCard(v)).join('');
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// PLAYER
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
let _ytApiReady    = false;
let _ytModalPlayer = null;
let _mobileCaptionsEnabled = false;
let _mobileLanguage = localStorage.getItem('tutv_preferred_language') || 'it';
let _mobileLiveCaptionsEnabled = false;
let _mobileLiveLanguage = localStorage.getItem('tutv_live_language') || 'it';
let _ytLivePlayer  = null;
let _liveDriftTimer = null;
let _livePlayerQueue = null;
let _mobileLiveWatchdogTimer = null;
let _progressTimer = null;
let _playerMuted   = false;
let _pendingModalPlayer = null;
let _heroSlides = [], _heroIdx = 0, _heroTimer = null;

window.LIVE_FAIL_STATE = window.LIVE_FAIL_STATE || (() => {
  try { return JSON.parse(localStorage.getItem('live_failed_videos') || '{}') || {}; }
  catch(e) { return {}; }
})();

const LIVE_MAX_FAILURES = 5;
const LIVE_FAIL_MAX_TRACKED = 200;

function pruneLiveFailState(){
  const entries = Object.entries(LIVE_FAIL_STATE || {}).sort((a, b) => Number(b[1]?.lastAt || 0) - Number(a[1]?.lastAt || 0));
  const trimmed = entries.slice(0, LIVE_FAIL_MAX_TRACKED);
  const next = {};
  for (const [k, v] of trimmed) next[k] = v;
  window.LIVE_FAIL_STATE = next;
}

function getMobileBasePath(){
  return location.pathname.includes('/tube/') ? '/tube/' : './';
}

function registerLiveVideoFailureMobile(videoId, reason){
  if(!videoId) return 0;

  LIVE_FAIL_STATE[videoId] ||= {
    attempts:0,
    firstAt:Date.now(),
    reasons:[]
  };

  if (Number(LIVE_FAIL_STATE[videoId].attempts || 0) < LIVE_MAX_FAILURES) {
    LIVE_FAIL_STATE[videoId].attempts++;
  }
  LIVE_FAIL_STATE[videoId].reasons.push(reason || 'unknown');
  LIVE_FAIL_STATE[videoId].reasons = LIVE_FAIL_STATE[videoId].reasons.slice(-10);
  LIVE_FAIL_STATE[videoId].lastAt = Date.now();
  LIVE_FAIL_STATE[videoId].failedAt = new Date().toISOString();

  pruneLiveFailState();
  localStorage.setItem('live_failed_videos', JSON.stringify(LIVE_FAIL_STATE));

  if(LIVE_FAIL_STATE[videoId].attempts >= MAX_LIVE_RETRY && !LIVE_FAIL_STATE[videoId].skipRequested){
    LIVE_FAIL_STATE[videoId].skipRequested = true;
    localStorage.setItem('live_failed_videos', JSON.stringify(LIVE_FAIL_STATE));
    showToast?.('Video non disponibile, attendo prossimo contenuto');
    console.warn('[LIVE MOBILE] max retry reached - read-only client does not skip', { videoId, reason });
  }
  return Math.min(MAX_LIVE_RETRY, Number(LIVE_FAIL_STATE[videoId].attempts || 0));
}

function clearLiveVideoFailureMobile(videoId){
  if(!videoId || !LIVE_FAIL_STATE[videoId]) return;
  delete LIVE_FAIL_STATE[videoId];
  localStorage.setItem('live_failed_videos', JSON.stringify(LIVE_FAIL_STATE));
  if (String(_liveRetryState.videoId || '') === String(videoId || '')) {
    _liveRetryState = { videoId: null, attempts: 0, skipRequested: false };
  }
}

function startMobileLivePlaybackWatchdog(videoId){
  clearTimeout(_mobileLiveWatchdogTimer);
  _mobileLiveWatchdogTimer = setTimeout(() => {
    try {
      const state = _ytLivePlayer?.getPlayerState?.();
      if(state !== YT.PlayerState.PLAYING){
        registerLiveVideoFailureMobile(videoId, 'player non parte entro 15 sec');
      }
    } catch(e) {
      registerLiveVideoFailureMobile(videoId, 'player non parte entro 15 sec');
    }
  }, 15000);
}

const CURRENT_PLAYER = {
  videoId: null, title: '', desc: '',
  sourceType: 'catalog',
  seriesId: null,
  epIndex: -1,
  episodeIndex: -1,
  episodeId: null,
  item: null,
  language: 'it'
};

function toggleMobileCaptions(){
  _mobileCaptionsEnabled = !_mobileCaptionsEnabled;
  try{
    if(_mobileCaptionsEnabled){
      _ytModalPlayer?.loadModule?.('captions');
      _ytModalPlayer?.setOption?.('captions','track',{languageCode:_mobileLanguage||'it'});
    }else{
      _ytModalPlayer?.unloadModule?.('captions');
    }
  }catch(e){}
  setMobileCCState();
}

function setMobileCCState(){
  const btn=document.getElementById('pm-cc-btn');
  if(!btn)return;
  btn.classList.toggle('active',_mobileCaptionsEnabled);
  btn.textContent=_mobileCaptionsEnabled?'CC ON':'CC';
}

function pmDedupeByCode(list){
  const seen = new Set();
  return (list||[]).filter(x => {
    const code = String(x?.code || x?.languageCode || x?.language || '').trim();
    if (!code || seen.has(code)) return false;
    seen.add(code);
    x.code = code;
    return true;
  });
}
function pmLanguages(item){
  const yt=(window._mobileAudioTracks||[]);
  if(yt.length)return yt;
  return getRealAudioLanguages(_ytModalPlayer,item||{});
}
function pmCaptions(item){
  return getCaptionLanguages(item);
}
function pmPreferredVideoId(item){
  return item?.videoId||ytId(item?.url||'')||resolveItalianVideoId(item);
}

function getAudioLanguages(item){
  const raw = [
    ...(item?.availableLanguages || []),
    ...(item?.audioVersions || []),
    ...(item?.languageVariants || [])
  ];
  const langs = [];
  raw.forEach(l => {
    const code = String(l.code || l.languageCode || l.language || '').toLowerCase();
    if (!code || !l.videoId) return;
    if (langs.some(x => x.code === code)) return;
    langs.push({code, label: l.label || l.name || code.toUpperCase(), videoId: l.videoId});
  });
  return langs;
}
function getCaptionLanguages(item){
  const raw = [
    ...(item?.captions || []),
    ...(item?.subtitleTracks || [])
  ];
  const langs = [{code:'off', label:'Sottotitoli OFF'}];
  raw.forEach(l => {
    const code = String(l.code || l.languageCode || l.language || '').toLowerCase();
    if (!code) return;
    if (langs.some(x => x.code === code)) return;
    langs.push({code, label: l.label || l.name || code.toUpperCase()});
  });
  if (!langs.some(x => x.code === 'it')) langs.push({code:'it', label:'Italiano'});
  return langs;
}

function updateMobileAudioBadge(item, resolvedVideoId){
  const badge=document.getElementById('pm-audio-badge');
  if(!badge)return;
  const hasItalianAlt=(item?.availableLanguages||[]).some(l=>
    String(l.code||l.languageCode||l.language).toLowerCase().startsWith('it')&&l.videoId
  );
  const originalId=item?.videoId||ytId(item?.url||'');
  const isOriginal=!hasItalianAlt || !resolvedVideoId || resolvedVideoId===originalId;
  badge.classList.toggle('show',isOriginal);
}

function closeTvDetail(){
  closeTvProgram?.();
}

function closeEventOverlay(){
  document.querySelectorAll('.event-overlay,.event-modal,[data-event-overlay="1"]').forEach(el=>el.classList.remove('open'));
}
function pmRenderLanguageMenus(){
  const lm = document.getElementById('pm-language-menu');
  const cm = document.getElementById('pm-caption-menu');
  if (lm) {
    const langs = pmLanguages(CURRENT_PLAYER.item);
    lm.innerHTML = langs.length
      ? langs.map(l => `<button onclick="pmSelectLanguage('${esc(l.code)}')">${esc(l.label || l.code)}</button>`).join('')
      : '<button disabled>Audio originale</button>';
  }
  if (cm) cm.innerHTML = pmCaptions(CURRENT_PLAYER.item).map(c => `<button onclick="pmSelectCaption('${esc(c.code)}')">${esc(c.label || c.code)}</button>`).join('');
  const btn = document.getElementById('pm-lang-btn');
  if (btn) btn.textContent = String(CURRENT_PLAYER.language || 'it').toUpperCase();
}
function renderMobileLanguageMenu(){
  const menu = document.getElementById('pm-language-menu');
  if (!menu) return;
  const langs = (window._mobileAudioTracks||[]).length
    ? (window._mobileAudioTracks||[])
    : getRealAudioLanguages(_ytModalPlayer, CURRENT_PLAYER?.item || CURRENT_PLAYER || {});
  if (!langs.length) {
    menu.innerHTML = '<button disabled>Audio originale</button>';
    return;
  }
  menu.innerHTML = langs.map(l => `
    <button onclick="selectMobileLanguage('${esc(l.code)}')">
      ${esc(l.label)}
    </button>
  `).join('');
}
function pmToggleLanguageMenu(){
  document.getElementById('pm-caption-menu')?.classList.remove('open');
  renderMobileLanguageMenu();
  document.getElementById('pm-language-menu')?.classList.toggle('open');
}
function pmToggleCaptionMenu(){
  document.getElementById('pm-language-menu')?.classList.remove('open');
  document.getElementById('pm-caption-menu')?.classList.toggle('open');
}
function pmSelectLanguage(code){
  const lang = pmLanguages(CURRENT_PLAYER.item).find(l => l.code === code);
  if (!lang) {
    showToast?.('Audio non disponibile per questo video');
    return;
  }
  if (lang.type === 'youtube_track') {
    try {
      _ytModalPlayer?.setAudioTrack?.(lang.track);
      CURRENT_PLAYER.language = code;
      localStorage.setItem('tutv_preferred_language', code);
      showToast?.('Lingua cambiata');
    } catch(e) {
      console.warn(e);
    }
    document.getElementById('pm-lang-btn').textContent = String(code).toUpperCase();
    document.getElementById('pm-language-menu')?.classList.remove('open');
    return;
  }
  if (!lang.videoId) {
    showToast?.('Audio non disponibile per questo video');
    return;
  }
  CURRENT_PLAYER.language = code;
  localStorage.setItem('tutv_preferred_language', code);
  let cur = 0;
  try { cur = _ytModalPlayer?.getCurrentTime?.() || 0; } catch(e) {}
  const item = { ...(CURRENT_PLAYER.item || {}), videoId: lang.videoId, preferredLanguage: code };
  openPlayer(item, { sourceType: CURRENT_PLAYER.sourceType, seriesId: CURRENT_PLAYER.seriesId, epIndex: CURRENT_PLAYER.epIndex, startSeconds: cur });
  document.getElementById('pm-lang-btn').textContent = String(code).toUpperCase();
  document.getElementById('pm-language-menu')?.classList.remove('open');
}
function selectMobileLanguage(code){
  return pmSelectLanguage(code);
}
function pmSelectCaption(code){
  try {
    if (code === 'off') _ytModalPlayer?.unloadModule?.('captions');
    else {
      _ytModalPlayer?.loadModule?.('captions');
      _ytModalPlayer?.setOption?.('captions','track',{ languageCode: code });
    }
  } catch(e) {}
  localStorage.setItem('tutv_caption_language', code);
  document.getElementById('pm-caption-menu')?.classList.remove('open');
}

function ensureModalPlayerDiv(){
  const pmVideo = document.querySelector('.pm-video');
  if (!pmVideo) return false;
  if (!document.getElementById('pm-player-div')) {
    const div = document.createElement('div');
    div.id = 'pm-player-div';
    div.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;';
    pmVideo.insertBefore(div, pmVideo.firstChild);
  }
  return true;
}

function createModalPlayer(vid, startSec){
  if (_ytModalPlayer) { try{_ytModalPlayer.destroy();}catch(e){} _ytModalPlayer = null; }
  if (!ensureModalPlayerDiv()) return;
  _ytModalPlayer = new YT.Player('pm-player-div', {
    videoId: vid,
    playerVars:{ autoplay:1, controls:0, disablekb:1, fs:0, rel:0, modestbranding:1, playsinline:1, enablejsapi:1, hl:'it', cc_lang_pref:'it', cc_load_policy:0, start:Math.floor(Math.max(0,startSec||0)) },
    events:{
      onReady:(e) => {
        try{ e.target.playVideo(); }catch(err){}
        hardDisableCaptions(_ytModalPlayer);
        Promise.resolve(trySelectItalianAudio(_ytModalPlayer, CURRENT_PLAYER.item||{})).then(sel=>{
          if(sel==='variant'){
            const beforeVid=vid;
            const nextVid=CURRENT_PLAYER.item?.videoId||'';
            if(nextVid&&nextVid!==beforeVid){
              const current=_ytModalPlayer?.getCurrentTime?.()||0;
              openPlayer(CURRENT_PLAYER.item||{}, { sourceType:CURRENT_PLAYER.sourceType, seriesId:CURRENT_PLAYER.seriesId, epIndex:CURRENT_PLAYER.epIndex, startSeconds:current });
            }
          }
          pmRenderLanguageMenus();
        });
        refreshAudioLanguagesAfterReady(_ytModalPlayer,CURRENT_PLAYER.item||{},'mobile');
        _mobileCaptionsEnabled = false;
        setMobileCCState();
      },
      onStateChange:(e) => { pmOnStateChange(e.data); }
    }
  });
}

function pmOnStateChange(state){
  const btn = document.getElementById('pm-play-btn');
  if (btn) btn.innerHTML = iconSvg(state === 1 ? 'pause' : 'play');
  if (state === 0 && CURRENT_PLAYER.sourceType === 'series') setTimeout(pmNext, 800);
}

function openPlayer(item, options={}){
  const { sourceType='catalog', seriesId=null, epIndex=-1, episodeIndex=epIndex, episodeId=null, startSeconds } = options;
  closeSeriesDetail();
  closeTvDetail();
  closeEventOverlay();
  const vid = pmPreferredVideoId(item);
  if (!vid) return;
  CURRENT_PLAYER.videoId    = vid;
  CURRENT_PLAYER.title      = getTextValue(item?.title) || getItalianTitle(item) || '';
  CURRENT_PLAYER.desc       = getTextValue(item?.description || item?.desc || '');
  CURRENT_PLAYER.sourceType = sourceType;
  CURRENT_PLAYER.seriesId   = seriesId;
  CURRENT_PLAYER.epIndex    = Number.isFinite(Number(episodeIndex)) ? Number(episodeIndex) : -1;
  CURRENT_PLAYER.episodeIndex = CURRENT_PLAYER.epIndex;
  CURRENT_PLAYER.episodeId = episodeId || item?.episodeId || item?.id || vid;
  CURRENT_PLAYER.item       = item;
  CURRENT_PLAYER.language   = localStorage.getItem('tutv_preferred_language') || item.preferredLanguage || 'it';
  _mobileLanguage = CURRENT_PLAYER.language;
  _mobileCaptionsEnabled = false;
  const allWp = wpGetAll();
  const wp    = allWp[vid] || {};
  const start = startSeconds != null ? startSeconds
    : (wp.currentTime && (wp.pct||0) < 0.92 ? Math.round(wp.currentTime) : 0);
  document.getElementById('pm-title').textContent = CURRENT_PLAYER.title;
  document.getElementById('pm-desc').textContent  = CURRENT_PLAYER.desc;
  document.getElementById('pm-ctrl-title').textContent = CURRENT_PLAYER.title;
  updateMobileAudioBadge(item, vid);
  const prevBtn = document.getElementById('pm-prev-btn');
  const nextBtn = document.getElementById('pm-next-btn');
  if (sourceType === 'series' && seriesId) {
    const series = (DB.series || []).find(x => String(x?.id) === String(seriesId));
    const episodes = series ? getEpisodesForSeries(series) : [];
    const idx = Number(CURRENT_PLAYER.episodeIndex);
    const hasPrev = idx > 0;
    const hasNext = idx >= 0 && idx < episodes.length - 1;
    if (prevBtn) {
      prevBtn.style.display = hasPrev ? '' : 'none';
      prevBtn.disabled = !hasPrev;
    }
    if (nextBtn) {
      nextBtn.style.display = hasNext ? '' : 'none';
      nextBtn.disabled = !hasNext;
    }
  } else {
    if (prevBtn) {
      prevBtn.style.display = 'none';
      prevBtn.disabled = true;
    }
    if (nextBtn) {
      nextBtn.style.display = 'none';
      nextBtn.disabled = true;
    }
  }
  document.getElementById('pm-progress-wrap').style.display = (sourceType === 'live') ? 'none' : '';
  pmRenderLanguageMenus();
  const playerModal=document.getElementById('player-modal');
  playerModal.style.display='';
  playerModal.classList.add('open');
  document.body.style.overflow = 'hidden';
  setMobileCCState();
  if (_ytApiReady) {
    if (_ytModalPlayer && typeof _ytModalPlayer.loadVideoById === 'function') {
      try {
        _ytModalPlayer.loadVideoById({ videoId:vid, startSeconds:start });
        hardDisableCaptions(_ytModalPlayer);
        Promise.resolve(trySelectItalianAudio(_ytModalPlayer, CURRENT_PLAYER.item||{})).then(sel=>{
          if(sel==='variant'){
            const nextVid=CURRENT_PLAYER.item?.videoId||'';
            if(nextVid&&nextVid!==vid){
              openPlayer(CURRENT_PLAYER.item||{}, { sourceType:CURRENT_PLAYER.sourceType, seriesId:CURRENT_PLAYER.seriesId, epIndex:CURRENT_PLAYER.epIndex, startSeconds:start });
            }
          }
          pmRenderLanguageMenus();
        });
        refreshAudioLanguagesAfterReady(_ytModalPlayer,CURRENT_PLAYER.item||{},'mobile');
      }
      catch(e) { createModalPlayer(vid, start); }
    } else {
      createModalPlayer(vid, start);
    }
  } else {
    _pendingModalPlayer = { vid, start };
  }
  startProgressUpdater();
}

function playVideo(vid, titleOrOpts, desc, startSec=0){
  if (titleOrOpts && typeof titleOrOpts === 'object' && !Array.isArray(titleOrOpts)) {
    const opts = titleOrOpts;
    openPlayer(
      {
        videoId: vid,
        title: getTextValue(opts.title),
        description: getTextValue(opts.description),
        episodeId: opts.episodeId || null
      },
      {
        startSeconds: Number(opts.startSeconds || 0),
        sourceType: opts.sourceType || 'catalog',
        seriesId: opts.seriesId ?? null,
        episodeIndex: Number.isFinite(Number(opts.episodeIndex)) ? Number(opts.episodeIndex) : -1,
        episodeId: opts.episodeId || null
      }
    );
    return;
  }
  openPlayer(
    { videoId:vid, title:getTextValue(titleOrOpts), description:getTextValue(desc) },
    { startSeconds:startSec, sourceType:'catalog' }
  );
}

function playEpisode(seriesId, epIndex){
  const s = DB.series.find(x => x.id === seriesId);
  if (!s) return;
  const epList = DB.seriesEpisodes[seriesId] || s.episodes || [];
  if (epIndex < 0 || epIndex >= epList.length) return;
  const ep  = epList[epIndex];
  const vid = ytId(ep.url||'')||ep.videoId||'';
  if (!vid) return;
  if (CURRENT_PLAYER.videoId && _ytModalPlayer &&
      document.getElementById('player-modal').classList.contains('open')) {
    try { const ct=_ytModalPlayer.getCurrentTime(),dur=_ytModalPlayer.getDuration(); if(ct&&dur) saveWatchProgress(CURRENT_PLAYER.videoId,ct,dur); } catch(e){}
  }
  const allWp = wpGetAll();
  const wp    = allWp[vid] || {};
  const start = wp.currentTime && (wp.pct||0) < 0.92 ? Math.round(wp.currentTime) : 0;
  openPlayer(
    { videoId:vid, title:getItalianTitle(ep)||('Episodio '+(epIndex+1)), description:ep.description||'' },
    { sourceType:'series', seriesId, epIndex, startSeconds:start }
  );
}

function closePlayer(){
  if (CURRENT_PLAYER.videoId && _ytModalPlayer) {
    try { const ct=_ytModalPlayer.getCurrentTime(),dur=_ytModalPlayer.getDuration(); if(ct&&dur) saveWatchProgress(CURRENT_PLAYER.videoId,ct,dur); } catch(e){}
  }
  if (_ytModalPlayer) { try{_ytModalPlayer.stopVideo();}catch(e){} }
  try {
    const iframe=_ytModalPlayer?.getIframe?.();
    if(iframe)iframe.src='';
  } catch(e) {}
  const directIframe=document.querySelector('#pm-player-div iframe');
  if(directIframe)directIframe.src='';
  stopProgressUpdater();
  const playerModal=document.getElementById('player-modal');
  playerModal.classList.remove('open');
  playerModal.classList.remove('fullscreen-mode');
  playerModal.style.display='none';
  document.body.style.overflow = '';
  CURRENT_PLAYER.videoId = null;
}

function startProgressUpdater(){ stopProgressUpdater(); _progressTimer = setInterval(pmUpdateProgress, 1000); }
function stopProgressUpdater(){ if (_progressTimer){ clearInterval(_progressTimer); _progressTimer = null; } }

function pmUpdateProgress(){
  if (!_ytModalPlayer || CURRENT_PLAYER.sourceType === 'live') return;
  try {
    const ct  = _ytModalPlayer.getCurrentTime()||0;
    const dur = _ytModalPlayer.getDuration()||0;
    if (dur > 0) {
      const fill = document.getElementById('pm-progress-fill');
      if (fill) { const pct=(ct/dur*100); fill.style.width = pct + '%'; const bar=document.getElementById('pm-progress-bar'); if(bar) bar.style.setProperty('--p', pct + '%'); }
      const td = document.getElementById('pm-time-display');
      if (td) td.textContent = fmtTime(ct) + ' / ' + fmtTime(dur);
      if (CURRENT_PLAYER.videoId) saveWatchProgress(CURRENT_PLAYER.videoId, ct, dur);
    }
  } catch(e){}
}

function pmTogglePlay(){
  if (!_ytModalPlayer) return;
  try { const st=_ytModalPlayer.getPlayerState(); if(st===1) _ytModalPlayer.pauseVideo(); else _ytModalPlayer.playVideo(); } catch(e){}
}
function pmToggleMute(){
  if (!_ytModalPlayer) return;
  try {
    _playerMuted = !_playerMuted;
    if (_playerMuted) _ytModalPlayer.mute(); else _ytModalPlayer.unMute();
    setBtnIcon('pm-mute-btn', _playerMuted ? 'mute' : 'volume');
  } catch(e){}
}
function pmSetVolume(v){
  if (!_ytModalPlayer) return;
  try {
    _ytModalPlayer.setVolume(Number(v));
    _playerMuted = Number(v) === 0;
    setBtnIcon('pm-mute-btn', _playerMuted ? 'mute' : 'volume');
    if (!_playerMuted) _ytModalPlayer.unMute();
  } catch(e){}
}
function pmSeek(e){
  e.stopPropagation();
  if (!_ytModalPlayer || CURRENT_PLAYER.sourceType === 'live') return;
  try {
    const bar = document.getElementById('pm-progress-bar');
    if (!bar) return;
    const rect    = bar.getBoundingClientRect();
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    const pct     = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
    const dur     = _ytModalPlayer.getDuration()||0;
    if (dur > 0) _ytModalPlayer.seekTo(pct * dur, true);
  } catch(e2){}
}
function pmFullscreen(){
  const modal = document.getElementById('player-modal');
  const wrap = document.querySelector('.pm-player-wrap');
  const iframe = _ytModalPlayer && _ytModalPlayer.getIframe ? _ytModalPlayer.getIframe() : null;
  const target = wrap || iframe || modal;
  if (!modal || !target) return;

  const fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;

  if (fsEl || modal.classList.contains('fullscreen-mode')) {
    modal.classList.remove('fullscreen-mode');
    try {
      if (document.exitFullscreen) document.exitFullscreen();
      else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
      else if (document.mozCancelFullScreen) document.mozCancelFullScreen();
      else if (document.msExitFullscreen) document.msExitFullscreen();
    } catch(e) {}
    return;
  }

  try {
    if (target.requestFullscreen) target.requestFullscreen();
    else if (target.webkitRequestFullscreen) target.webkitRequestFullscreen();
    else if (target.mozRequestFullScreen) target.mozRequestFullScreen();
    else if (target.msRequestFullscreen) target.msRequestFullscreen();
    else modal.classList.add('fullscreen-mode');

    setTimeout(() => {
      if (!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement)) {
        modal.classList.add('fullscreen-mode');
      }
    }, 350);
  } catch(e) {
    modal.classList.add('fullscreen-mode');
  }
}
function pmPrev(){
  if (CURRENT_PLAYER.sourceType !== 'series') return;
  const series = (DB.series || []).find(x => String(x?.id) === String(CURRENT_PLAYER.seriesId));
  if (!series) return;
  const episodes = getEpisodesForSeries(series);
  const currentIndex = Number(CURRENT_PLAYER.episodeIndex);
  if (!Number.isFinite(currentIndex) || currentIndex <= 0 || !episodes.length) return;
  playSeriesEpisode(CURRENT_PLAYER.seriesId, currentIndex - 1);
}
function pmNext(){
  if (CURRENT_PLAYER.sourceType !== 'series') return;
  const series = (DB.series || []).find(x => String(x?.id) === String(CURRENT_PLAYER.seriesId));
  if (!series) return;
  const episodes = getEpisodesForSeries(series);
  const currentIndex = Number(CURRENT_PLAYER.episodeIndex);
  if (!Number.isFinite(currentIndex) || currentIndex < 0 || currentIndex >= episodes.length - 1) return;
  playSeriesEpisode(CURRENT_PLAYER.seriesId, currentIndex + 1);
}
function fmtTime(s){ s=Math.floor(s||0); const m=Math.floor(s/60),sec=s%60; return m+':'+(sec<10?'0':'')+sec; }

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// PERIODIC REFRESH
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function startRefreshLoop(){
  if (_refreshTimer) clearInterval(_refreshTimer);
  _refreshTimer = setInterval(async () => {
    if (_currentView === 'live') return;
    const changed = await refreshData(false);
    if (!changed) return;
    if (_currentView === 'home')    renderHome();
    else if (_currentView === 'catalog') renderCatalog();
    else if (_currentView === 'series')  renderSeries();
    else if (_currentView === 'kids')    renderKids();
    else if (_currentView === 'tv')      renderTV();
    else if (_currentView === 'eventi')  renderEventi();
  }, 30000);
}

function startLiveSyncLoop(){
  if (_livePollTimer) clearInterval(_livePollTimer);
  _livePollTimer = setInterval(async () => {
    try {
      await refreshLiveFromServer();
      if (_currentView === 'live') {
        renderLive();
      }
    } catch(err) {
      console.warn('[LIVE MOBILE] poll schedule failed', err);
    }
  }, 5000);
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// INIT
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
async function init(){
  setBtnIcon('live-mute-btn','mute');
  setBtnIcon('pm-mute-btn','volume');
  await loadData();
  await refreshLiveScheduleFromBot();
  renderHome();
  // If user already navigated to live view before init completed, re-render it now
  if (_currentView === 'live') renderLive();
  startRefreshLoop();
  startLiveSyncLoop();
}


function syncFullscreenFallbackClasses(){
  const modal = document.getElementById('player-modal');
  const live = document.getElementById('live-player-outer');
  const fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;
  if (fsEl) return;
  // Do not remove fallback classes here: on iOS there may be no real Fullscreen API.
}
document.addEventListener('fullscreenchange', syncFullscreenFallbackClasses);
document.addEventListener('webkitfullscreenchange', syncFullscreenFallbackClasses);

document.addEventListener('DOMContentLoaded', init);

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// HERO CAROUSEL
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function buildMobileHeroSlides(){
  const slides = [];
  const liveItem = getLiveCurrent();
  if (liveItem) {
    const vid = liveItem.videoId || ytId(liveItem.url||'');
    slides.push({ type:'live', title:getItalianTitle(liveItem)||'In onda ora', subtitle:liveItem.channel||'Live TV', thumb:vid?ytThumb(vid):'', isLive:true });
  }
  DB.topContent.slice(0,5).forEach(tc => {
    const v = DB.videos.find(x=>(x.videoId||ytId(x.url||''))===(tc.videoId||tc.id));
    if (v && !slides.some(s=>s.title===getItalianTitle(v))) {
      const vid = v.videoId||ytId(v.url||'')||'';
      slides.push({ type:'top', title:getItalianTitle(v)||'', subtitle:v.category||'In evidenza', thumb:v.thumbnail||(vid?ytThumb(vid):''), vid });
    }
  });
  DB.videos.filter(v=>v.featured).slice(0,5).forEach(v => {
    if (!slides.some(s=>s.title===getItalianTitle(v))) {
      const vid = v.videoId||ytId(v.url||'')||'';
      slides.push({ type:'featured', title:getItalianTitle(v)||'', subtitle:v.category||'', thumb:v.thumbnail||(vid?ytThumb(vid):''), vid });
    }
  });
  _heroSlides = slides.slice(0,8);
}

function heroAction(idx){
  const s = _heroSlides[idx];
  if (!s) return;
  if (s.isLive) { switchView('live'); return; }
  if (s.vid) openPlayer({ videoId:s.vid, title:s.title, description:'' }, { sourceType:'catalog' });
}

function renderMobileHero(){
  const wrap = document.getElementById('mobile-hero-carousel');
  if (!wrap || !_heroSlides.length) { if (wrap) wrap.innerHTML=''; return; }
  const s = _heroSlides[_heroIdx];
  if (!s) return;
  const badge = s.type==='live' ? '<div class="hero-live-badge">LIVE ORA</div>'
    : s.type==='top' ? '<div class="hero-top-badge">TOP</div>' : '';
  wrap.innerHTML =
    `<div class="mhero-slide" onclick="heroAction(${_heroIdx})">`+
    `<img class="mhero-img" src="${esc(s.thumb)}" alt="${esc(s.title)}" loading="lazy" onerror="this.style.opacity=.15">`+
    `<div class="mhero-gradient"></div>`+
    `<div class="mhero-content">${badge}<div class="mhero-title">${esc(s.title)}</div><div class="mhero-sub">${esc(s.subtitle||'')}</div></div>`+
    `<button class="mhero-prev" aria-label="Precedente" onclick="event.stopPropagation();prevMobileHero()"><svg class="icon-svg" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg></button>`+
    `<button class="mhero-next" aria-label="Successivo" onclick="event.stopPropagation();nextMobileHero()"><svg class="icon-svg" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button>`+
    `</div>`+
    `<div class="mhero-dots">${_heroSlides.map((_,i)=>`<button class="mhero-dot${i===_heroIdx?' active':''}" onclick="event.stopPropagation();goMobileHero(${i})"></button>`).join('')}</div>`;
  if (_heroTimer) clearTimeout(_heroTimer);
  _heroTimer = setTimeout(nextMobileHero, 6000);
}
function nextMobileHero(){ if(!_heroSlides.length) return; _heroIdx=(_heroIdx+1)%_heroSlides.length; renderMobileHero(); }
function prevMobileHero(){ if(!_heroSlides.length) return; _heroIdx=(_heroIdx-1+_heroSlides.length)%_heroSlides.length; renderMobileHero(); }
function goMobileHero(i){ _heroIdx=i; renderMobileHero(); }

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// KIDS HIGHLIGHT
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function renderKidsHighlight(){
  const sec  = document.getElementById('kids-highlight-section');
  const grid = document.getElementById('kids-highlight-grid');
  if (!sec || !grid) return;

  const topKids = DB.topContent.filter(tc=>tc.isKids)
    .map(tc=>DB.videos.find(v=>(v.videoId||ytId(v.url||''))===(tc.videoId||tc.id))).filter(Boolean);
  const featKids = DB.videos.filter(v=>v.isKids&&v.featured).slice(0,6);
  const allWp = wpGetAll();
  const kidsEps = [];
  DB.series.filter(s=>s.isKids).forEach(s=>{
    const eps = DB.seriesEpisodes[s.id]||s.episodes||[];
    eps.filter(ep=>{ const vid=ytId(ep.url||'')||ep.videoId; return allWp[vid]?.status==='started'; })
       .slice(0,2).forEach(ep=>{
         const vid = ytId(ep.url||'')||ep.videoId||'';
         if (vid) kidsEps.push({ videoId:vid, title:`${getItalianTitle(s)} - ${getItalianTitle(ep)||'Ep.'}`, thumbnail:ep.thumbnail||ytThumb(vid), category:'Kids' });
       });
  });
  const items = [...topKids,...featKids,...kidsEps]
    .filter((v,i,arr)=>arr.findIndex(x=>(x.videoId||ytId(x.url||''))===(v.videoId||ytId(v.url||'')))===i)
    .slice(0,8);
  if (!items.length) { sec.style.display='none'; return; }
  sec.style.display='block';
  grid.innerHTML = items.map(v=>buildVideoCard(v)).join('');
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// LIVE PLAYER (YT.Player inline)
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function ensureLivePlayerDiv(){
  const outer = document.getElementById('live-player-outer');
  if (!outer) return false;
  if (!document.getElementById('live-player-div')) {
    const div = document.createElement('div');
    div.id = 'live-player-div';
    outer.insertBefore(div, outer.firstChild);
  }
  return true;
}

function forceMobileLiveIframeFallback(vid, offset){
  const div = document.getElementById('live-player-div');
  if(!div || !vid) return;

  const safeOffset = Math.max(0, Math.floor(offset || 0));
  const safeVid = encodeURIComponent(String(vid || ''));
  const iframe = document.createElement('iframe');
  iframe.src = `https://www.youtube.com/embed/${safeVid}?autoplay=1&mute=1&playsinline=1&controls=0&rel=0&modestbranding=1&hl=it&cc_lang_pref=it&cc_load_policy=0&iv_load_policy=3&start=${safeOffset}`;
  iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
  iframe.setAttribute('allowfullscreen', '');
  iframe.style.width = '100%';
  iframe.style.height = '100%';
  iframe.style.border = '0';
  div.innerHTML = '';
  div.appendChild(iframe);
}

function createLivePlayer(vid, offset){
  if(!window.YT || !YT.Player || !_ytApiReady){
    console.warn('[MOBILE LIVE] YouTube API non pronta, metto in coda player', vid, offset);
    _livePlayerQueue = {vid, offset};
    setTimeout(() => {
      try{
        const iframeCount = document.querySelectorAll('#live-player-div iframe').length;
        if(_currentView === 'live' && iframeCount === 0){
          console.error('[MOBILE LIVE] API non pronta, fallback iframe');
          forceMobileLiveIframeFallback(vid, offset);
        }
      }catch(e){}
    }, 2500);
    return;
  }

  if (_ytLivePlayer) { try{_ytLivePlayer.destroy();}catch(e){} _ytLivePlayer=null; }
  if (!ensureLivePlayerDiv()) return;
  const safeOffset = Math.floor(Math.max(0, offset||0));
  _ytLivePlayer = new YT.Player('live-player-div', {
    videoId: vid,
    playerVars:{ autoplay:1, mute:1, controls:0, rel:0, modestbranding:1, playsinline:1, enablejsapi:1, hl:'it', cc_lang_pref:'it', cc_load_policy:0, start:safeOffset },
    events:{
      onReady:(e)=>{
        try{
          console.log('[MOBILE LIVE] onReady', { vid, safeOffset });
          const cur = getLiveCurrent();
          if (DB.liveState && getLiveStatePhaseMobile(DB.liveState) === 'content') {
            e.target.seekTo(Math.floor(getLiveOffsetFromState(DB.liveState)), true);
            e.target.playVideo();
          }
          hardDisableCaptions(_ytLivePlayer);
          Promise.resolve(trySelectItalianAudio(_ytLivePlayer, cur||{})).then(sel=>{
            if(sel==='variant'){
              showToast?.('Versione audio separata non consentita in client read-only');
            }
            renderMobileLiveLanguageMenu();
          });
          refreshAudioLanguagesAfterReady(_ytLivePlayer,cur||{},'live');
          _mobileLiveCaptionsEnabled=false;
          setMobileLiveCCState();
          startLiveDriftGuard();
          startMobileLivePlaybackWatchdog(vid);
        }catch(err){
          console.error('[MOBILE LIVE] onReady error', err);
        }
      },
      onStateChange:(e)=>{
        if(e.data === YT.PlayerState.PLAYING){
          clearTimeout(_mobileLiveWatchdogTimer);
          clearLiveVideoFailureMobile(vid);
          _liveRetryState = { videoId: null, attempts: 0, skipRequested: false };
        }
      },
      onError:(e)=>{
        let reason = 'YT error';
        const code = Number(e?.data || 0);
        if(code === 2) reason = 'Video ID non valido';
        else if(code === 5) reason = 'HTML5 player error';
        else if(code === 100) reason = 'video unavailable';
        else if(code === 101 || code === 150) reason = 'Embed non consentito';
        registerLiveVideoFailureMobile(vid, reason);
      }
    }
  });
  setTimeout(() => {
    const iframeCount = document.querySelectorAll('#live-player-div iframe').length;
    if(!_ytLivePlayer || iframeCount === 0){
      console.error('[MOBILE LIVE] player non creato, fallback iframe');
      forceMobileLiveIframeFallback(vid, offset);
    }
  }, 2500);
  setBtnIcon('live-mute-btn','mute');
  const vs=document.getElementById('live-vol'); if(vs) vs.value=0;
}

function openLiveEmbed(current){
  if (!current || !DB.liveState) return;
  if (getLiveStatePhaseMobile(DB.liveState) !== 'content') return;
  ensureLivePlayer(current, DB.liveState);
}

function startLiveDriftGuard(){
  if (_liveDriftTimer) clearInterval(_liveDriftTimer);
  _liveDriftTimer = setInterval(()=>{
    if (_currentView !== 'live' || !_ytLivePlayer || !DB.liveState) return;
    if (getLiveStatePhaseMobile(DB.liveState) !== 'content') return;
    try {
      const expected = getLiveOffsetFromState(DB.liveState);
      const ct = _ytLivePlayer.getCurrentTime()||0;
      if (Math.abs(ct - expected) > 10) _ytLivePlayer.seekTo(expected, true);
      const state = _ytLivePlayer.getPlayerState();
      if (state === 0) _liveVideoEnded = true;
      if ((state === -1 || state === 3 || state === 5) && (ct || 0) < 1 && _liveVideoId) {
        registerLiveVideoFailureMobile(String(_liveVideoId || ''), 'playback bloccato');
      }
      if (state === 2 || state === 0) _ytLivePlayer.playVideo();
    }catch(e){}
  }, 3000);
}

function liveMute(){
  if (!_ytLivePlayer) return;
  try {
    const m  = document.getElementById('live-mute-btn');
    const vs = document.getElementById('live-vol');
    if (_ytLivePlayer.isMuted()) {
      _ytLivePlayer.unMute();
      _ytLivePlayer.setVolume(vs ? Number(vs.value)||50 : 50);
      setBtnIcon('live-mute-btn','volume');
    } else {
      _ytLivePlayer.mute();
      setBtnIcon('live-mute-btn','mute');
    }
  }catch(e){}
}
function liveVol(v){
  if (!_ytLivePlayer) return;
  try {
    _ytLivePlayer.setVolume(Number(v));
    const m = document.getElementById('live-mute-btn');
    if (Number(v) > 0) { _ytLivePlayer.unMute(); setBtnIcon('live-mute-btn','volume'); }
    else { setBtnIcon('live-mute-btn','mute'); }
  }catch(e){}
}
function liveFs(){
  const w = document.getElementById('live-player-outer');
  if (!w) return;

  const fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;

  if (fsEl || w.classList.contains('live-fullscreen-fallback')) {
    w.classList.remove('live-fullscreen-fallback');
    try {
      if (document.exitFullscreen) document.exitFullscreen();
      else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
      else if (document.mozCancelFullScreen) document.mozCancelFullScreen();
      else if (document.msExitFullscreen) document.msExitFullscreen();
    } catch(e) {}
    return;
  }

  try {
    if (w.requestFullscreen) w.requestFullscreen();
    else if (w.webkitRequestFullscreen) w.webkitRequestFullscreen();
    else if (w.mozRequestFullScreen) w.mozRequestFullScreen();
    else if (w.msRequestFullscreen) w.msRequestFullscreen();
    else w.classList.add('live-fullscreen-fallback');

    setTimeout(() => {
      if (!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement)) {
        w.classList.add('live-fullscreen-fallback');
      }
    }, 350);
  } catch(e) {
    w.classList.add('live-fullscreen-fallback');
  }
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// YT API CALLBACK
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function onYouTubeIframeAPIReady(){
  console.log('[MOBILE LIVE] onYouTubeIframeAPIReady');
  _ytApiReady = true;
  if (_pendingModalPlayer) {
    const {vid, start} = _pendingModalPlayer; _pendingModalPlayer = null;
    if (document.getElementById('player-modal').classList.contains('open')) createModalPlayer(vid, start);
  }
  if (_livePlayerQueue) {
    const q = _livePlayerQueue;
    _livePlayerQueue = null;
    if (_currentView === 'live') createLivePlayer(q.vid, q.offset);
  }
}



(function(){
  let fsTarget = null;
  let fsScrollY = 0;

  function ensureExitButton(){
    let btn = document.getElementById('tutv-exit-fs-btn');
    if(btn) return btn;

    btn = document.createElement('button');
    btn.id = 'tutv-exit-fs-btn';
    btn.className = 'tutv-exit-fs-btn';
    btn.setAttribute('aria-label','Riduci schermo');
    btn.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M8 3v5H3"/><path d="M16 3v5h5"/><path d="M8 21v-5H3"/><path d="M16 21v-5h5"/></svg>';
    btn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      exitTubeFullscreen();
    });
    document.body.appendChild(btn);
    return btn;
  }

  function findFullscreenTarget(elId){
    if(elId && document.getElementById(elId)) return document.getElementById(elId);

    const modal = document.getElementById('player-modal');
    if(modal && modal.classList.contains('open')){
      return document.querySelector('.pm-player-wrap') || modal;
    }

    const live = document.getElementById('live-player-outer') ||
                 document.querySelector('.live-player-wrap');
    if(live) return live;

    return document.querySelector('.player-shell') ||
           document.querySelector('.video-player-wrap') ||
           document.querySelector('.pm-player-wrap');
  }

  window.enterTubeFullscreen = async function(elId){
    const target = findFullscreenTarget(elId);
    if(!target) return;

    ensureExitButton();
    fsTarget = target;
    fsScrollY = window.scrollY || 0;

    target.classList.add('tutv-fs-target');
    document.body.classList.add('tutv-fs-active');
    document.body.style.top = '-' + fsScrollY + 'px';

    // Try native fullscreen, but app-mode remains active even if browser refuses.
    try{
      if(target.requestFullscreen) await target.requestFullscreen();
      else if(target.webkitRequestFullscreen) target.webkitRequestFullscreen();
    }catch(e){}

    // Try orientation only if supported; ignore failures.
    try{
      if(screen.orientation && screen.orientation.lock) {
        await screen.orientation.lock('landscape');
      }
    }catch(e){}

    setTimeout(function(){
      window.dispatchEvent(new Event('resize'));
    }, 100);
  };

  window.exitTubeFullscreen = async function(){
    try{
      if(document.fullscreenElement && document.exitFullscreen) await document.exitFullscreen();
      else if(document.webkitFullscreenElement && document.webkitExitFullscreen) document.webkitExitFullscreen();
    }catch(e){}

    if(fsTarget) fsTarget.classList.remove('tutv-fs-target');
    document.body.classList.remove('tutv-fs-active');
    document.body.style.top = '';
    window.scrollTo(0, fsScrollY || 0);

    try{
      if(screen.orientation && screen.orientation.unlock) screen.orientation.unlock();
    }catch(e){}

    fsTarget = null;
    setTimeout(function(){
      window.dispatchEvent(new Event('resize'));
    }, 100);
  };

  window.toggleTubeFullscreen = function(elId){
    if(document.body.classList.contains('tutv-fs-active')) {
      exitTubeFullscreen();
    } else {
      enterTubeFullscreen(elId);
    }
  };

  // Compatibility with previous names
  window.toggleCustomFullscreen = window.toggleTubeFullscreen;

  // Patch existing fullscreen functions if present
  window.pmFullscreen = function(){
    toggleTubeFullscreen('player-modal');
  };

  window.liveFs = function(){
    toggleTubeFullscreen('live-player-outer');
  };

  document.addEventListener('fullscreenchange', function(){
    if(!document.fullscreenElement && document.body.classList.contains('tutv-fs-active')){
      // Browser exited native fullscreen with back/gesture; keep app mode unless user used our button?
      // On Android this event fires after native exit; we also close app mode to avoid being stuck.
      exitTubeFullscreen();
    }
  });

  document.addEventListener('webkitfullscreenchange', function(){
    if(!document.webkitFullscreenElement && document.body.classList.contains('tutv-fs-active')){
      exitTubeFullscreen();
    }
  });

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && document.body.classList.contains('tutv-fs-active')){
      exitTubeFullscreen();
    }
  });

  document.addEventListener('DOMContentLoaded', ensureExitButton);
})();


(function(){
  function svgPlay(){
    return '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
  }
  function svgPause(){
    return '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>';
  }
  function svgPrev(){
    return '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M6 5h2v14H6zM9 12l9 7V5z"/></svg>';
  }
  function svgNext(){
    return '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M16 5h2v14h-2zM6 19l9-7-9-7z"/></svg>';
  }
  function svgVolume(){
    return '<svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/></svg>';
  }
  function svgMute(){
    return '<svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="m17 9 5 5"/><path d="m22 9-5 5"/></svg>';
  }
  function svgFullscreen(){
    return '<svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M4 9V4h5"/><path d="M20 9V4h-5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/></svg>';
  }

  window.tutvSvg = {svgPlay, svgPause, svgPrev, svgNext, svgVolume, svgMute, svgFullscreen};

  function setIcon(id, html){
    const el = document.getElementById(id);
    if(el) el.innerHTML = html;
  }

  function applyPlayerIcons(){
    setIcon('pm-prev-btn', svgPrev());
    setIcon('pm-next-btn', svgNext());
    setIcon('pm-play-btn', svgPlay());
    setIcon('pm-mute-btn', svgVolume());

    const fsButtons = Array.from(document.querySelectorAll('[onclick*="pmFullscreen"],[onclick*="liveFs"],[onclick*="toggleTubeFullscreen"],[onclick*="toggleCustomFullscreen"]'));
    fsButtons.forEach(btn => {
      if(btn && btn.tagName === 'BUTTON') btn.innerHTML = svgFullscreen();
    });

    const liveMute = document.getElementById('live-mute-btn');
    if(liveMute) liveMute.innerHTML = svgMute();
  }

  function ensureTitleBar(){
    const wrap = document.querySelector('.pm-player-wrap');
    if(wrap && !wrap.querySelector('.tutv-fs-titlebar')){
      const bar = document.createElement('div');
      bar.className = 'tutv-fs-titlebar';
      bar.innerHTML = '<div class="tutv-fs-title" id="tutv-fs-title"></div><div class="tutv-fs-subtitle" id="tutv-fs-subtitle"></div>';
      wrap.appendChild(bar);
    }

    const live = document.getElementById('live-player-outer') || document.querySelector('.live-player-wrap');
    if(live && !live.querySelector('.tutv-live-fs-titlebar')){
      const bar = document.createElement('div');
      bar.className = 'tutv-live-fs-titlebar';
      bar.innerHTML = '<div class="tutv-live-badge">LIVE</div><div class="tutv-live-title" id="tutv-live-fs-title"></div><div class="tutv-live-sub" id="tutv-live-fs-sub"></div>';
      live.appendChild(bar);
    }
  }

  function syncFullscreenTitles(){
    const title = document.getElementById('pm-title')?.textContent ||
                  document.getElementById('pm-ctrl-title')?.textContent || '';
    const desc = document.getElementById('pm-desc')?.textContent || '';
    const t = document.getElementById('tutv-fs-title');
    const s = document.getElementById('tutv-fs-subtitle');
    if(t) t.textContent = title;
    if(s) s.textContent = desc;

    const lt = document.getElementById('tutv-live-fs-title');
    const ls = document.getElementById('tutv-live-fs-sub');
    if(lt) lt.textContent = document.getElementById('live-title')?.textContent || 'In onda ora';
    if(ls) ls.textContent = document.getElementById('live-meta')?.textContent || '';
  }

  const oldPmOnStateChange = window.pmOnStateChange;
  window.pmOnStateChange = function(state){
    if(typeof oldPmOnStateChange === 'function') oldPmOnStateChange(state);
    setIcon('pm-play-btn', state === 1 ? svgPause() : svgPlay());
  };

  const oldPmToggleMute = window.pmToggleMute;
  window.pmToggleMute = function(){
    if(typeof oldPmToggleMute === 'function') oldPmToggleMute();
    const btn = document.getElementById('pm-mute-btn');
    const isMuted = btn && /mute|ðŸ”‡/i.test(btn.textContent || '');
    // Prefer player state when available
    try{
      if(window._ytModalPlayer && window._ytModalPlayer.isMuted){
        setIcon('pm-mute-btn', window._ytModalPlayer.isMuted() ? svgMute() : svgVolume());
        return;
      }
    }catch(e){}
    setIcon('pm-mute-btn', isMuted ? svgMute() : svgVolume());
  };

  const oldPmSetVolume = window.pmSetVolume;
  window.pmSetVolume = function(v){
    if(typeof oldPmSetVolume === 'function') oldPmSetVolume(v);
    setIcon('pm-mute-btn', Number(v) === 0 ? svgMute() : svgVolume());
  };

  const oldLiveMute = window.liveMute;
  window.liveMute = function(){
    if(typeof oldLiveMute === 'function') oldLiveMute();
    try{
      if(window._ytLivePlayer && window._ytLivePlayer.isMuted){
        setIcon('live-mute-btn', window._ytLivePlayer.isMuted() ? svgMute() : svgVolume());
      }
    }catch(e){}
  };

  const oldOpenPlayer = window.openPlayer;
  window.openPlayer = function(){
    const result = (typeof oldOpenPlayer === 'function') ? oldOpenPlayer.apply(this, arguments) : undefined;
    setTimeout(function(){
      ensureTitleBar();
      applyPlayerIcons();
      syncFullscreenTitles();
    }, 80);
    return result;
  };

  document.addEventListener('fullscreenchange', syncFullscreenTitles);
  document.addEventListener('webkitfullscreenchange', syncFullscreenTitles);
  document.addEventListener('DOMContentLoaded', function(){
    ensureTitleBar();
    applyPlayerIcons();
    syncFullscreenTitles();
  });

  setInterval(function(){
    if(document.body.classList.contains('tutv-fs-active')){
      syncFullscreenTitles();
    }
  }, 1000);
})();


function loadAdvertisingScripts(){
  if(document.querySelector('script[data-adsense-loaded="1"]'))return;
  const clientId = String(DB?.adSettings?.adsenseClientId || '').trim();
  if(!/^ca-pub-[0-9]+$/i.test(clientId)){
    // Placeholder only: load script only when admin configured a valid AdSense client ID.
    return;
  }
  const s=document.createElement('script');
  s.async=true;
  s.src='https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client='+encodeURIComponent(clientId);
  s.crossOrigin='anonymous';
  s.dataset.adsenseLoaded='1';
  document.head.appendChild(s);
}
function setCookieConsent(value){
  localStorage.setItem('tutv_cookie_consent',value);
  document.getElementById('cookie-banner')?.classList.remove('open');
  if(value==='accepted')loadAdvertisingScripts();
}
function initCookieConsent(){
  const consent=localStorage.getItem('tutv_cookie_consent');
  if(consent==='accepted')loadAdvertisingScripts();
  if(!consent)document.getElementById('cookie-banner')?.classList.add('open');
}
function applyInitialHashView(){
  const v=(location.hash||'').replace('#','');
  if(v&&document.getElementById('view-'+v)&&typeof switchView==='function')switchView(v);
}
document.addEventListener('DOMContentLoaded',()=>{initCookieConsent();setTimeout(applyInitialHashView,0);});


