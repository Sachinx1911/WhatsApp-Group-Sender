'use strict';

/**
 * Developer aid: prints how the live WhatsApp Web chat list is structured right now, so
 * services/selectors.js can be corrected when WhatsApp changes its DOM. Reports shapes
 * and counts only — never chat contents.
 *
 *   node playwright/scripts/inspect-dom.js
 *
 * The worker must be stopped first: both use the same browser profile.
 */

const { BrowserSession, defaultUserDataDir } = require('../services/browser');

async function main() {
  const session = new BrowserSession({ userDataDir: defaultUserDataDir(), headless: false });
  const page = await session.open();

  await page.waitForTimeout(8000);

  const report = await page.evaluate(() => {
    const pane = document.querySelector('#pane-side');
    if (!pane) return { pane: false };

    const countOf = (sel) => pane.querySelectorAll(sel).length;

    // Walk up from a chat-name span to see what the repeating row element looks like.
    const firstTitleSpan = pane.querySelector('span[title]');
    const ancestry = [];
    let node = firstTitleSpan;
    while (node && node !== pane && ancestry.length < 8) {
      ancestry.push({
        tag: node.tagName.toLowerCase(),
        role: node.getAttribute('role'),
        testid: node.getAttribute('data-testid'),
      });
      node = node.parentElement;
    }

    return {
      pane: true,
      counts: {
        'span[title]': countOf('span[title]'),
        '[role=listitem]': countOf('[role="listitem"]'),
        '[role=row]': countOf('[role="row"]'),
        '[role=gridcell]': countOf('[role="gridcell"]'),
        '[role=listbox]': countOf('[role="listbox"]'),
        '[data-testid]': countOf('[data-testid]'),
      },
      paneRole: pane.getAttribute('role'),
      ancestryOfFirstTitle: ancestry,
    };
  });

  console.log(JSON.stringify(report, null, 2));

  await session.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
