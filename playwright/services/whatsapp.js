'use strict';

const sel = require('./selectors');

/** Select-all is Cmd+A on macOS and Ctrl+A elsewhere; using the wrong one silently types over nothing. */
const SELECT_ALL = process.platform === 'darwin' ? 'Meta+A' : 'Control+A';

/** Raised for expected, structured failures. Caught by worker.js and turned into JSON. */
class WhatsAppError extends Error {
  constructor(errorType, message, technical = null) {
    super(message);
    this.errorType = errorType;
    this.technical = technical;
  }
}

const STATE = {
  STARTING: 'STARTING',
  WAITING_FOR_QR: 'WAITING_FOR_QR',
  CONNECTED: 'CONNECTED',
  DISCONNECTED: 'DISCONNECTED',
};

/** Look at the current page and decide which of the four states we are in. */
async function detectState(page) {
  if (!page || page.isClosed()) {
    return STATE.DISCONNECTED;
  }

  const [hasQr, hasChatList] = await Promise.all([
    sel.qrCode(page).isVisible().catch(() => false),
    sel.chatListPane(page).isVisible().catch(() => false),
  ]);

  if (hasChatList) return STATE.CONNECTED;
  if (hasQr) return STATE.WAITING_FOR_QR;

  return STATE.STARTING;
}

/** Poll detectState() until it settles on CONNECTED, WAITING_FOR_QR, or the timeout passes. */
async function waitForSettledState(page, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  let last = STATE.STARTING;

  while (Date.now() < deadline) {
    last = await detectState(page);
    if (last === STATE.CONNECTED || last === STATE.WAITING_FOR_QR) {
      return last;
    }
    await sleep(500);
  }

  return last;
}

/** Open the exact group chat by name, verifying the opened chat's title matches exactly. */
async function openGroupChat(page, groupName) {
  const search = await focusChatSearch(page);

  // Clear whatever a previous send left behind, then type the name. fill() covers the
  // real <input> WhatsApp uses now; the keyboard path covers a contenteditable fallback.
  try {
    await search.fill(groupName, { timeout: 5000 });
  } catch {
    await page.keyboard.press(SELECT_ALL).catch(() => {});
    await page.keyboard.press('Delete').catch(() => {});
    await page.keyboard.insertText(groupName);
  }

  await sleep(1200); // let WhatsApp's own search debounce/filter run

  const result = sel.chatListResult(page, groupName);
  const found = await result.first().isVisible().catch(() => false);

  if (!found) {
    await clearSearch(page);
    throw new WhatsAppError('GROUP_NOT_FOUND', `No WhatsApp group matches "${groupName}" exactly.`);
  }

  await result.first().click();
  await sleep(400);

  // Safety gate: never send until the chat that actually opened is the one we asked for.
  const header = sel.openChatHeaderTitle(page);
  await header.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});

  const headerTitle = (await header.innerText().catch(() => null))
    || (await header.getAttribute('title').catch(() => null));

  if (!headerTitle || headerTitle.trim() !== groupName.trim()) {
    await clearSearch(page);
    throw new WhatsAppError(
      'GROUP_NOT_FOUND',
      `The opened chat ("${headerTitle ?? 'unknown'}") does not match "${groupName}" exactly.`,
    );
  }
}

async function clearSearch(page) {
  await page.keyboard.press('Escape').catch(() => {});
}

/**
 * Focus the chat-list search box, ready to type a group name.
 *
 * During a campaign this runs once per group, right after the previous send. Whatever the
 * last send left on screen (an open chat, a closing attachment preview, leftover search
 * text) can briefly cover the search box and make the click time out, which showed up as
 * spurious BROWSER_ERROR failures that burned a retry. So: reset the UI first, wait for
 * the box to actually be ready, and give it a second attempt before giving up.
 */
async function focusChatSearch(page, attempts = 2) {
  let lastError = null;

  for (let attempt = 1; attempt <= attempts; attempt++) {
    await page.keyboard.press('Escape').catch(() => {});
    await sleep(attempt * 500);

    const search = sel.chatSearchBox(page);

    try {
      await search.waitFor({ state: 'visible', timeout: 8000 });
      await search.click({ timeout: 8000 });

      return search;
    } catch (e) {
      lastError = e;
    }
  }

  throw new WhatsAppError('BROWSER_ERROR', 'Could not open the chat search box.', String(lastError));
}

/** Type text into a contenteditable composer, preserving line breaks (Shift+Enter). */
async function typeMultiline(page, box, text) {
  await box.click();
  await page.keyboard.press(SELECT_ALL).catch(() => {});
  await page.keyboard.press('Delete').catch(() => {});

  const lines = String(text).split('\n');

  for (let i = 0; i < lines.length; i++) {
    if (lines[i].length > 0) {
      await page.keyboard.insertText(lines[i]);
    }
    if (i < lines.length - 1) {
      await page.keyboard.press('Shift+Enter');
    }
  }
}

