const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');
const net = require('net');
const {spawn, execFile} = require('child_process');

const APP_ROOT = process.env.TUBETV_DESKTOP_APP || path.join(__dirname, '..', '.tubetv-local', 'desktop-host', 'app');
const PRIVATE_ROOT = path.join(APP_ROOT, 'private');
const PHP = process.env.TUBETV_DESKTOP_PHP || 'php.exe';
const PHP_INI = process.env.TUBETV_DESKTOP_PHP_INI || path.join(APP_ROOT, 'php.ini');
const CACHE_ROOT = process.env.TUBETV_SEGMENT_CACHE_DIR || path.join(PRIVATE_ROOT, 'segment-cache');
const PORT = Number(process.env.TUBETV_DESKTOP_PORT || 8765);
const FIRST_WORKER_PORT = Number(process.env.TUBETV_DESKTOP_WORKER_PORT || 8810);
const WORKER_COUNT = Math.max(4, Math.min(16, Number(process.env.TUBETV_DESKTOP_WORKERS || 8)));

for (const dir of [PRIVATE_ROOT, CACHE_ROOT, path.join(PRIVATE_ROOT, 'worker-activity')]) fs.mkdirSync(dir, {recursive: true});

const workers = [];
let shuttingDown = false;
let totalProxyBytes = 0;
let previousCpu = cpuTicks();
let cpuPercent = 0;
let network = {rx: 0, tx: 0, downloadBps: 0, uploadBps: 0, sampledAt: Date.now()};
let pingMs = null;

function cpuTicks() {
  let total = 0, idle = 0;
  for (const cpu of os.cpus()) for (const [name, value] of Object.entries(cpu.times)) {
    total += value;
    if (name === 'idle') idle += value;
  }
  return {total, idle};
}

function taskFor(url) {
  const pathname = new URL(url, 'http://localhost').pathname;
  if (pathname.includes('dashboard')) return 'Monitor server';
  if (pathname.includes('client-heartbeat')) return 'Presenza dispositivo';
  if (pathname.includes('catalog-browse')) return 'Navigazione catalogo';
  if (pathname.includes('catalog-section')) return 'Sezione catalogo';
  if (pathname.includes('catalog')) return 'Catalogo IPTV';
  if (pathname.includes('adaptive')) return 'Qualita automatica RTX';
  if (pathname.includes('transcode')) return 'Conversione video';
  if (pathname.includes('relay')) return 'Relay video';
  if (pathname.includes('stream')) return 'Segmento video';
  return 'Servizio web';
}

function launchWorker(index) {
  const worker = workers[index] || {number: index + 1, active: 0, task: 'In attesa', startedAt: 0, restarts: 0};
  workers[index] = worker;
  const port = FIRST_WORKER_PORT + index;
  const env = {...process.env, TUBETV_SEGMENT_CACHE_DIR: CACHE_ROOT};
  const child = spawn(PHP, ['-c', PHP_INI, '-S', `127.0.0.1:${port}`, path.join(APP_ROOT, 'router.php')], {
    cwd: APP_ROOT, env, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe']
  });
  worker.port = port;
  worker.pid = child.pid || 0;
  worker.child = child;
  child.stderr.on('data', chunk => {
    const line = String(chunk);
    if (/Fatal|error|failed/i.test(line)) fs.appendFileSync(path.join(PRIVATE_ROOT, 'desktop-host-error.log'), line);
  });
  child.on('exit', () => {
    worker.pid = 0;
    if (!shuttingDown) {
      worker.restarts++;
      setTimeout(() => launchWorker(index), Math.min(5000, 500 + worker.restarts * 250));
    }
  });
}

for (let index = 0; index < WORKER_COUNT; index++) launchWorker(index);

const prefetch = spawn(PHP, ['-c', PHP_INI, path.join(APP_ROOT, 'prefetch-worker.php')], {
  cwd: APP_ROOT,
  env: {...process.env, TUBETV_SEGMENT_CACHE_DIR: CACHE_ROOT},
  windowsHide: true,
  stdio: ['ignore', 'ignore', 'pipe']
});
prefetch.stderr.on('data', chunk => fs.appendFileSync(path.join(PRIVATE_ROOT, 'prefetch-error.log'), String(chunk)));

const agent = new http.Agent({keepAlive: true, maxSockets: 256, maxFreeSockets: 32, scheduling: 'lifo'});

function chooseWorker() {
  const online = workers.filter(worker => worker.pid > 0);
  if (!online.length) return null;
  return online.reduce((best, worker) => worker.active < best.active ? worker : best, online[0]);
}

