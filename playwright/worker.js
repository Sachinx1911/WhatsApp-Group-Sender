'use strict';

require('dotenv').config();

const http = require('node:http');
const { URL } = require('node:url');

const { BrowserSession, defaultUserDataDir } = require('./services/browser');
const wa = require('./services/whatsapp');
const { StateReporter } = require('./services/stateReporter');

const PORT = Number(process.env.WORKER_PORT || 3010);
const HOST = '127.0.0.1';
const WORKER_TOKEN = process.env.WHATSAPP_WORKER_TOKEN || '';
const LARAVEL_URL = process.env.LARAVEL_URL || 'http://127.0.0.1:8010';
const HEARTBEAT_MS = Number(process.env.WORKER_HEARTBEAT_MS || 15000);
const HEADLESS = process.env.WORKER_HEADLESS === 'true';

if (!WORKER_TOKEN) {
  console.error('WHATSAPP_WORKER_TOKEN is not set (playwright/.env). It must match the Laravel .env value. Exiting.');
  process.exit(1);
}

/** Timestamped worker log. Never logs the token, session data, or message contents. */
function log(line) {
  console.log(`[${new Date().toISOString()}] ${line}`);
}

const session = new BrowserSession({ userDataDir: defaultUserDataDir(), headless: HEADLESS });
const reporter = new StateReporter({ laravelUrl: LARAVEL_URL, workerToken: WORKER_TOKEN, heartbeatMs: HEARTBEAT_MS });

/** Only one /send in flight at a time (docs/MASTER_PROMPT.md §32.7): one browser, one profile. */
let sending = false;
let currentState = wa.STATE.DISCONNECTED;

async function reportIfChanged(newState, reason = null) {
  if (newState !== currentState) {
    log(`state: ${currentState} -> ${newState}${reason ? ` (${reason})` : ''}`);
    currentState = newState;
    await reporter.report(newState, reason);
  }
}

async function refreshState() {
  const state = await wa.detectState(session.page);
  await reportIfChanged(state);
  return state;
}

function sendJson(res, status, body) {
  const data = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) });
  res.end(data);
}

async function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', (chunk) => { raw += chunk; });
    req.on('end', () => {
      if (!raw) return resolve({});
      try {
        resolve(JSON.parse(raw));
      } catch (e) {
        reject(e);
      }
    });
    req.on('error', reject);
  });
}

function isLocal(req) {
  const addr = req.socket.remoteAddress || '';
  return addr === '127.0.0.1' || addr === '::1' || addr === '::ffff:127.0.0.1';
}

function hasValidToken(req) {
  const token = req.headers['x-worker-token'];
  return typeof token === 'string' && token.length > 0 && token === WORKER_TOKEN;
}

