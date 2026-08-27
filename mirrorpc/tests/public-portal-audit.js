'use strict';

const WebSocket = require('ws');
const port = Number(process.env.CDP_PORT || 9224);

async function run() {
  const pages = await fetch(`http://127.0.0.1:${port}/json`).then(response => response.json());
  const page = pages.find(item => item.type === 'page'); if (!page) throw new Error('Pagina Chrome non trovata');
  const socket = new WebSocket(page.webSocketDebuggerUrl); let id = 0; const pending = new Map();
  socket.on('message', raw => { const message = JSON.parse(raw); if (pending.has(message.id)) { pending.get(message.id)(message); pending.delete(message.id); } });
  await new Promise(resolve => socket.once('open', resolve));
  const call = (method, params = {}) => new Promise(resolve => { const requestId = ++id; pending.set(requestId, resolve); socket.send(JSON.stringify({ id: requestId, method, params })); });
  await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await call('Page.navigate', { url: 'http://127.0.0.1:4177/index.html' }); await new Promise(resolve => setTimeout(resolve, 900));
  const audit = await call('Runtime.evaluate', { expression: `JSON.stringify((()=>{const b=document.getElementById('openJoin').getBoundingClientRect(),text=document.body.innerText;return{width:innerWidth,scroll:document.documentElement.scrollWidth,button:b,private:/PC fisso|Polaroid|192\\.168\\.1\\./.test(text),download:document.querySelectorAll('[href="Installa-MirrorPC.bat"]').length}})())`, returnByValue: true });
  const result = JSON.parse(audit.result.result.value); if (result.private) throw new Error('Dati privati presenti'); if (result.scroll > result.width + 1) throw new Error('Overflow mobile'); if (result.button.right > result.width || result.button.left < 0) throw new Error('Pulsante collega fuori schermo'); if (!result.download) throw new Error('Download app assente');
  await call('Runtime.evaluate', { expression: `document.getElementById('openJoin').click()` }); const dialog = await call('Runtime.evaluate', { expression: `!document.getElementById('joinDialog').classList.contains('hidden')`, returnByValue: true }); if (!dialog.result.result.value) throw new Error('Dialog codice non aperto');
  socket.close(); console.log('Portale pubblico: privacy, mobile, download e codice OK');
}
run().catch(error => { console.error(error.message); process.exitCode = 1; });
