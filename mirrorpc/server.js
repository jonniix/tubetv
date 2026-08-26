'use strict';

const crypto = require('crypto');
const http = require('http');
const os = require('os');
const path = require('path');
const { execFile } = require('child_process');
const express = require('express');
const QRCode = require('qrcode');
const { WebSocketServer, WebSocket } = require('ws');

const PORT = Number(process.env.PORT || 4177);
const app = express();
const server = http.createServer(app);
const sessions = new Map();

app.disable('x-powered-by');
app.use(express.json({ limit: '32kb' }));
app.use(express.static(path.join(__dirname, 'public'), {
  etag: true,
  maxAge: process.env.NODE_ENV === 'production' ? '1h' : 0
}));

function randomCode() {
  let code;
  do code = String(crypto.randomInt(0, 1000000)).padStart(6, '0'); while (sessions.has(code));
  return code;
}

function lanAddress() {
  if (process.env.MIRROR_HOST) return process.env.MIRROR_HOST;
  const candidates = Object.values(os.networkInterfaces()).flat().filter(Boolean)
    .filter(n => n.family === 'IPv4' && !n.internal);
  return (candidates.find(n => /^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/.test(n.address)) || candidates[0] || {}).address || 'localhost';
}

function cleanupSession(code) {
  const session = sessions.get(code);
  if (!session) return;
  clearTimeout(session.expiry);
  for (const viewer of session.viewers.values()) {
    if (viewer.readyState === WebSocket.OPEN) viewer.close(1001, 'Sessione terminata');
  }
  sessions.delete(code);
}

app.post('/api/session', async (req, res) => {
  const code = randomCode();
  const token = crypto.randomBytes(24).toString('base64url');
  const host = lanAddress();
  const joinUrl = `http://${host}:${PORT}/?code=${code}`;
  const expiry = setTimeout(() => {
    const session = sessions.get(code);
    if (session && !session.host) cleanupSession(code);
  }, 5 * 60 * 1000);
  sessions.set(code, { token, host: null, viewers: new Map(), createdAt: Date.now(), expiry });
  const qr = await QRCode.toDataURL(joinUrl, { margin: 1, width: 420, color: { dark: '#06131fff', light: '#f3f8ffff' } });
  res.json({ code, token, joinUrl, qr, expiresIn: 300 });
});

app.get('/api/health', (req, res) => {
  res.json({ ok: true, service: 'MirrorPC', sessions: sessions.size, address: lanAddress(), port: PORT });
});

app.get('/host', (req, res) => res.sendFile(path.join(__dirname, 'public', 'host.html')));

const wss = new WebSocketServer({ server, path: '/signal', maxPayload: 256 * 1024 });

function send(ws, message) {
  if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(message));
}

function safeTokenEqual(expected, received) {
  const a = Buffer.from(String(expected || ''));
  const b = Buffer.from(String(received || ''));
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

wss.on('connection', ws => {
  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });

  ws.on('message', raw => {
    let msg;
    try { msg = JSON.parse(raw.toString()); } catch { return send(ws, { type: 'error', message: 'Messaggio non valido' }); }

    if (msg.type === 'host_register') {
      const session = sessions.get(String(msg.code || ''));
      if (!session || !safeTokenEqual(session.token, msg.token)) {
        return send(ws, { type: 'error', message: 'Sessione host non valida' });
      }
      session.host = ws;
      ws.role = 'host'; ws.code = msg.code;
      clearTimeout(session.expiry);
      send(ws, { type: 'host_ready' });
      return;
    }

    if (msg.type === 'viewer_join') {
      const code = String(msg.code || '');
      const session = sessions.get(code);
      if (!session || !session.host) return send(ws, { type: 'error', message: 'Sessione non disponibile o scaduta' });
      const viewerId = crypto.randomUUID();
      session.viewers.set(viewerId, ws);
      ws.role = 'viewer'; ws.code = code; ws.viewerId = viewerId;
      send(ws, { type: 'viewer_ready', viewerId });
      send(session.host, { type: 'viewer_join', viewerId, viewers: session.viewers.size });
      return;
    }

    const session = sessions.get(ws.code);
    if (!session || msg.type !== 'signal') return;
    if (ws.role === 'host') {
      send(session.viewers.get(String(msg.to || '')), { type: 'signal', from: 'host', data: msg.data });
    } else if (ws.role === 'viewer') {
      send(session.host, { type: 'signal', from: ws.viewerId, data: msg.data });
    }
  });

  ws.on('close', () => {
    const session = sessions.get(ws.code);
    if (!session) return;
    if (ws.role === 'host') return cleanupSession(ws.code);
    if (ws.role === 'viewer') {
      session.viewers.delete(ws.viewerId);
      send(session.host, { type: 'viewer_left', viewerId: ws.viewerId, viewers: session.viewers.size });
    }
  });
});

const heartbeat = setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.isAlive) { ws.terminate(); continue; }
    ws.isAlive = false; ws.ping();
  }
}, 20000);

server.on('close', () => clearInterval(heartbeat));
server.listen(PORT, '0.0.0.0', () => {
  const local = `http://localhost:${PORT}/host`;
  console.log(`\nMirrorPC pronto\nHost: ${local}\nRicevitore LAN: http://${lanAddress()}:${PORT}\n`);
  if (process.platform === 'win32' && process.env.AUTO_OPEN !== '0') execFile('cmd.exe', ['/c', 'start', '', local]);
});