const routes = {
  'GET /status': async (req, res) => {
    const state = await refreshState();
    sendJson(res, 200, { state, browser_open: session.isOpen(), sending });
  },

  'POST /connect': async (req, res) => {
    await session.open();
    const state = await wa.waitForSettledState(session.page, 20000);
    await reportIfChanged(state, 'connect');
    sendJson(res, 200, { state });
  },

  'POST /disconnect': async (req, res) => {
    if (session.isOpen()) {
      try {
        await wa.logOut(session.page);
        await session.page.waitForTimeout(1500);
      } catch (e) {
        console.warn('[worker] logout via UI failed, closing browser anyway:', e.message);
      }
    }

    await session.close();
    await reportIfChanged(wa.STATE.DISCONNECTED, 'disconnect');
    sendJson(res, 200, { state: wa.STATE.DISCONNECTED });
  },

  'POST /restart': async (req, res) => {
    await session.reload();
    const state = await wa.waitForSettledState(session.page, 20000);
    await reportIfChanged(state, 'restart');
    sendJson(res, 200, { state });
  },

  'GET /groups': async (req, res) => {
    if (!session.isOpen()) {
      return sendJson(res, 200, { success: false, error_type: 'WHATSAPP_DISCONNECTED', error_message: 'WhatsApp is not connected.' });
    }

    // ?scope=groups (default) reads WhatsApp's Groups tab; ?scope=all reads every chat.
    const scope = new URL(req.url, `http://${HOST}:${PORT}`).searchParams.get('scope') === 'all' ? 'all' : 'groups';

    try {
      log(`listing chats (scope: ${scope})`);
      const groups = await wa.listChats(session.page, scope);
      log(`listed ${groups.length} chat(s)`);
      sendJson(res, 200, { success: true, scope, groups });
    } catch (error) {
      const errorType = error.errorType || 'UNKNOWN_ERROR';
      sendJson(res, 200, { success: false, error_type: errorType, error_message: error.message });
    }
  },

  'POST /send': async (req, res) => {
    if (sending) {
      return sendJson(res, 409, { success: false, error_type: 'UNKNOWN_ERROR', error_message: 'Worker is busy sending another message.' });
    }

    let body;
    try {
      body = await readJsonBody(req);
    } catch {
      return sendJson(res, 400, { success: false, error_type: 'UNKNOWN_ERROR', error_message: 'Invalid JSON body.' });
    }

    const { group, message, attachment_path: attachmentPath } = body;

    if (!group || typeof group !== 'string') {
      return sendJson(res, 422, { success: false, error_type: 'UNKNOWN_ERROR', error_message: '"group" is required.' });
    }

    if (!session.isOpen()) {
      return sendJson(res, 200, { success: false, group, error_type: 'WHATSAPP_DISCONNECTED', error_message: 'WhatsApp is not connected.' });
    }

    sending = true;
    const startedAt = Date.now();
    log(`send started: "${group}"${attachmentPath ? ' (with attachment)' : ''}`);

    try {
      const result = await wa.sendToGroup(session.page, { group, message: message || '', attachmentPath: attachmentPath || null });
      const seconds = ((Date.now() - startedAt) / 1000).toFixed(1);

      if (result.success) {
        log(`send completed: "${group}" in ${seconds}s`);
      } else {
        log(`send failed: "${group}" in ${seconds}s — ${result.error_type}: ${result.error_message}`);
      }

      sendJson(res, 200, result);
    } catch (error) {
      // sendToGroup handles expected failures itself, so reaching here means something
      // unexpected broke. Answer with a structured error instead of an empty response,
      // which Laravel would otherwise read as UNKNOWN_ERROR with no detail.
      log(`send crashed: "${group}" — ${error && error.message}`);

      sendJson(res, 200, {
        success: false,
        group,
        error_type: 'BROWSER_ERROR',
        error_message: 'The browser hit an unexpected problem while sending.',
        technical: String((error && error.stack) || error),
      });
    } finally {
      sending = false;
    }
  },
};

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://${HOST}:${PORT}`);
    const key = `${req.method} ${url.pathname}`;

    if (!isLocal(req)) {
      return sendJson(res, 403, { success: false, error_message: 'Forbidden: local requests only.' });
    }

    if (!hasValidToken(req)) {
      return sendJson(res, 401, { success: false, error_message: 'Missing or invalid X-Worker-Token.' });
    }

    const handler = routes[key];
    if (!handler) {
      return sendJson(res, 404, { success: false, error_message: `No such route: ${key}` });
    }

    await handler(req, res);
  } catch (error) {
    console.error('[worker] unhandled error:', error);
    sendJson(res, 500, { success: false, error_type: 'BROWSER_ERROR', error_message: 'Unexpected worker error.', technical: String(error && error.stack || error) });
  }
});

server.listen(PORT, HOST, async () => {
  log(`worker started, listening on http://${HOST}:${PORT}`);
  log(`reporting state to ${LARAVEL_URL} every ${HEARTBEAT_MS}ms`);

  reporter.start(() => currentState);
  await reportIfChanged(wa.STATE.STARTING, 'boot');

  // Auto-open the browser on boot so a previously-linked session reconnects without
  // the admin having to press "Connect" every time (matches docs/MASTER_PROMPT.md §33).
  try {
    await session.open();
    const state = await wa.waitForSettledState(session.page, 20000);
    await reportIfChanged(state, 'boot');
  } catch (error) {
    console.error('[worker] failed to open browser on boot:', error);
  }
});

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

async function shutdown() {
  log('worker stopping');
  reporter.stop();
  await session.close().catch(() => {});
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 3000).unref();
}
