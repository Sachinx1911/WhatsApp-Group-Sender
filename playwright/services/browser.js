'use strict';

const path = require('node:path');
const { chromium } = require('playwright');

/**
 * Owns the single persistent Chromium context for this worker (docs/MASTER_PROMPT.md
 * §32–33). One profile, one visible window, launched once and reused across sends so
 * the linked WhatsApp Web session survives restarts of the worker itself.
 */
class BrowserSession {
  constructor({ userDataDir, headless = false }) {
    this.userDataDir = userDataDir;
    this.headless = headless;
    /** @type {import('playwright').BrowserContext|null} */
    this.context = null;
    /** @type {import('playwright').Page|null} */
    this.page = null;
  }

  isOpen() {
    return this.context !== null && this.page !== null && !this.page.isClosed();
  }

  /** Launch the browser (idempotent) and return the WhatsApp Web page. */
  async open() {
    if (this.isOpen()) {
      return this.page;
    }

    this.context = await chromium.launchPersistentContext(this.userDataDir, {
      headless: this.headless,
      viewport: { width: 1280, height: 900 },
      args: ['--disable-blink-features=AutomationControlled'],
    });

    this.page = this.context.pages()[0] ?? (await this.context.newPage());
    this.context.on('close', () => {
      this.context = null;
      this.page = null;
    });

    await this.page.goto('https://web.whatsapp.com', { waitUntil: 'domcontentloaded' });

    return this.page;
  }

  /** Reload WhatsApp Web without closing the browser (used by /restart). */
  async reload() {
    if (!this.isOpen()) {
      return this.open();
    }

    await this.page.goto('https://web.whatsapp.com', { waitUntil: 'domcontentloaded' });

    return this.page;
  }

  /** Close the browser entirely (used by /disconnect after logging out in-app). */
  async close() {
    if (this.context) {
      await this.context.close().catch(() => {});
    }

    this.context = null;
    this.page = null;
  }
}

function defaultUserDataDir() {
  // playwright/ sits next to storage/ at the Laravel project root.
  return path.resolve(__dirname, '..', '..', 'storage', 'app', 'whatsapp-session');
}

module.exports = { BrowserSession, defaultUserDataDir };
