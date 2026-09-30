'use strict';

/**
 * Standalone smoke test (docs/MASTER_PROMPT.md §48): verify the worker responds
 * correctly before ever wiring it to the Laravel UI.
 *
 *   node playwright/scripts/check-status.js
 */

require('dotenv').config({ path: require('node:path').join(__dirname, '..', '.env') });

const PORT = process.env.WORKER_PORT || 3010;
const TOKEN = process.env.WHATSAPP_WORKER_TOKEN || '';
const BASE = `http://127.0.0.1:${PORT}`;

async function main() {
  if (!TOKEN) {
    console.error('WHATSAPP_WORKER_TOKEN is not set in playwright/.env');
    process.exit(1);
  }

  console.log(`Checking ${BASE}/status ...`);

  const res = await fetch(`${BASE}/status`, { headers: { 'X-Worker-Token': TOKEN } });
  const body = await res.json();

  console.log(`HTTP ${res.status}`);
  console.log(JSON.stringify(body, null, 2));

  if (!res.ok) {
    process.exit(1);
  }

  console.log('\nWorker is reachable and responding.');
}

main().catch((error) => {
  console.error('Could not reach the worker. Is `node playwright/worker.js` running?');
  console.error(error.message);
  process.exit(1);
});