/** Send a text-only message to the currently open chat. */
async function sendTextMessage(page, message) {
  const composer = sel.messageComposer(page);
  const visible = await composer.isVisible({ timeout: 10000 }).catch(() => false);

  if (!visible) {
    throw new WhatsAppError('ONLY_ADMINS_CAN_SEND', 'The message box is not available. You may not be able to send in this group.');
  }

  await typeMultiline(page, composer, message);

  const send = sel.sendButton(page);
  await send.click({ timeout: 5000 }).catch(async () => {
    await page.keyboard.press('Enter');
  });

  await confirmSent(page);
}

const IMAGE_FILE = /\.(png|jpe?g|gif|webp)$/i;

/**
 * Upload an attachment with the message text as its caption.
 *
 * The file always goes through WhatsApp's own file chooser, opened by clicking the menu
 * entry. Setting files directly on an input[type=file] is deliberately avoided: the page
 * also holds hidden image inputs belonging to the group profile picture, and writing to
 * one of those changes the group's icon instead of sending anything.
 */
async function sendAttachmentMessage(page, message, attachmentPath) {
  const attach = sel.attachButton(page);
  await attach.click({ timeout: 5000 }).catch(() => {
    throw new WhatsAppError('MEDIA_UPLOAD_FAILED', 'Could not open the attachment menu.');
  });

  const isImage = IMAGE_FILE.test(attachmentPath);
  const menuItem = isImage ? sel.attachPhotosMenuItem(page) : sel.attachDocumentMenuItem(page);

  let chooser;
  try {
    [chooser] = await Promise.all([
      page.waitForEvent('filechooser', { timeout: 15000 }),
      menuItem.click({ timeout: 10000 }),
    ]);
    await chooser.setFiles(attachmentPath);
  } catch (e) {
    await closePreview(page);
    throw new WhatsAppError('MEDIA_UPLOAD_FAILED', 'The attachment could not be uploaded.', String(e));
  }

  // The preview's own send button appearing is the proof that the preview really opened.
  const send = sel.attachmentSendButton(page);

  try {
    await send.waitFor({ state: 'visible', timeout: 20000 });
  } catch {
    await closePreview(page);
    throw new WhatsAppError('MEDIA_UPLOAD_FAILED', 'The attachment preview did not open.');
  }

  if (message) {
    const caption = sel.attachmentCaptionBox(page);
    const captionReady = await caption.isVisible().catch(() => false);

    if (!captionReady) {
      await closePreview(page);
      throw new WhatsAppError('MEDIA_UPLOAD_FAILED', 'The caption box did not open, so the message text would have been lost.');
    }

    await typeMultiline(page, caption, message);
  }

  await send.click({ timeout: 10000 }).catch(() => {
    throw new WhatsAppError('MESSAGE_SEND_FAILED', 'Could not confirm sending the attachment.');
  });

  await confirmSent(page);
}

/** Back out of a half-opened attachment preview so the next send starts from a clean chat. */
async function closePreview(page) {
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(500);
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(300);
}

/**
 * Wait until WhatsApp confirms the message left this device: the newest bubble carries a
 * delivery state ("Sent", "Delivered", "Read"). Until then the message is still pending,
 * and reporting success would be a lie the admin cannot check later.
 */
async function confirmSent(page, timeoutMs = 20000) {
  try {
    await page.waitForFunction(
      ({ rowSelector, statusPattern }) => {
        const rows = document.querySelectorAll(rowSelector);
        const last = rows[rows.length - 1];
        if (!last) return false;

        const status = new RegExp(statusPattern, 'i');

        return Array.from(last.querySelectorAll('[aria-label]'))
          .map((el) => (el.getAttribute('aria-label') || '').trim())
          .some((label) => status.test(label));
      },
      { rowSelector: sel.MESSAGE_ROW_SELECTOR, statusPattern: sel.DELIVERY_STATUS_PATTERN },
      { timeout: timeoutMs, polling: 500 },
    );
  } catch {
    throw new WhatsAppError('TIMEOUT', 'WhatsApp did not confirm the message was sent in time.');
  }
}

/** Full send flow for one group: open chat, send, confirm. Never throws for expected errors. */
async function sendToGroup(page, { group, message, attachmentPath }) {
  try {
    if ((await detectState(page)) !== STATE.CONNECTED) {
      throw new WhatsAppError('WHATSAPP_DISCONNECTED', 'WhatsApp is not connected.');
    }

    await openGroupChat(page, group);

    if (attachmentPath) {
      await sendAttachmentMessage(page, message, attachmentPath);
    } else {
      await sendTextMessage(page, message);
    }

    await clearSearch(page);

    return { success: true, group, timestamp: new Date().toISOString() };
  } catch (error) {
    await clearSearch(page).catch(() => {});

    if (error instanceof WhatsAppError) {
      return { success: false, group, error_type: error.errorType, error_message: error.message, technical: error.technical };
    }

    return { success: false, group, error_type: 'UNKNOWN_ERROR', error_message: 'An unknown error occurred.', technical: String(error && error.stack || error) };
  }
}

