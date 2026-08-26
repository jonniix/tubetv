'use strict';

const http = require('http');
const WebSocket = require('ws');
const port = Number(process.env.PORT || 4177);

function requestSession() {
  return new Promise((resolve, reject) => {
    const req = http.request({ hostname: '127.0.0.1', port, path: '/api/session', method: 'POST', headers: { 'content-type': 'application/json' } }, res => {
      let body = '';
      res.on('data', chunk => { body += chunk; });
      res.on('end', () => resolve(JSON.parse(body)));
    });
    req.on('error', reject); req.end('{}');
  });
}

function waitFor(ws, type, timeout = 3000) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`Timeout: ${type}`)), timeout);
    const handler = raw => {
      const message = JSON.parse(raw.toString());
      if (message.type !== type) return;
      clearTimeout(timer); ws.off('message', handler); resolve(message);
    };
    ws.on('message', handler);
  });
}

(async () => {
  const session = await requestSession();
  const hostSocket = new WebSocket(`ws://127.0.0.1:${port}/signal`);
  await new Promise(resolve => hostSocket.once('open', resolve));
  hostSocket.send(JSON.stringify({ type: 'host_register', code: session.code, token: session.token }));
  await waitFor(hostSocket, 'host_ready');

  const viewerSocket = new WebSocket(`ws://127.0.0.1:${port}/signal`);
  await new Promise(resolve => viewerSocket.once('open', resolve));
  const joined = waitFor(hostSocket, 'viewer_join');
  viewerSocket.send(JSON.stringify({ type: 'viewer_join', code: session.code }));
  const ready = await waitFor(viewerSocket, 'viewer_ready');
  const joinNotice = await joined;
  if (ready.viewerId !== joinNotice.viewerId) throw new Error('Viewer ID non coerente');

  const relayed = waitFor(viewerSocket, 'signal');
  hostSocket.send(JSON.stringify({ type: 'signal', to: ready.viewerId, data: { test: 'host-to-viewer' } }));
  if ((await relayed).data.test !== 'host-to-viewer') throw new Error('Relay host-viewer fallito');

  hostSocket.close(); viewerSocket.close();
  console.log('Signaling, pairing e relay: OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
