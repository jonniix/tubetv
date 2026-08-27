'use strict';
const WebSocket = require('ws');
const port = Number(process.env.CDP_PORT || 9224);
async function run() {
  const pages = await fetch(`http://127.0.0.1:${port}/json`).then(r => r.json());
  const page = pages.find(item => item.type === 'page'); if (!page) throw new Error('Browser test non trovato');
  const socket = new WebSocket(page.webSocketDebuggerUrl); let id = 0; const pending = new Map(), exceptions = [];
  socket.on('message', raw => { const msg = JSON.parse(raw); if (msg.method === 'Runtime.exceptionThrown') exceptions.push(msg.params.exceptionDetails.text); if (pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); } });
  await new Promise(resolve => socket.once('open', resolve));
  const call = (method, params = {}) => new Promise(resolve => { const requestId = ++id; pending.set(requestId, resolve); socket.send(JSON.stringify({ id: requestId, method, params })); });
  await call('Runtime.enable'); await call('Page.enable');
  await call('Page.navigate', { url: `https://tubetv.online/mirrorpc/?audit=${Date.now()}` }); await new Promise(r => setTimeout(r, 1200));
  const prepared = await call('Runtime.evaluate', { expression: `(()=>{document.getElementById('openJoin').click();const input=document.getElementById('permanentPortalId');input.value='231540221914';input.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('joinPermanent').click();return true})()`, returnByValue: true });
  if (!prepared.result.result.value) throw new Error('Clic ID non eseguito');
  await new Promise(r => setTimeout(r, 1800));
  const result = await call('Runtime.evaluate', { expression: `JSON.stringify({href:location.href,device:new URLSearchParams(location.search).get('device'),field:document.getElementById('permanentPortalId')?.value||'',toast:document.getElementById('toast')?.textContent||'',dialog:!document.getElementById('joinDialog')?.classList.contains('hidden')})`, returnByValue: true });
  const state = JSON.parse(result.result.result.value);
  if (!state.dialog || !/nessuna condivisione|offline|non raggiungibile/i.test(state.toast)) throw new Error(`Stato PC non mostrato nel dialog: ${JSON.stringify(state)}`);
  if (exceptions.length) throw new Error(`Errore JavaScript: ${exceptions.join(', ')}`);
  socket.close(); console.log(JSON.stringify(state));
}
run().catch(error => { console.error(error.message); process.exitCode = 1; });

