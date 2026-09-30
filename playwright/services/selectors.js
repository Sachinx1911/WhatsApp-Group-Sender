'use strict';

/**
 * Every WhatsApp Web locator lives here (docs/MASTER_PROMPT.md §32), so that when
 * WhatsApp changes its UI, only this file needs to change. Locators prefer role,
 * aria-label and data-* attributes over CSS classes, which WhatsApp renames often.
 *
 * These are best-effort as of the WhatsApp Web layout in use today. If the worker
 * starts failing to detect QR/connected/chat state, check this file first — open
 * web.whatsapp.com in a normal browser, inspect the element, and update the locator
 * here.
 */

/** True once the page has rendered *something* recognizable (QR or chat list). */
function appShell(page) {
  return page.locator('#app, #pane-side, canvas[aria-label], [data-testid="qrcode"]').first();
}

/** The QR code canvas shown before a device is linked. */
function qrCode(page) {
  return page.locator('canvas[aria-label*="Scan" i], div[data-testid="qrcode"] canvas, canvas').first();
}

/** The left-hand chat list, only present once a session is linked and loaded. */
function chatListPane(page) {
  return page.locator('#pane-side, div[aria-label="Chat list"]').first();
}

/** Loading spinner shown briefly while a saved session is being restored. */
function startupLoading(page) {
  return page.getByText(/loading your chats/i).first();
}

/** A generic "connection lost" / "computer not connected to the internet" banner. */
function offlineBanner(page) {
  return page.getByText(/computer.*not connected|phone.*not connected|trying to reconnect/i).first();
}

/**
 * The search box at the top of the chat list. Current WhatsApp Web renders this as a real
 * <input>; older builds used a contenteditable div, kept here as a fallback.
 */
function chatSearchBox(page) {
  return page.locator([
    'input[aria-label="Search or start a new chat"]',
    'div[data-testid="chat-list-search-container"] input',
    'div[contenteditable="true"][data-tab="3"]',
    'div[aria-label="Search input textbox"]',
  ].join(', ')).first();
}

/**
 * One row in the chat list. Rows are role="row" (data-testid="list-item-N"); each row
 * holds two span[title] elements — the chat name in the "cell-frame-title" cell, and the
 * last-message preview below it. Only the title cell is a chat name.
 */
function chatRows(page) {
  return page.locator('#pane-side div[role="row"]');
}

/** The chat-name span inside every chat row (excludes message previews). */
function chatTitleSpans(page) {
  return page.locator('#pane-side div[role="row"] div[data-testid="cell-frame-title"] span[title]');
}

/** One row in the chat list search results, matched on the exact chat name. */
function chatListResult(page, exactName) {
  return chatRows(page).filter({
    has: page.locator('div[data-testid="cell-frame-title"]').getByTitle(exactName, { exact: true }),
  }).first();
}

/**
 * The title of the currently open chat — the check that stops a message going to the
 * wrong group. Current WhatsApp Web puts the name in the text of a data-testid span with
 * no title attribute, so read innerText, not the attribute. Scope to #main: the chat-list
 * header also contains spans, and one of them ("click here for group info") does carry a
 * title attribute that must never be mistaken for the chat name.
 */
function openChatHeaderTitle(page) {
  return page.locator([
    '#main header span[data-testid="conversation-info-header-chat-title"]',
    '#main header span[dir="auto"][title]',
  ].join(', ')).first();
}

/** The message composer box in the open chat. */
function messageComposer(page) {
  return page.locator('footer div[contenteditable="true"][data-tab="10"], div[aria-label="Type a message"]').first();
}

/** Send button next to the composer (appears once there is text or an attachment). */
function sendButton(page) {
  return page.locator('button[aria-label="Send"], span[data-icon="send"]').first();
}

/** Attach ("+" / paperclip) button that opens the attachment menu. */
function attachButton(page) {
  return page.locator('button[aria-label="Attach"], button[title="Attach"], div[title="Attach"], span[data-icon="plus-rounded"], span[data-icon="clip"]').first();
}

/**
 * Entries in the attach menu. These must be clicked to open WhatsApp's own file chooser.
 *
 * Never set files on a raw input[type=file] instead: WhatsApp keeps hidden image inputs in
 * the page for the *group profile picture*, and writing to one of those silently replaces
 * the group's icon rather than sending a message.
 */
function attachPhotosMenuItem(page) {
  return page.getByText(/^Photos & videos$/i).first();
}

function attachDocumentMenuItem(page) {
  return page.getByText(/^Document$/i).first();
}

/**
 * Caption box on the media preview screen. The open chat's own composer is still in the
 * page behind the preview, and typing the caption into that one would leave the text in
 * the chat box instead of attaching it. The preview's box is the one outside <footer>;
 * its aria-label is exactly "Type a message", while the chat composer's names the chat.
 */
function attachmentCaptionBox(page) {
  return page.locator('xpath=//div[@contenteditable="true"][@aria-label="Type a message"][not(ancestor::footer)]').first();
}

/** Send button on the media preview screen; its label carries a count ("Send 1 selected"). */
function attachmentSendButton(page) {
  return page.locator('xpath=//*[@role="button"][.//*[@data-testid="wds-ic-send-filled"]][not(ancestor::footer)]').first();
}

/**
 * Confirming a send. WhatsApp no longer marks outgoing bubbles with a .message-out class,
 * and the tick is not a data-icon: the delivery state is exposed to screen readers as an
 * aria-label on the outgoing bubble ("Sent", "Delivered", "Read"). Incoming messages have
 * no such label, so finding one on the last row means our message went out.
 *
 * Matched on the exact trimmed label — a substring match would accept "unread".
 */
const MESSAGE_ROW_SELECTOR = '#main div[role="row"]';
const DELIVERY_STATUS_PATTERN = '^(sent|delivered|read|played)$';

/** The last message bubble in the open conversation. */
function lastMessageRow(page) {
  return page.locator(MESSAGE_ROW_SELECTOR).last();
}

/** Three-dot menu button in the top-right corner of the chat list header. */
function menuButton(page) {
  return page.locator('div[title="Menu"], button[aria-label="Menu"]').first();
}

/**
 * "Log out" item inside the menu. Anchored to the exact wording: a loose match would also
 * hit entries like "Log out of all devices", and logging out unlinks the phone.
 */
function logoutMenuItem(page) {
  return page.getByText(/^Log out$/i).first();
}

/** "Log out" confirmation button in the dialog WhatsApp shows before logging out. */
function logoutConfirmButton(page) {
  return page.locator('div[role="dialog"] button', { hasText: /log out/i }).first();
}

module.exports = {
  appShell,
  chatRows,
  chatTitleSpans,
  qrCode,
  chatListPane,
  startupLoading,
  offlineBanner,
  chatSearchBox,
  chatListResult,
  openChatHeaderTitle,
  messageComposer,
  sendButton,
  attachButton,
  attachPhotosMenuItem,
  attachDocumentMenuItem,
  attachmentCaptionBox,
  attachmentSendButton,
  lastMessageRow,
  MESSAGE_ROW_SELECTOR,
  DELIVERY_STATUS_PATTERN,
  menuButton,
  logoutMenuItem,
  logoutConfirmButton,
};