const server = http.createServer((req, res) => {
  const worker = chooseWorker();
  if (!worker) {
    res.writeHead(503, {'content-type': 'application/json', 'retry-after': '1'});
    return res.end(JSON.stringify({ok: false, error: 'DESKTOP_WORKERS_STARTING'}));
  }
  worker.active++;
  worker.task = taskFor(req.url);
  worker.startedAt = Date.now();
  const headers = {...req.headers, host: req.headers.host || `127.0.0.1:${PORT}`, connection: 'keep-alive'};
  const incomingForwarded = String(req.headers['x-forwarded-for'] || '').trim();
  const remote = String(req.socket.remoteAddress || '').replace(/^::ffff:/, '');
  if (incomingForwarded) headers['x-forwarded-for'] = incomingForwarded;
  else if (!['127.0.0.1', '::1'].includes(remote)) headers['x-forwarded-for'] = remote;
  else delete headers['x-forwarded-for'];
  const upstream = http.request({host: '127.0.0.1', port: worker.port, method: req.method, path: req.url, headers, agent}, upstreamRes => {
    res.writeHead(upstreamRes.statusCode || 502, upstreamRes.headers);
    upstreamRes.on('data', chunk => { totalProxyBytes += chunk.length; });
    upstreamRes.pipe(res);
  });
  const finish = () => {
    worker.active = Math.max(0, worker.active - 1);
    if (!worker.active) worker.task = 'In attesa';
  };
  upstream.on('error', error => {
    if (!res.headersSent) res.writeHead(502, {'content-type': 'application/json'});
    if (!res.writableEnded) res.end(JSON.stringify({ok: false, error: 'DESKTOP_BACKEND_FAILED'}));
    fs.appendFileSync(path.join(PRIVATE_ROOT, 'desktop-host-error.log'), `${new Date().toISOString()} ${error.message}\n`);
  });
  upstream.on('close', finish);
  req.on('aborted', () => upstream.destroy());
  req.pipe(upstream);
});

server.keepAliveTimeout = 65000;
server.headersTimeout = 70000;
server.requestTimeout = 0;
server.maxRequestsPerSocket = 0;

function sampleNetwork() {
  const script = "$items=Get-NetAdapterStatistics -ErrorAction SilentlyContinue | Where-Object {$_.Name -notmatch 'Tailscale'};$rx=($items|Measure-Object -Property ReceivedBytes -Sum).Sum;$tx=($items|Measure-Object -Property SentBytes -Sum).Sum;[pscustomobject]@{rx=[double]$rx;tx=[double]$tx}|ConvertTo-Json -Compress";
  execFile('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', script], {windowsHide: true, timeout: 2500}, (error, stdout) => {
    if (error) return;
    try {
      const current = JSON.parse(String(stdout));
      const now = Date.now(), seconds = Math.max(.001, (now - network.sampledAt) / 1000);
      network = {
        rx: Number(current.rx || 0), tx: Number(current.tx || 0), sampledAt: now,
        downloadBps: Math.max(0, (Number(current.rx || 0) - network.rx) / seconds),
        uploadBps: Math.max(0, (Number(current.tx || 0) - network.tx) / seconds)
      };
    } catch (_) {}
  });
}

function samplePing() {
  const started = Date.now();
  const socket = net.createConnection({host: '1.1.1.1', port: 443, timeout: 1800}, () => {
    pingMs = Date.now() - started;
    socket.destroy();
  });
  socket.on('timeout', () => socket.destroy());
  socket.on('error', () => {});
}

function writeStats() {
  const nowCpu = cpuTicks();
  const totalDelta = Math.max(1, nowCpu.total - previousCpu.total);
  cpuPercent = Math.max(0, Math.min(100, 100 * (1 - (nowCpu.idle - previousCpu.idle) / totalDelta)));
  previousCpu = nowCpu;
  let diskTotal = 0, diskFree = 0;
  try { const disk = fs.statfsSync(APP_ROOT); diskTotal = disk.blocks * disk.bsize; diskFree = disk.bavail * disk.bsize; } catch (_) {}
  const payload = {
    updatedAt: Date.now(), hostname: os.hostname(), uptime: os.uptime(), cpuPercent,
    memoryTotal: os.totalmem(), memoryUsed: os.totalmem() - os.freemem(),
    diskTotal, diskUsed: Math.max(0, diskTotal - diskFree), pingMs,
    network, totalProxyBytes,
    runtime: {
      workers: workers.length, processes: workers.filter(worker => worker.pid).length,
      busyWorkers: workers.filter(worker => worker.active > 0).length,
      mediaWorkers: workers.filter(worker => worker.active > 0 && !/Monitor|Presenza/.test(worker.task)).length,
      idleWorkers: workers.filter(worker => worker.pid && worker.active === 0).length,
      workerDetails: workers.map(worker => ({
        number: worker.number, pid: worker.pid, busy: worker.active > 0, task: worker.task,
        durationMs: worker.active ? Date.now() - worker.startedAt : 0, memoryMb: 0, updatedAt: Date.now() / 1000
      }))
    }
  };
  const target = path.join(PRIVATE_ROOT, 'windows-host-stats.json');
  const temporary = `${target}.${process.pid}.tmp`;
  fs.writeFileSync(temporary, JSON.stringify(payload));
  if (process.platform === 'win32') try { fs.unlinkSync(target); } catch (_) {}
  fs.renameSync(temporary, target);
}

setInterval(writeStats, 1000).unref();
setInterval(sampleNetwork, 3000).unref();
setInterval(samplePing, 10000).unref();
sampleNetwork(); samplePing(); writeStats();

function shutdown() {
  if (shuttingDown) return;
  shuttingDown = true;
  server.close();
  for (const worker of workers) if (worker.child) worker.child.kill();
  prefetch.kill();
  setTimeout(() => process.exit(0), 800).unref();
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

server.listen(PORT, '127.0.0.1', () => process.stdout.write(`TubeTV Desktop Host http://127.0.0.1:${PORT} with ${WORKER_COUNT} PHP workers\n`));
