const fs = require('fs');

function check(condition, message) {
  if (!condition) throw new Error(message);
}

const tv = fs.readFileSync('tv.html', 'utf8');
const lite = fs.readFileSync('tv-lite.html', 'utf8');
const tvManifest = JSON.parse(fs.readFileSync('tv-manifest.webmanifest', 'utf8'));
const tvPwa = fs.readFileSync('tv-pwa.js', 'utf8');
const tvServiceWorker = fs.readFileSync('tv-sw.js', 'utf8');
const index = fs.readFileSync('index.html', 'utf8');
const mobile = fs.readFileSync('mobile.html', 'utf8');
const devicesApi = fs.readFileSync('api/tv-devices.php', 'utf8');
const commandApi = fs.readFileSync('api/tv-command.php', 'utf8');
const remoteCatalogApi = fs.readFileSync('api/tv-remote-catalog.php', 'utf8');
const streamApi = fs.readFileSync('api/iptv-stream.php', 'utf8');
const iptvLib = fs.readFileSync('api/iptv-lib.php', 'utf8');
const liveStateApi = fs.readFileSync('api/live-state.php', 'utf8');
const transcodeApi = fs.readFileSync('api/iptv-transcode.php', 'utf8');
const account = fs.readFileSync('account.js', 'utf8');

