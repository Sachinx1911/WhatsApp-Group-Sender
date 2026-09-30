# WhatsApp Web worker

A long-running local Node process that drives WhatsApp Web in a visible Chromium window
(`docs/MASTER_PROMPT.md` §32). Laravel never touches Playwright directly: it calls this
worker over `127.0.0.1` with a shared token, through `PlaywrightWhatsAppService`.

Nothing here talks to a WhatsApp API. It automates the normal WhatsApp Web linked-device
flow, exactly as a person would use it.

## Setup

```bash
npm install --prefix playwright
cp playwright/.env.example playwright/.env
```

Then set `WHATSAPP_WORKER_TOKEN` in `playwright/.env` to the **same** value as
`WHATSAPP_WORKER_TOKEN` in the Laravel `.env`, and set `WHATSAPP_DRIVER=playwright` in the
Laravel `.env` so the app uses this worker instead of the practice driver.

## Running

`start.bat` and `composer dev` start the worker together with the app and the queue. To
run it alone:

```bash
node playwright/worker.js
```

The first run opens Chromium with a QR code. Scan it from your phone
(**WhatsApp → Linked devices**). The session is stored in
`storage/app/whatsapp-session/` and survives restarts, so you normally scan once.

Keep the Chromium window open while sending. It is the real WhatsApp Web session.

## Checking it works

```bash
node playwright/scripts/check-status.js
```

Expected: `HTTP 200` and a state of `CONNECTED`, `WAITING_FOR_QR`, `STARTING`, or
`DISCONNECTED`.

## Endpoints

All require the `X-Worker-Token` header and are bound to `127.0.0.1` only.

| Method | Path | Purpose |
|---|---|---|
| GET | `/status` | Current connection state |
| POST | `/connect` | Open WhatsApp Web (idempotent) |
| POST | `/disconnect` | Log out this linked device and close the browser |
| POST | `/restart` | Reload WhatsApp Web |
| POST | `/send` | `{ group, message, attachment_path }` — one send at a time |
| GET | `/groups` | Chat names, for "Sync from WhatsApp" |

The worker also pushes state changes to Laravel at `POST /internal/whatsapp/state`, plus a
heartbeat every 15 seconds so Settings can show whether the worker is running.

## When WhatsApp changes its UI

This is the part that breaks over time. **Every locator lives in
`services/selectors.js`** — fix it there, nowhere else.

Two helper scripts inspect the live page so you can correct a selector from real DOM
instead of guessing. Stop the worker first: both use the same browser profile.

```bash
node playwright/scripts/inspect-dom.js                     # chat-list structure
node playwright/scripts/inspect-open-chat.js "Group 1"     # search → open → title check
```

`inspect-open-chat.js` reports whether the chat-title check still resolves. That check is
what stops a message being sent to the wrong group, so if it reports
`TITLE MATCHES TARGET EXACTLY: false`, fix `openChatHeaderTitle` before sending anything.

Locators prefer `role`, `aria-label` and `data-*` over CSS classes, which WhatsApp
renames constantly. Two examples of why that matters, both found by the scripts above:
the old `.message-out` class no longer exists, and the delivery tick is not an icon — the
state is exposed as an `aria-label` of `Sent` / `Delivered` / `Read`.

## Safety notes

- The browser profile in `storage/app/whatsapp-session/` holds a live WhatsApp login. It
  is git-ignored and must never be committed or shared.
- The worker accepts one send at a time; a concurrent send is rejected as busy.
- A send is only reported successful once WhatsApp shows a delivery state for it.
- `/send` verifies the opened chat's title matches the requested group exactly before
  typing anything.
