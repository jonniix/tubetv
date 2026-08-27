'use strict';

const WebSocket = require('ws');
const port = Number(process.env.CDP_PORT || 9224);

async function run() {
  const pages = await fetch(`http://127.0.0.1:${port}/json`).then(response => response.json());
  const page = pages.find(item => item.type === 'page' && /127\.0\.0\.1:4177/.test(item.url));
  if (!page) throw new Error('Pagina MirrorPC non trovata');
  const socket = new WebSocket(page.webSocketDebuggerUrl); let id = 0; const pending = new Map();
  socket.on('message', raw => { const message = JSON.parse(raw); if (message.id && pending.has(message.id)) { pending.get(message.id)(message); pending.delete(message.id); } });
  await new Promise(resolve => socket.once('open', resolve));
  const call = (method, params = {}) => new Promise(resolve => { const requestId = ++id; pending.set(requestId, resolve); socket.send(JSON.stringify({ id: requestId, method, params })); });
  await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await call('Runtime.evaluate', { expression: 'location.reload()', awaitPromise: true }); await new Promise(resolve => setTimeout(resolve, 900));
  const result = await call('Runtime.evaluate', { expression: `JSON.stringify((()=>{const b=document.getElementById('openScanner').getBoundingClientRect();return{innerWidth,scrollWidth:document.documentElement.scrollWidth,scanner:{left:b.left,right:b.right,top:b.top,width:b.width},devices:document.querySelectorAll('.saved-device').length}})())`, returnByValue: true });
  const metrics = JSON.parse(result.result.result.value);
  if (metrics.scrollWidth > metrics.innerWidth + 1) throw new Error(`Overflow mobile: ${metrics.scrollWidth}px su ${metrics.innerWidth}px`);
  if (metrics.scanner.left < 0 || metrics.scanner.right > metrics.innerWidth) throw new Error('Scanner fuori dal viewport mobile');
  await call('Runtime.evaluate', { expression: `document.getElementById('openScanner').click()` });
  const dialog = await call('Runtime.evaluate', { expression: `!document.getElementById('scannerDialog').classList.contains('hidden')`, returnByValue: true });
  if (!dialog.result.result.value) throw new Error('Dialog scanner non aperto');
  socket.close(); console.log(`UI mobile, scanner e ${metrics.devices} dispositivi: OK`);
}

run().catch(error => { console.error(error.message); process.exitCode = 1; });