check(tv.includes('width:100vw;height:100vh;height:100dvh'), 'TV shell is not viewport sized');
check(tv.includes("tv-lite.html?tvdevice=1&mode=") && !tv.includes("index.html?desktop=1&tvdevice=1&view=live"), 'TV does not use the unified lightweight experience');
check(tv.includes('function markFrameReady(){if(frameReady)return;frameReady=true') && tv.includes('function frameLoaded(){markFrameReady()}') && tv.includes("data.state==='tv-ready'"), 'remote commands are not gated on iframe readiness');
check(!tv.includes("markFrameReady();deliverCommand('LIVE')") && !tv.includes("add('frame-loaded');deliverCommand('LIVE')"), 'TV shell still jumps automatically from Home to Live');
check(tv.includes("location.replace(base+'tv-lite.html?tvdevice=1&mode=performance&standalone=1") && lite.includes('function startStandaloneRemote()') && lite.includes("api/tv-command.php"), 'TV does not launch the optimized top-level renderer with standalone remote polling');
check(tv.includes('.app-shell iframe{position:absolute') && tv.includes('background:#05070b;opacity:1}') && !tv.includes('.app-shell.frame-loaded iframe{opacity:1}'), 'TV iframe still uses opacity compositing that can black out hardware video');
check(tv.includes('pendingCommands.push({command:command,payload:payload||{}})'), 'remote pending queue is missing');
check(tv.includes("storeSet(storage.seq,String(lastSeq))"), 'remote command sequence is not persisted');
check(tv.includes('id="repair-remote"'), 'TV re-pair button is missing');
check(tv.includes('function restartPairing()'), 'TV re-pair workflow is missing');
check(tv.includes('storeRemove(storage.device);storeRemove(storage.token);storeRemove(storage.seq)'), 'TV re-pair does not clear the stale local link');
check(tv.includes("function loadTvExperience(mode)") && tv.includes("startLiteMode(){loadTvExperience('performance')}"), 'TV performance reload is missing');
check(!tv.includes('id="lite-toggle"'), 'TV Lite control must not be visible on the television');
check(account.includes('data-command="TV_LITE"'), 'TV Lite control is missing from the phone remote');
check(account.includes('if(auth)auth.hidden=true') && !account.includes('if(!token){prepareRemotePanel();return;}'), 'phone remote still requires the redundant 4 digit pairing');
check(devicesApi.includes('No second 4-digit pairing is required') && !remoteCatalogApi.includes('REMOTE_PAIR_REQUIRED'), 'account-linked TV still requires a second remote token');
check(!account.includes("sendRemoteCommand('OPEN_IPTV'"), 'private IPTV launcher is still exposed');
check(!account.includes('data-remote-section="categories"') && !account.includes('data-remote-section="guide"') && !account.includes('data-remote-section="schedule"'), 'private IPTV remote sections are still exposed');
check(devicesApi.includes("'TV_LITE'"), 'TV Lite remote command is not accepted by the API');
check(tv.includes("setTimeout(function(){button.classList.add('hidden-control')},10000)"), 're-pair control does not hide after 10 seconds');
check(lite.includes('api/tv-live.php'), 'TV Lite does not use the compact live endpoint');
check(lite.includes('api/tv-lite-data.php'), 'TV Lite catalogue endpoint is missing');
check(!lite.includes("label:'Catalogo TV'"), 'TV Lite still exposes the private IPTV catalogue');
check(!devicesApi.includes("'OPEN_IPTV'"), 'the API still accepts private IPTV launch commands');
check(lite.includes('liveAudioUnlocked') && lite.includes('Il box ha bloccato il player. Premi OK') && lite.includes('function unlockTvPlayback()') && lite.includes('navigator.userActivation'), 'Android TV YouTube playback does not recover through a real OK/user gesture with audio');
check(lite.includes('function scheduleLiveBoundary()') && lite.includes('function refreshLiveData(advancePlayer)') && lite.includes("litePending.kind==='live'") && lite.includes('liveServerOffset)+80'), 'TV Lite does not advance the web schedule at the exact programme boundary');
check(lite.includes('class="brand-name"') && lite.includes('id="clock"') && tv.includes('class="tv-boot"'), 'unified TV visual shell is missing');
check(tv.includes('data-tv-install') && lite.includes('data-tv-install') && tv.includes('tv-manifest.webmanifest') && lite.includes('tv-pwa.js'), 'Android TV PWA install controls are missing');
check((tvManifest.start_url.includes('/tv') || tvManifest.start_url.includes('tv.html')) && tvManifest.display === 'fullscreen' && tvManifest.orientation === 'landscape', 'Android TV manifest is not TV-first');
check(tvPwa.includes('beforeinstallprompt') && tvPwa.includes("serviceWorker.register('./tv-sw.js?v=3'") && tvPwa.includes('Installa app') && tvServiceWorker.includes("'/api/'") && tvServiceWorker.includes('fetch(request)'), 'Android TV PWA install or network-first shell is incomplete');
check(!lite.includes('assets/vendor/hls.light.min.js') && !lite.includes('assets/vendor/mpegts.js'), 'TV Lite still loads private IPTV playback engines');
check(lite.includes('youtube-nocookie.com/embed/') && lite.includes('Apri nel player YouTube TV') && lite.includes('com.google.android.youtube.tv'), 'Android TV has no direct/native YouTube fallback');
check(!index.includes('setTimeout(openIptvCatalog,120)') && !mobile.includes('renderIptvEntryCard();'), 'a private IPTV catalogue entry is still reachable');
check(mobile.includes('function syncMobileLandscapeCinema()') && mobile.includes('mobile-landscape-cinema') && mobile.includes('mobile-landscape-target'), 'automatic mobile landscape cinema layout is missing');
check(mobile.includes("window.addEventListener('orientationchange'") && mobile.includes("document.getElementById('player-modal')") && mobile.includes("document.getElementById('live-player-outer')"), 'landscape layout does not restore or cover all mobile players');
check(!/syncMobileLandscapeCinema[\s\S]{0,900}requestFullscreen\s*\(/.test(mobile), 'landscape cinema incorrectly invokes native fullscreen');
check(mobile.includes('function recoverMobileIptvPlayback(reason)') && mobile.includes('Date.now()-_mobileIptvLastProgress>14000') && mobile.includes('Riconnessione automatica'), 'mobile IPTV stall recovery watchdog is missing');
check(mobile.includes('_mobileLiveDriftSamples < 3') && mobile.includes('_mobileLiveLastDriftSeekAt < 30000') && mobile.includes('playerState !== YT.PlayerState.PLAYING || drift <= 20'), 'mobile Live Web still seeks repeatedly during ordinary buffering');
check(mobile.includes("const expired = phase !== 'content' && isMobileLivePhaseExpired") && mobile.includes('&& !phaseExpired && !adFree'), 'mobile Live Web can remain paused on an expired or ad-free phase');
check(index.includes('_liveDriftMismatchSamples >= 3') && index.includes('_liveLastDriftSeekAt >= 30000') && index.includes('if (!liveState?.adState?.active) liveSyncDriftGuard()'), 'desktop/TV Live Web drift correction is still aggressive');
check(index.includes("navigator.platform==='MacIntel'") && index.includes('id="live-start-gate"') && index.includes('function startLiveFromUserGesture()') && index.includes("||_isAppleTouchDevice"), 'iPadOS desktop-mode Live Web autoplay recovery is missing');
check(mobile.includes('id="mobile-live-start-gate"') && mobile.includes('function startMobileLiveFromGesture()') && mobile.includes('_isAppleTouchDeviceMobile'), 'iPadOS mobile Live Web autoplay recovery is missing');
check(mobile.includes("div.replaceChildren()") && !mobile.includes("outer.innerHTML = '<div id=\"live-player-div\"></div>'"), 'mobile live reset still destroys the iPad start control and phase overlays');
check(index.includes("api/live-state.php?channel=") && mobile.includes("api/live-state.php?channel="), 'Live Web clients still read stale cron state directly');
check(index.includes('function scheduleExactLiveBoundary(state)') && mobile.includes('function scheduleMobileLiveBoundary(state)') && index.includes('endAt - getLiveNowMs() + 80') && mobile.includes('_mobileLiveServerClockOffsetMs) + 80'), 'Live Web does not switch at the exact server-scheduled second');
check(liveStateApi.includes("'projectedFromSchedule' => true") && liveStateApi.includes("$now >= $entry['start'] && $now < $entry['end']") && liveStateApi.includes("array_slice($data['publicLiveSchedule']['liveQueue']"), 'deterministic live-state projection or fallback is incomplete');
check(iptvLib.includes('Sliding lifetime for an actively watched channel') && iptvLib.includes("$session['expiresAt'] = time() + 21600"), 'active IPTV sessions still expire during viewing');
check(index.includes('verifiedTrack.audioTrackId||verifiedTrack.id') && mobile.includes('verifiedTrack.audioTrackId||verifiedTrack.id'), 'verified Italian YouTube audio track is not selected by web players');
check(lite.includes('id="categories"') && lite.includes('id="grid"'), 'TV Lite category browser is missing');
check(lite.includes("new YT.Player('yt-lite'"), 'TV Lite controllable YouTube player is missing');
check(lite.includes("c==='VOLUME_UP'") && lite.includes("c==='LEFT'"), 'TV Lite remote navigation or volume handling is missing');
check(index.includes('body.tv-device-mode #view-live .live-sched-col{display:none!important}'), 'TV live player still reserves sidebar space');
check(index.includes("document.body.classList.add('tv-device-mode')"), 'TV viewport mode is not enabled');
check(devicesApi.indexOf('tv_devices_lock()') < devicesApi.indexOf('tv_read_file(tv_devices_path())'), 'mobile command endpoint reads before locking');
check(commandApi.indexOf('tv_devices_lock()') < commandApi.indexOf('tv_read_file(tv_devices_path())'), 'TV poll endpoint reads before locking');
check(commandApi.includes('if ($after > $currentSeq) $after = 0;') && lite.includes('standaloneLastSeq>Number(data.currentSeq)'), 'standalone TV command sequence cannot recover after server reset');
check(lite.includes('standaloneCodeTimer=setTimeout(hideStandaloneCode,delay)') && lite.includes('expires<=Date.now()'), 'standalone remote code overlay expiry is not managed safely');

const inlineScripts = [...tv.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(match => match[1]).filter(Boolean);
inlineScripts.forEach((source, index) => {
  try { new Function(source); }
  catch (error) { throw new Error(`tv.html inline script ${index + 1} has invalid syntax: ${error.message}`); }
});
const liteScripts = [...lite.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(match => match[1]).filter(Boolean);
liteScripts.forEach((source, index) => {
  try { new Function(source); }
  catch (error) { throw new Error(`tv-lite.html inline script ${index + 1} has invalid syntax: ${error.message}`); }
});
const mobileScripts = [...mobile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(match => match[1]).filter(Boolean);
mobileScripts.forEach((source, index) => {
  try { new Function(source); }
  catch (error) { throw new Error(`mobile.html inline script ${index + 1} has invalid syntax: ${error.message}`); }
});
const indexScripts = [...index.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(match => match[1]).filter(Boolean);
indexScripts.forEach((source, scriptIndex) => {
  try { new Function(source); }
  catch (error) { throw new Error(`index.html inline script ${scriptIndex + 1} has invalid syntax: ${error.message}`); }
});

const tvModeMarker = index.indexOf("if (!new URLSearchParams(location.search).has('tvdevice')) return;");
const tvModeStart = index.lastIndexOf('(() => {', tvModeMarker);
const tvModeEnd = index.indexOf('</script>', tvModeStart);
check(tvModeStart >= 0 && tvModeEnd > tvModeStart, 'cannot extract TV remote receiver');
new Function(index.slice(tvModeStart, tvModeEnd));

console.log('tv-experience-tests PASS');
