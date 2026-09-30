# Education Hub — WhatsApp Group Sender

Sends study material (text, image or PDF) to many WhatsApp groups from one local Windows PC.
Laravel 13 + Livewire, MySQL in Docker, and a Playwright worker that drives WhatsApp Web in a
visible Chromium window. No WhatsApp API is used. Full specification: `docs/MASTER_PROMPT.md`.

## Requirements (Windows)

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.3+ | `winget install PHP.PHP.8.4`. Create `php.ini` from `php.ini-development` and enable: `curl fileinfo gd intl mbstring openssl pdo_mysql pdo_sqlite sqlite3 sodium zip exif`. Set `memory_limit=512M`, `upload_max_filesize=100M`, `post_max_size=110M`. |
| Composer | 2.x | https://getcomposer.org |
| Node.js | 20+ | https://nodejs.org |
| Docker Desktop | any | Runs MySQL only. Must be started before the app. |
| Git | any | |

## First-time setup

```bash
git clone https://github.com/Sachinx1911/WhatsApp-Group-Sender.git
cd WhatsApp-Group-Sender
copy .env.example .env
```

Edit `.env` and set real values for `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `ADMIN_PASSWORD`
(8+ characters; the placeholder is refused) and `WHATSAPP_WORKER_TOKEN` (a long random string).
Then:

```bash
docker compose up -d --wait
composer setup
```

`composer setup` installs dependencies, generates the app key, runs migrations, creates the
admin login and default categories, builds the frontend and installs the WhatsApp worker
(including Chromium). Finally put the **same** `WHATSAPP_WORKER_TOKEN` in `playwright/.env`.

## Daily use

1. Start Docker Desktop.
2. Double-click `start.bat`. It applies updates, recovers interrupted campaigns, and starts
   the web app, the sending queue and the WhatsApp worker, then opens http://127.0.0.1:8010.
3. Sign in with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env`.
4. The first time, a Chromium window shows a QR code: scan it from **WhatsApp → Linked
   devices**. The login is kept in `storage/app/whatsapp-session/` and survives restarts.

Keep the `start.bat` window and the Chromium window open while sending.

## Practice mode vs real sending

`WHATSAPP_DRIVER=fake` (the default) only pretends to send. Set `WHATSAPP_DRIVER=playwright`
in `.env` and restart to send for real. Always test with the test group first
(Settings → Sending Settings), then 2–3 groups, before a full campaign.

## Development

```bash
composer dev        # server + queue + vite + worker with live reload
composer test       # feature tests (SQLite in memory)
vendor/bin/pint     # code style
```

When WhatsApp changes its layout, every locator lives in `playwright/services/selectors.js`;
see `playwright/README.md` for the inspection scripts.
