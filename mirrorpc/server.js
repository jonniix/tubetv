'use strict';

const crypto = require('crypto');
const dgram = require('dgram');
const http = require('http');
const os = require('os');
const path = require('path');
const { execFile, spawn } = require('child_process');
const { promisify } = require('util');
const express = require('express');
const QRCode = require('qrcode');
const { WebSocketServer, WebSocket } = require('ws');

const PORT = Number(process.env.PORT || 4177);
const app = express();
const server = http.createServer(app);
const sessions = new Map();
const execFileAsync = promisify(execFile);
let controlAgent = null;

app.disable('x-powered-by');
app.get(['/', '/control'], (req, res) => res.sendFile(path.join(__dirname, 'control.html')));
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
  const joinUrl = `http://${host}:${PORT}/display.html?code=${code}`;
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

function isLoopback(req) {
  const address = String(req.socket.remoteAddress || '').replace(/^::ffff:/, '');
  return address === '127.0.0.1' || address === '::1';
}

function clientAddress(req) {
  return String(req.socket.remoteAddress || '').replace(/^::ffff:/, '');
}

function isLanClient(req) {
  const address = clientAddress(req);
  return address === '127.0.0.1' || address === '::1' || /^(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)/.test(address);
}

function validLanHost(value) {
  const host = String(value || '').trim();
  if (!/^(?:10|192\.168|172\.(?:1[6-9]|2\d|3[01]))(?:\.\d{1,3}){2,3}$/.test(host)) return '';
  const parts = host.split('.').map(Number);
  return parts.length === 4 && parts.every(part => part >= 0 && part <= 255) ? host : '';
}

app.get('/api/devices/status', async (req, res) => {
  if (!isLanClient(req)) return res.status(403).json({ ok: false, message: 'Disponibile soltanto nella rete locale' });
  const host = validLanHost(req.query.host);
  if (!host) return res.status(400).json({ ok: false, message: 'Indirizzo LAN non valido' });
  const started = Date.now();
  try {
    const args = process.platform === 'win32' ? ['-n', '1', '-w', '1200', host] : ['-c', '1', '-W', '1', host];
    await execFileAsync(process.platform === 'win32' ? 'ping.exe' : 'ping', args, { windowsHide: true, timeout: 2200 });
    res.json({ ok: true, online: true, host, latencyMs: Date.now() - started });
  } catch {
    res.json({ ok: true, online: false, host, latencyMs: null });
  }
});

app.post('/api/devices/wake', async (req, res) => {
  if (!isLanClient(req)) return res.status(403).json({ ok: false, message: 'Wake-on-LAN consentito soltanto dalla rete locale' });
  const compact = String(req.body?.mac || '').replace(/[^a-fA-F0-9]/g, '');
  if (!/^[a-fA-F0-9]{12}$/.test(compact)) return res.status(400).json({ ok: false, message: 'MAC address non valido' });
  const mac = Buffer.from(compact, 'hex');
  const packet = Buffer.concat([Buffer.alloc(6, 0xff), ...Array.from({ length: 16 }, () => mac)]);
  const socket = dgram.createSocket('udp4');
  try {
    await new Promise((resolve, reject) => {
      socket.once('error', reject);
      socket.bind(() => { socket.setBroadcast(true); socket.send(packet, 0, packet.length, 9, '255.255.255.255', error => error ? reject(error) : resolve()); });
    });
    res.json({ ok: true, message: 'Magic Packet inviato' });
  } catch {
    res.status(500).json({ ok: false, message: 'Invio Wake-on-LAN non riuscito' });
  } finally { socket.close(); }
});

function ensureControlAgent() {
  if (process.platform !== 'win32') return null;
  if (controlAgent && !controlAgent.killed) return controlAgent;
  controlAgent = spawn('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(__dirname, 'control-agent.ps1')], {
    windowsHide: true, stdio: ['pipe', 'ignore', 'pipe']
  });
  controlAgent.on('exit', () => { controlAgent = null; });
  controlAgent.stderr.on('data', data => console.error(`Remote input: ${String(data).trim()}`));
  return controlAgent;
}

app.post('/api/system/control', (req, res) => {
  if (!isLoopback(req)) return res.status(403).json({ ok: false, message: 'Controllo consentito soltanto all’host locale' });
  const event = req.body?.event;
  if (!event || !['move', 'button', 'wheel', 'key'].includes(event.type)) return res.status(400).json({ ok: false, message: 'Comando non valido' });
  const agent = ensureControlAgent();
  if (!agent) return res.status(409).json({ ok: false, message: 'Controllo remoto disponibile sull’app Windows' });
  agent.stdin.write(`${JSON.stringify(event)}\n`);
  res.json({ ok: true });
});

async function windowsDisplayStatus() {
  if (process.platform !== 'win32') {
    return { supported: false, platform: process.platform, driverInstalled: false, adapters: [] };
  }
  try {
    const [displayResult, monitorResult] = await Promise.all([
      execFileAsync('pnputil.exe', ['/enum-devices', '/class', 'Display'], {
        windowsHide: true,
        timeout: 5000,
        maxBuffer: 128 * 1024
      }),
      execFileAsync('pnputil.exe', ['/enum-devices', '/class', 'Monitor'], {
        windowsHide: true,
        timeout: 5000,
        maxBuffer: 128 * 1024
      })
    ]);
    const raw = `${displayResult.stdout}\n${monitorResult.stdout}`;
    const driverInstalled = /virtual\s*(display|monitor)|iddsample|parsec.*display/i.test(raw);
    const adapters = raw.split(/\r?\n/)
      .map(line => line.match(/^\s*(?:Device Description|Descrizione dispositivo)\s*:\s*(.+)$/i)?.[1]?.trim())
      .filter(Boolean)
      .map(name => ({ name }));
    return { supported: true, platform: 'win32', driverInstalled, adapters };
  } catch (error) {
    return { supported: true, platform: 'win32', driverInstalled: false, adapters: [], diagnostic: error.code || 'DISPLAY_QUERY_FAILED' };
  }
}

app.get('/api/system/display', async (req, res) => {
  res.set('cache-control', 'no-store');
  res.json(await windowsDisplayStatus());
});

app.post('/api/system/open-display-settings', (req, res) => {
  if (process.platform !== 'win32') return res.status(409).json({ ok: false, message: 'Disponibile solo su Windows' });
  if (!isLoopback(req)) return res.status(403).json({ ok: false, message: 'Comando consentito soltanto dal PC host' });
  execFile('cmd.exe', ['/c', 'start', '', 'ms-settings:display'], { windowsHide: true }, error => {
    if (error) return res.status(500).json({ ok: false, message: 'Impossibile aprire le impostazioni schermo' });
    res.json({ ok: true });
  });
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
  const local = `http://localhost:${PORT}/`;
  console.log(`\nMirrorPC pronto\nHost: ${local}\nRicevitore LAN: http://${lanAddress()}:${PORT}\n`);
  if (process.platform === 'win32' && process.env.AUTO_OPEN !== '0') execFile('cmd.exe', ['/c', 'start', '', local]);
});
