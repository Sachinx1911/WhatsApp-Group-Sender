'use strict';

/**
 * Developer aid: exercises the "find the group, open it, confirm the title matches"
 * path from services/whatsapp.js WITHOUT typing or sending any message, and reports what
 * each selector resolved to. Use it to re-verify selectors.js after a WhatsApp UI change.
 *
 *   node playwright/scripts/inspect-open-chat.js "Exact Group Name"
 *
 * The worker must be stopped first: both use the same browser profile.
 */

const { BrowserSession, defaultUserDataDir } = require('../services/browser');
const sel = require('../services/selectors');

const target = process.argv[2];

if (!target) {
  console.error('Usage: node playwright/scripts/inspect-open-chat.js "Exact Group Name"');
  process.exit(1);
}

async function main() {
  const session = new BrowserSession({ userDataDir: defaultUserDataDir(), headless: false });
  const page = await session.open();

  await page.waitForTimeout(7000);

  console.log('chat list visible:', await sel.chatListPane(page).isVisible().catch(() => false));

  const search = sel.chatSearchBox(page);
  console.log('search box found:', await search.isVisible().catch(() => false));

  await search.click();
  try {
    await search.fill(target, { timeout: 5000 });
  } catch {
    await page.keyboard.insertText(target);
  }
  await page.waitForTimeout(1800);

  const rows = await sel.chatRows(page).count();
  console.log(`rows after searching for "${target}":`, rows);

  const match = sel.chatListResult(page, target);
  const matched = await match.isVisible().catch(() => false);
  console.log('exact-name row matched:', matched);

  if (matched) {
    await match.click();
    await page.waitForTimeout(1500);

    const header = sel.openChatHeaderTitle(page);
    const headerFound = await header.isVisible().catch(() => false);
    const headerTitleAttr = await header.getAttribute('title').catch(() => null);
    const headerText = await header.innerText().catch(() => null);

    console.log('header element found:', headerFound);
    console.log('header title attribute:', JSON.stringify(headerTitleAttr));
    console.log('header inner text:', JSON.stringify(headerText));
    console.log('TITLE MATCHES TARGET EXACTLY:', (headerTitleAttr || headerText || '').trim() === target.trim());

    console.log('composer found:', await sel.messageComposer(page).isVisible().catch(() => false));
    console.log('attach button found:', await sel.attachButton(page).isVisible().catch(() => false));
  }

  await page.keyboard.press('Escape').catch(() => {});
  await session.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