/**
 * List chat titles for "Sync from WhatsApp".
 *
 * scope "groups" reads WhatsApp's own Groups tab, so personal chats are never imported;
 * scope "all" reads everything. A chat row itself carries no marker distinguishing a group
 * from a one-to-one chat, so the tab is the only dependable signal.
 */
async function listChats(page, scope = 'groups') {
  const pane = sel.chatListPane(page);
  const visible = await pane.isVisible().catch(() => false);

  if (!visible) {
    throw new WhatsAppError('WHATSAPP_DISCONNECTED', 'WhatsApp is not connected.');
  }

  const wantsGroupsOnly = scope === 'groups';

  if (wantsGroupsOnly) {
    const tab = await findGroupsTab(page);

    if (!tab) {
      throw new WhatsAppError(
        'BROWSER_ERROR',
        'WhatsApp is not showing a "Groups" filter above the chat list right now, so groups cannot be told apart from personal chats. Add the Groups list in WhatsApp, or set sync to include all chats.',
      );
    }

    await tab.click();
    await sleep(1500);
  }

  try {
    return await collectChatNames(page);
  } finally {
    // Always go back to "All": leaving the Groups filter on would narrow the chat search
    // that every later send depends on.
    if (wantsGroupsOnly) {
      await sel.chatFilterTab(page, 'All').click({ timeout: 5000 }).catch(() => {});
      await sleep(500);
    }
  }
}

/**
 * Locate the "Groups" filter chip, or null when WhatsApp is not offering one.
 *
 * The chips above the chat list are not fixed: which ones appear varies (All, Unread,
 * Favourites, Groups, Communities...) and the row scrolls sideways, so a chip can simply
 * be off-screen. Scroll the row before concluding it is absent.
 */
async function findGroupsTab(page) {
  const tab = sel.chatFilterTab(page, 'Groups');

  if (await tab.isVisible().catch(() => false)) {
    return tab;
  }

  const tablist = page.locator('[role="tablist"]').first();

  for (let pass = 0; pass < 6; pass++) {
    const moved = await tablist.evaluate((el) => {
      const from = el.scrollLeft;
      el.scrollLeft = from + 150;

      return el.scrollLeft !== from;
    }).catch(() => false);

    await sleep(300);

    if (await tab.isVisible().catch(() => false)) {
      return tab;
    }

    if (!moved) {
      break;
    }
  }

  return null;
}

/**
 * Read every chat name, scrolling as we go. The chat list is virtualised: only the rows on
 * screen exist in the page, so without scrolling a long list would import just the first
 * screenful. Stops once scrolling stops revealing new names.
 */
async function collectChatNames(page) {
  const scroller = sel.chatListScroller(page);
  const seen = new Set();
  let idlePasses = 0;

  for (let pass = 0; pass < 80 && idlePasses < 3; pass++) {
    // Only the chat-name cell: each row also holds a span[title] for the message preview,
    // which must never be imported as if it were a group name.
    const batch = await sel.chatTitleSpans(page).evaluateAll((spans) => spans
      .map((span) => span.getAttribute('title'))
      .filter((name) => typeof name === 'string' && name.trim().length > 0)
      .map((name) => name.trim()));

    const before = seen.size;
    batch.forEach((name) => seen.add(name));

    idlePasses = seen.size === before ? idlePasses + 1 : 0;

    const moved = await scroller.evaluate((el) => {
      const from = el.scrollTop;
      el.scrollTop = from + Math.max(200, el.clientHeight * 0.8);

      return el.scrollTop !== from;
    }).catch(() => false);

    if (!moved) {
      idlePasses++;
    }

    await sleep(500);
  }

  await scroller.evaluate((el) => { el.scrollTop = 0; }).catch(() => {});

  return Array.from(seen);
}

/** Log out of WhatsApp Web via the in-app menu (removes the linked device). */
async function logOut(page) {
  const menu = sel.menuButton(page);
  await menu.click({ timeout: 8000 }).catch(() => {
    throw new WhatsAppError('BROWSER_ERROR', 'Could not open the WhatsApp menu to log out.');
  });

  const logout = sel.logoutMenuItem(page);
  await logout.click({ timeout: 8000 }).catch(() => {
    throw new WhatsAppError('BROWSER_ERROR', 'Could not find "Log out" in the menu.');
  });

  const confirm = sel.logoutConfirmButton(page);
  const hasConfirm = await confirm.isVisible({ timeout: 5000 }).catch(() => false);

  if (hasConfirm) {
    await confirm.click();
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

module.exports = {
  STATE,
  WhatsAppError,
  detectState,
  waitForSettledState,
  sendToGroup,
  listChats,
  logOut,
};
