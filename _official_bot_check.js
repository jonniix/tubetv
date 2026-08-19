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
checkInlineScripts('admin.html');
checkInlineScripts('index.html');
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
console.log('official-bot-check PASS');
