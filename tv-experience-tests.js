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
check(account.includes("sendRemoteCommand('OPEN_IPTV'"), 'smart remote channel launcher is missing');
check(account.includes('data-remote-section="categories"') && account.includes('data-remote-section="guide"') && account.includes('data-remote-section="schedule"'), 'advanced remote sections are missing');
check(account.includes('function renderRemoteCategories()') && account.includes('function renderRemoteGuideBrowser()') && account.includes('function renderRemoteSchedule(channel)'), 'advanced remote category or EPG rendering is missing');
check(account.includes('Torna alle categorie') && account.includes("grid.hidden=true") && account.includes("state.remoteSelectedGroup=''"), 'remote category drill-down does not open a dedicated channel page');
check(account.includes("showRemoteSection('guide')") && account.includes('Guida TV · ${escapeHtml(group)}') && account.includes('channels.slice(0,12)'), 'remote categories do not open their filtered TV guide');
check(devicesApi.includes("'TV_LITE'"), 'TV Lite remote command is not accepted by the API');
check(tv.includes("setTimeout(function(){button.classList.add('hidden-control')},10000)"), 're-pair control does not hide after 10 seconds');
check(lite.includes('api/tv-live.php'), 'TV Lite does not use the compact live endpoint');
check(lite.includes('api/tv-lite-data.php'), 'TV Lite catalogue endpoint is missing');
check(lite.includes('api/tv-lite-iptv.php') && lite.includes("c==='OPEN_IPTV'"), 'TV Lite IPTV catalogue or remote launcher is missing');
check(lite.includes("localStorage.getItem('tubetv_tv_device_token')") && lite.includes("label:'Catalogo TV'"), 'TV Lite does not authenticate or always expose its IPTV catalogue');
check(lite.includes("document.body.classList.add('player-active')") && lite.includes('z-index:2147483647'), 'TV Lite IPTV player is not forced to full screen');
check(!lite.includes('requestFullscreen') && lite.includes('function startTvVideo(video,manual)') && lite.includes('id="player-retry"'), 'TV playback recovery or CSS-only fullscreen is missing');
check(lite.includes('Hls.ErrorTypes.MEDIA_ERROR') && lite.includes('recoverMediaError()') && lite.includes('noPicture()'), 'HLS recovery is missing');
check(lite.includes('.player.controls-hidden') && lite.includes('},7000)'), 'player controls do not hide after seven seconds');
check(lite.includes('lowLatencyMode:!isVod') && lite.includes('maxBufferLength:isVod?90:35'), 'separate VOD/live buffering is missing');
check(lite.includes('Avvio player TV leggero') && lite.includes("video.src=source;video.addEventListener('loadedmetadata'") && lite.includes('function startFallback()'), 'TV player does not try the lightweight native path before compatibility engines');
check(lite.includes('liveAudioUnlocked') && lite.includes('Il box ha bloccato il player. Premi OK') && lite.includes('function unlockTvPlayback()') && lite.includes('navigator.userActivation'), 'Android TV YouTube playback does not recover through a real OK/user gesture with audio');
check(lite.includes('function fallbackUnknownLive(video,source,isVod)') && lite.includes("mpegts.createPlayer({type:'mpegts',isLive:!isVod"), 'HLS to MPEG-TS live fallback is missing');
check(lite.includes('function scheduleLiveBoundary()') && lite.includes('function refreshLiveData(advancePlayer)') && lite.includes("litePending.kind==='live'") && lite.includes('liveServerOffset)+80'), 'TV Lite does not advance the web schedule at the exact programme boundary');
check(streamApi.includes('$probeBody') && streamApi.includes("str_starts_with(ltrim($probeBody), '#EXTM3U')"), 'extensionless IPTV stream probing is missing');
check(streamApi.includes("header('X-Accel-Buffering: no')") && streamApi.includes('CURLOPT_TCP_NODELAY'), 'IPTV proxy streaming optimizations are missing');
check(streamApi.includes('$isContinuousLive') && streamApi.includes("header_remove('Content-Length')") && streamApi.includes('usleep(250000)') && streamApi.includes("if (!$isContinuousLive && !empty($_SERVER['HTTP_RANGE']))"), 'finite upstream MPEG-TS responses are not reconnected as one continuous live stream');
check(iptvLib.includes("'group' => (string)$item['group']") && iptvLib.includes("'format' => (string)$item['format']"), 'IPTV session does not retain the metadata required to distinguish live channels from VOD');
check(iptvLib.includes('mp4|m4v|webm|mp3|aac') && iptvLib.includes("return 'transcode'") && iptvLib.includes('mkv|avi|flv'), 'incompatible VOD containers are not routed to conversion');
check(transcodeApi.includes('proc_open') && transcodeApi.includes('libx264') && transcodeApi.includes('frag_keyframe+empty_moov'), 'browser-compatible H.264 film conversion is incomplete');
check(index.includes('api/iptv-transcode.php') && mobile.includes('api/iptv-transcode.php') && lite.includes('api/iptv-transcode.php'), 'film conversion fallback is not available on desktop, mobile and TV');
check(index.includes('function iptvCategoryValue(channel)') && mobile.includes('function mobileIptvCategoryValue(channel)') && index.includes('return iptvCategoryValue(channel)===rule.value'), 'IPTV macro categories are not exclusive or group-first');
check(lite.includes('class="brand-name"') && lite.includes('id="clock"') && tv.includes('class="tv-boot"'), 'unified TV visual shell is missing');
check(tv.includes('data-tv-install') && lite.includes('data-tv-install') && tv.includes('tv-manifest.webmanifest') && lite.includes('tv-pwa.js'), 'Android TV PWA install controls are missing');
check(tvManifest.start_url.includes('tv.html') && tvManifest.display === 'fullscreen' && tvManifest.orientation === 'landscape', 'Android TV manifest is not TV-first');
check(tvPwa.includes('beforeinstallprompt') && tvPwa.includes("serviceWorker.register('./tv-sw.js?v=2'") && tvPwa.includes('Installa app') && tvServiceWorker.includes("'/api/'") && tvServiceWorker.includes('fetch(request)'), 'Android TV PWA install or network-first shell is incomplete');
check(lite.includes('assets/vendor/hls.light.min.js') && lite.includes('assets/vendor/mpegts.js') && fs.existsSync('assets/vendor/hls.light.min.js') && fs.existsSync('assets/vendor/mpegts.js'), 'TV playback engines still depend on a third-party CDN');
check(lite.includes('youtube-nocookie.com/embed/') && lite.includes('Apri nel player YouTube TV') && lite.includes('com.google.android.youtube.tv'), 'Android TV has no direct/native YouTube fallback');
check(index.includes('async function openTvDeviceIptvCatalog()') && index.includes("setTimeout(openIptvCatalog,120)"), 'normal TV mode cannot open the approved IPTV catalogue');
check(index.includes('.iptv-channel-panel{order:2;max-height:none') && index.includes('.iptv-main-stage{order:3;'), 'mobile IPTV channels still render below the player');
check(index.includes('function backIptvCategories()') && index.includes('Scegli la sottocategoria'), 'IPTV category drill-down navigation is missing');
check(index.includes('function setIptvMobilePage(page)') && index.includes("setIptvMobilePage('channels')") && index.includes("setIptvMobilePage('player')"), 'mobile IPTV does not use separate category, channel and player pages');
check(index.includes('function backIptvMobileCategories()') && index.includes('onclick="backIptvMobileCategories()"'), 'mobile IPTV channel page has no category back navigation');
check(index.includes('function toggleIptvMobileMenu()') && index.includes('class="iptv-mobile-menu"') && index.includes('mobile-menu-open>.iptv-category-panel'), 'mobile IPTV categories are not contained in a hamburger drawer');
check(index.includes("if(empty){empty.style.display='grid';empty.innerHTML='<div><strong>Avvio player leggero") && index.includes("if(fallback==='hls'&&startMpeg())"), 'desktop IPTV player does not verify video frames or switch engines');
check(mobile.includes('function toggleMobileIptvMenu()') && mobile.includes('class="iptv-mobile-drawer-backdrop"') && mobile.includes('mobile-menu-open .iptv-category-panel'), 'normal mobile IPTV categories are not contained in a hamburger drawer');
check(mobile.includes('function backMobileIptvCategories()') && mobile.includes('Scegli una sottocategoria') && mobile.includes('closeMobileIptvMenu();document.querySelector'), 'normal mobile IPTV macro and subcategory flow is incomplete');
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
check(index.includes("api/live-state.php?ts=") && mobile.includes("api/live-state.php?live="), 'Live Web clients still read stale cron state directly');
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

const tvModeStart = index.indexOf("(() => {\n  if (!new URLSearchParams(location.search).has('tvdevice')) return;");
const tvModeEnd = index.indexOf('</script>', tvModeStart);
check(tvModeStart >= 0 && tvModeEnd > tvModeStart, 'cannot extract TV remote receiver');
new Function(index.slice(tvModeStart, tvModeEnd));

console.log('tv-experience-tests PASS');
