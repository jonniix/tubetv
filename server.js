/**
 * TubeTV – optional local server
 * Serves static files and exposes POST /api/save-data to write data/tubetv-data.json.
 *
 * Usage:
 *   npm install
 *   npm start          → http://localhost:3000
 */

'use strict';

require('dotenv').config();

const express = require('express');
const fs      = require('fs');
const path    = require('path');

const app  = express();
const PORT = process.env.PORT || 3000;
// Keep local Node mode aligned with the PHP deployment and the public client.
const DATA_FILE = path.join(__dirname, 'data', 'tubetv-data.json');

function isPlainObject(v) {
  return !!v && typeof v === 'object' && !Array.isArray(v);
}

function deepMerge(base, patch) {
  if (!isPlainObject(base) || !isPlainObject(patch)) return patch;
  const out = { ...base };
  for (const [k, v] of Object.entries(patch)) {
    if (isPlainObject(v) && isPlainObject(out[k])) out[k] = deepMerge(out[k], v);
    else out[k] = v;
  }
  return out;
}

// ── Middleware ──────────────────────────────────────────────────────────────
app.use(express.json({ limit: '20mb' }));
app.use('/private', (_req, res) => res.status(404).end());
app.get(['/tv', '/tv/'], (_req, res) => res.sendFile(path.join(__dirname, 'tv.html')));
app.get(['/tv-connect', '/tv-connect/'], (_req, res) => res.sendFile(path.join(__dirname, 'tv-connect.html')));
app.use(express.static(__dirname));          // serve index.html, admin.html, etc.

// ── GET /api/ping ────────────────────────────────────────────────────────────
app.get('/api/ping', (_req, res) => {
  res.json({ ok: true, mode: 'server' });
});

// ── POST /api/save-data (+ .php alias for local parity with Hostpoint) ─────
app.post(['/api/save-data', '/api/save-data.php'], (req, res) => {
  const { filename, data, merge, preserveRuntime } = req.body || {};

  if (!filename || typeof data !== 'object') {
    return res.status(400).json({ ok: false, error: 'Parametri mancanti o non validi' });
  }

  // Security: only allow saving canonical tubetv-data.json in project root.
  const safeFilename = path.basename(filename);
  if (safeFilename !== 'tubetv-data.json') {
    return res.status(403).json({ ok: false, error: 'Nome file non consentito' });
  }

  // Strip API keys from the payload before writing (never persist secrets)
  const safeIncoming = JSON.parse(JSON.stringify(data));
  const incomingKeys = Object.keys(safeIncoming || {});
  const livePatchKeys = ['publicLiveSchedule', 'liveQueue', 'liveState', 'botState', 'botHistory', 'lastBotPublishAt', 'version', 'liveSkipRequest'];
  const catalogKeys = ['videos', 'catalog', 'channels', 'series', 'seriesEpisodes', 'kids', 'videoLibrary'];

  let existing = {};
  if (fs.existsSync(DATA_FILE)) {
    try {
      existing = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8')) || {};
    } catch (_e) {
      existing = {};
    }
  }

  let safeData = merge ? deepMerge(existing, safeIncoming) : safeIncoming;

  if (preserveRuntime && isPlainObject(existing) && isPlainObject(safeData)) {
    const runtimeKeys = [
      'schedule', 'palinsesto', 'scheduleMeta', 'scheduleArchive',
      'internalSlotSchedule', 'publicLiveSchedule', 'liveQueue', 'liveState',
      'botHistory', 'airedLast30Days', 'lastBotPublishAt'
    ];
    for (const key of runtimeKeys) {
      if (existing[key] !== undefined) safeData[key] = existing[key];
    }
    // Preserve server-owned fields while accepting editable bot preferences.
    if (isPlainObject(existing.botState)) safeData.botState = existing.botState;
  }

  // Prevent catalog destruction on live-only patches.
  const isLivePatchOnly = incomingKeys.every(k => livePatchKeys.includes(k));
  if (isLivePatchOnly && isPlainObject(existing) && isPlainObject(safeData)) {
    for (const k of catalogKeys) {
      if (existing[k] !== undefined && safeData[k] === undefined) {
        safeData[k] = existing[k];
      }
    }
  }

  if (safeData.settings) {
    delete safeData.settings.apiKey;
    delete safeData.settings.youtubeApiKey;
    delete safeData.settings.ytApiKey;
  }
  if (Array.isArray(safeData.channels)) {
    safeData.channels = safeData.channels.map(({ apiKey, ...rest }) => rest);
  }

  try {
    const tmpFile = DATA_FILE + '.tmp';
    fs.writeFileSync(tmpFile, JSON.stringify(safeData, null, 2), 'utf8');
    try {
      fs.renameSync(tmpFile, DATA_FILE);
    } catch (renameErr) {
      if (fs.existsSync(DATA_FILE)) {
        fs.unlinkSync(DATA_FILE);
      }
      fs.renameSync(tmpFile, DATA_FILE);
    }
    console.log('[TubeTV] Saved:', DATA_FILE);
    res.json({ ok: true, merge: !!merge });
  } catch (err) {
    try {
      const tmpFile = DATA_FILE + '.tmp';
      if (fs.existsSync(tmpFile)) fs.unlinkSync(tmpFile);
    } catch (_cleanupErr) {}
    console.error('[TubeTV] Write error:', err.message);
    res.status(500).json({ ok: false, error: err.message });
  }
});

// ── Start ───────────────────────────────────────────────────────────────────
app.listen(PORT, () => {
  console.log(`TubeTV server running → http://localhost:${PORT}`);
  console.log(`Admin panel          → http://localhost:${PORT}/admin.html`);
});
