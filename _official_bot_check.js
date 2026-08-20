const fs = require('fs');

function check(condition, message) {
  if (!condition) throw new Error(message);
}

function checkInlineScripts(file) {
  const html = fs.readFileSync(file, 'utf8');
  const scripts = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)];
  for (const [index, match] of scripts.entries()) {
    try { new Function(match[1]); }
    catch (error) { throw new Error(`${file} script ${index + 1}: ${error.message}`); }
  }
}

const admin = fs.readFileSync('admin.html', 'utf8');
const index = fs.readFileSync('index.html', 'utf8');
const saveData = fs.readFileSync('api/save-data.php', 'utf8');
const iptvLib = fs.readFileSync('api/iptv-lib.php', 'utf8');
const botV3 = fs.readFileSync('api/bot-v3.php', 'utf8');
const botV3Engine = fs.readFileSync('api/bot-v3-engine.php', 'utf8');
const multiLive = fs.readFileSync('api/multilive-engine.php', 'utf8');
const htaccess = fs.readFileSync('.htaccess', 'utf8');
checkInlineScripts('admin.html');
checkInlineScripts('index.html');
checkInlineScripts('mobile.html');
check(!fs.existsSync('bot.html'), 'bot.html legacy ancora presente');
check(!fs.existsSync('prova.html'), 'prova.html legacy ancora presente');
check(!fs.existsSync('api/bot-tick.php'), 'bot-tick.php legacy ancora presente');
check(!fs.existsSync('api/grok-scheduler.php'), 'grok-scheduler.php legacy ancora presente');
check(!admin.includes("api/bot-tick.php"), 'Admin usa ancora bot-tick legacy');
check(!index.includes("api/bot-tick.php"), 'Live Web usa ancora bot-tick legacy');
check(admin.includes('Bot Palinsesto ufficiale'), 'Pannello del bot ufficiale assente');
check(admin.includes('bot-v3-availability-status'), 'Diagnostica video eliminati assente');
check(admin.includes('bot-v3-future') && admin.includes('renderBotV3Future'), 'Previsione dinamica 72 ore assente');
check(admin.includes('BLOCCATO') && admin.includes('prossimi due sono impegni bloccati'), 'Admin non distingue la terna Live bloccata dalla previsione dinamica');
check(admin.includes('TITOLO EN - AUDIO IT GARANTITO') && admin.includes('TRACCIA IT NON GARANTITA - ESCLUSO') && admin.includes('SUBENTRATO') && admin.includes('TITOLO IT UFFICIALE'), 'Badge audio, sostituzioni o titoli localizzati assenti');
check(index.includes('hiddenByAvailabilityBot'), 'Catalogo pubblico non filtra i video eliminati');
check(index.includes('enforceLiveItalianAudio') && index.includes('setYoutubeAudioTrackSafe'), 'Live Web non forza la traccia italiana verificata');
check(index.includes("api/bot-v3.php?action=tick"), 'Recovery Live non collegato al bot ufficiale');
check(saveData.includes("fail_json('ADMIN_TOKEN_NOT_CONFIGURED', 503)"), 'save-data non nega le scritture quando manca il token Admin');
check(iptvLib.includes("'ADMIN_TOKEN_NOT_CONFIGURED'], 503"), 'IPTV Admin non nega l’accesso quando manca il token');
check(botV3.includes("if ($token === '') return false;"), 'Bot V3 accetta ancora mutazioni senza token');
check(admin.includes('configureAdminToken()') && admin.includes('admin-security-token-btn'), 'Admin non offre la configurazione locale del token');
check(admin.includes('ch-web-live-list') && admin.includes('webLiveIds'), 'Assegnazione sorgenti ai canali Live Web secondari assente');
check(botV3Engine.includes('ml_tick_all($data, $now)') && multiLive.includes("'maxAgeDays' => 30") && multiLive.includes("'newReleaseWindowHours' => 72"), 'Motore multi-canale non collegato al bot ufficiale o regole mancanti');
check(index.includes("selectWebLiveChannel('crime')") && index.includes("selectWebLiveChannel('docu')"), 'Selettori Live Web desktop incompleti');
check(fs.readFileSync('mobile.html','utf8').includes('selectMobileWebLiveChannel') && fs.readFileSync('mobile.html','utf8').includes('mobile-live-tabs'), 'Selettori Live Web mobile incompleti');
check(index.includes('Live Web 1') && index.includes('Live Docu &amp; Lifestyle') && !index.includes('>Principale</button>'), 'Nomi pubblici dei canali Live non aggiornati');
check(index.includes('id="live-channel-bug"') && fs.readFileSync('mobile.html','utf8').includes('id="mobile-live-channel-bug"'), 'Logo dinamico del canale assente dal player');
for (const logo of ['live-web-1.svg','live-web-2.svg','live-kids.svg','live-crime.svg','live-docu-lifestyle.svg']) {
  check(fs.existsSync(`icons/${logo}`), `Logo canale mancante: ${logo}`);
}
check(htaccess.includes('node_modules') && htaccess.includes('live-presence\\.json') && htaccess.includes('index\\.html\\.bak'), 'Regole web per residui e runtime sensibili incomplete');
console.log('official-bot-check PASS');
