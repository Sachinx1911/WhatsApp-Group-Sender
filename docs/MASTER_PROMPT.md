# MASTER PROMPT — EDUCATION HUB WHATSAPP GROUP SENDER (Revised v2)

> Revision notes (v2): this version adds the architecture decisions, fixes contradictions in v1, adds Marathi/Devanagari support, Test Send, ETA, resume and duplicate-protection rules, editable categories and a daily limit, and moves non-essential features to a Future (V2) list.

---

## 1. PROJECT OVERVIEW

Build a polished, production-quality local web application named:

**Education Hub — WhatsApp Group Sender**

This is an internal education-content distribution system for one administrator who manages 250+ WhatsApp student groups.

The administrator must be able to:

* Connect an existing WhatsApp account through WhatsApp Web using the normal linked-device QR flow.
* Maintain a database of 250+ WhatsApp groups.
* Organize groups by category/batch. Categories are editable.
* Compose a message. Marathi (Devanagari), English, emoji and multi-line text are fully supported.
* Attach **one** image or PDF per message. The message text is sent as its caption.
* Select one, multiple, or all groups.
* Send a **test** to a designated test group before a full campaign.
* Send the prepared content through the locally connected WhatsApp Web session.
* Monitor sending progress, including an estimated time remaining.
* Pause, resume and cancel a campaign.
* View successful and failed sends.
* Retry or skip failed messages.
* Save reusable message templates.
* Manage uploaded media.
* View complete send history.
* Configure application and WhatsApp session settings.

### Important product decisions

* Do NOT build a WhatsApp Business API integration. No WhatsApp API of any kind.
* The application runs locally on one Windows PC and uses a connected WhatsApp Web session through browser automation.
* Browser automation: **Playwright + Chromium + WhatsApp Web**.
* Do NOT use Selenium.
* Do NOT create an Express or other Node business backend.
* **Allowed exception:** a small long-running Node Playwright worker (see §31). It:
  * uses only Node's built-in `http` module,
  * listens only on `127.0.0.1`,
  * requires a shared secret token,
  * contains no business logic.

  All state and business rules live in Laravel.
* **UI language: English.** Message content may be Marathi, English or mixed.

---

## 2. CORE TECHNOLOGY STACK

### Backend
* **Laravel: latest stable version** (Laravel 13 if released and stable, otherwise Laravel 12). Verify at install time.
* PHP 8.3+ (the machine has PHP 8.4).
* Laravel Blade
* **Livewire: latest stable version**
* Laravel Queue (database driver)

### Frontend
* Livewire
* Tailwind CSS (v4, via Vite)
* Alpine.js (bundled with Livewire)
* Lucide icons (`mallardduck/blade-lucide-icons`)
* ApexCharts (dashboard chart only)

### Database
* **MySQL 8.4 LTS, running in Docker** (image `mysql:8.4`; MySQL 8.0 is end-of-life since April 2026). Docker Desktop's disk image should live on drive D: (C: has little free space).
  * Only the database runs in Docker, using a minimal `docker-compose.yml` with a named volume.
  * Container name `edu_hub_mysql`. Host port **3308**: 3306 is XAMPP's and 3307 is used by another local project (`spr_laravel_db`).
  * The Laravel dev server runs on port **8010**, because 8000 is used by another local project.
  * Charset `utf8mb4` / collation `utf8mb4_unicode_ci`, which is required for Marathi and emoji.
* Laravel, the queue worker and the Playwright worker run **natively on Windows**, not in Docker. Chromium must be visible for the QR scan.

### File Storage
* Laravel Storage, on the private `local` disk (never public).

### Authentication
* Laravel authentication. A single administrator account is seeded from `.env`.
* No starter kit (Flux UI would clash with the custom design). Use a small Livewire login component.

### Local runtime constraints
The application must not depend on:
* VPS, cloud hosting or any external backend server
* Redis or any external queue service

A `start.bat` must start all of the following:
1. the Docker MySQL container
2. the Laravel server
3. the queue worker
4. the Playwright worker

`composer dev` must do the same for development.

---

## 3. ARCHITECTURE PRINCIPLE

Separate the application into two logical layers.

### Application Layer (Laravel)
Laravel handles authentication, the dashboard, groups, categories, messages, templates, media, campaigns, queue jobs, history, failed records, settings, the database, file management and logging.

### WhatsApp Automation Layer (Playwright worker)
The Playwright worker handles:
* opening Chromium (visible window)
* opening WhatsApp Web
* QR / linked-device authentication (the admin scans the QR in the visible Chromium window)
* maintaining the authenticated persistent browser profile
* finding and opening the selected group
* preparing the message, including multi-line and Devanagari text
* attaching supported media, with the message as its caption
* sending
* returning a structured success/failure result

### Flow
```
Browser UI (Livewire)
→ Laravel (Campaign)
→ Database Queue (exactly ONE worker, sequential)
→ SendToGroupJob
→ WhatsAppServiceInterface → PlaywrightWhatsAppService
→ HTTP 127.0.0.1:<port> + X-Worker-Token
→ Node Playwright worker
→ Chromium
→ WhatsApp Web
→ Selected Group
```

Do not couple the UI directly to Playwright. Define `WhatsAppServiceInterface` with these implementations:
* `PlaywrightWhatsAppService`: the real implementation.
* `FakeWhatsAppService`: used for tests and for all development before Phase 14. It never sends real messages.

---

## 4. PROJECT STRUCTURE

```
app/
├── Actions/
├── Console/
├── Enums/
├── Events/
├── Http/
├── Jobs/
├── Livewire/
│   ├── Dashboard/
│   ├── SendMessage/
│   ├── Groups/
│   ├── Categories/
│   ├── Templates/
│   ├── Media/
│   ├── History/
│   ├── FailedMessages/
│   ├── Settings/
│   └── WhatsApp/
├── Models/
├── Services/
│   ├── WhatsApp/
│   │   ├── WhatsAppServiceInterface.php
│   │   ├── PlaywrightWhatsAppService.php
│   │   ├── FakeWhatsAppService.php
│   │   ├── SendResult.php
│   │   ├── WhatsAppSessionService.php
│   │   ├── WhatsAppGroupService.php
│   │   └── WhatsAppMessageService.php
│   └── Media/
├── Support/
└── Providers/

resources/views/{layouts,components,livewire}
resources/css/

database/{migrations,seeders,factories}

playwright/
├── worker.js          (entry point: node:http server on 127.0.0.1)
├── browser/           (persistent context launch)
├── session/           (connection state handling)
├── services/
│   └── selectors.js   (ALL WhatsApp Web locators in one place)
└── scripts/           (check-status.js and other manual test scripts)

storage/app/private/media/      (private "local" disk; demo files in media/demo/)
storage/app/whatsapp-session/   (private, never web-exposed)
storage/logs/whatsapp.log

docker-compose.yml   (MySQL 8 only)
start.bat
docs/MASTER_PROMPT.md
```

---

## 5. DESIGN SYSTEM

### Overall style
Modern, clean, premium, professional SaaS dashboard with an education-technology feel.
* High information density without clutter.
* Desktop-first.
* Strong hierarchy and consistent spacing.
* Minimal decoration.

### Colors
| Token | Value |
|---|---|
| Primary Blue | #2563EB |
| Dark Navy (sidebar) | #0F172A |
| Background | #F8FAFC |
| White | #FFFFFF |
| Success | #10B981 |
| Danger | #EF4444 |
| Warning | #F59E0B |
| Border | #E2E8F0 |
| Muted text | #64748B |
| Primary text | #0F172A |

### Typography
**Poppins** throughout. Poppins includes Devanagari glyphs, so Marathi text renders correctly.
* Load the weights 400, 500, 600 and 700 with both the `latin` and `devanagari` subsets.
* Add a Devanagari fallback in the font stack: `"Poppins", "Noto Sans Devanagari", system-ui, sans-serif`.

| Level | Size / weight |
|---|---|
| Page title | 28–32px / semibold |
| Section title | 18–20px / semibold |
| Card title | 15–16px / semibold |
| Body | 14px |
| Secondary | 12–13px |

### UI
Use:
* 12–16px border radius
* subtle borders and very light shadows
* clean cards
* blue primary buttons
* compact tables
* pill status badges
* Lucide icons
* an 8px spacing system

Do not use excessive gradients, glassmorphism, Bootstrap styling, random colors or excessive animations. Use subtle transitions only.

---

## 6. GLOBAL LAYOUT

* Fixed left sidebar, about 240px wide, dark navy. Top header about 68px. Main content with comfortable padding.
* Sidebar brand: **Education Hub**, subtitle **WhatsApp Group Sender**.
* Navigation:
  1. Dashboard
  2. Send Message
  3. Group Manager
  4. Message Templates
  5. Media Library
  6. Send History
  7. Failed Messages
  8. Settings
* Bottom of the sidebar: a **Storage Usage** card (for example "2.4 GB / 10 GB · 24%") with a progress bar.
  * Usage is the real size of `storage/app/media`.
  * The quota comes from Settings.

---

## 7. TOP HEADER

* **Left:** hamburger (collapses the sidebar) and a global search box. Placeholder: "Search by group, message, error...".
* **Right:**
  * WhatsApp status badge: 🟢 WhatsApp Connected / 🔴 WhatsApp Disconnected / 🟡 Waiting for QR scan. Clicking it opens the WhatsApp Connection settings.
  * Notification bell: shows recent events (campaign completed, disconnects, failures) from the database.
  * Admin avatar, "Admin · Education Hub", dropdown (Settings, Logout).
* If a campaign is currently sending, show a small "Sending 147/250" chip that links to the progress page.

---

## 8. DASHBOARD

Title: **Dashboard**. Subtitle: "Overview of your WhatsApp education content distribution".

> All numbers in this document (257, 643, 18, 3, etc.) are **examples only**. Every value on every screen must come from real database queries. Nothing is hardcoded.

* **KPI cards:** Total Groups, Sent Today, Pending, Failed, WhatsApp Status.
* **Today's Sending Activity:** an ApexCharts chart of Sent / Failed / Pending by hour.
* **Recent Campaigns table:**
  * Columns: Campaign, Message, Groups, Sent, Failed, Status, Date, Action.
  * Statuses: Completed, Sending, Paused, Partially Failed, Failed, Cancelled.
* **Quick Actions:** Send Message, Manage Groups, Message Templates, Media Library.

---

## 9. SEND MESSAGE SCREEN (most important)

Title: **Send Message**. Subtitle: "Create and distribute educational content to selected WhatsApp groups".

Two-column workspace.

### Left — Message Composer
* **Message card:**
  * Large textarea. Placeholder: "Write your message here...".
  * It must fully support Marathi (Devanagari), English, mixed text, emoji and line breaks.
* **Character counter:** counts Unicode characters (grapheme-aware), not bytes.
* **WhatsApp formatting helper buttons:** Bold `*text*`, Italic `_text_`, Strikethrough `~text~`, Monospace ```` ```text``` ````.
* **Buttons:** Insert Template, Clear.
* **Attachment:**
  * Drag & drop area: "Drag & drop image or PDF here", or **Browse Files**, or **Choose from Media Library**.
  * Supported types: JPG, JPEG, PNG, PDF. **One attachment only.**
  * The uploaded file card shows the filename, size, a type icon and a remove button.
* **Message Preview:**
  * A WhatsApp-style bubble that renders formatting (bold, italic, etc.) and line breaks exactly as WhatsApp will.
  * If there is an attachment, the text is shown as its caption.
* If an **auto-footer** is enabled in Settings, show it in the preview.

### Right — Group Selection
* Search groups (by name or category). Category dropdown. Status filter.
* Buttons: **Select All** (selects every group matching the current filter, across all pages, not just the visible page) and **Clear**.
* Paginated group list with checkboxes. Each row shows a group icon, name, category, member count and checkbox.
* A "250 Groups Selected" counter.

### Bottom sticky action bar
* **Cancel**
* **Send Test**: sends to the configured test group only, as a single-group campaign marked as a test.
* **Review & Send**

---

## 10. REVIEW & SEND

A confirmation modal shows:
* the message preview
* the attachment (name and size)
* the selected groups count
* the categories included
* **Estimated time**, calculated as `groups × (configured delay + average send time)`. Example: 250 groups ≈ 1 h 20 min.

Buttons: **Back**, **Start Sending**.

Accidental-click protection:
* If the selection is larger than the "large selection" threshold in Settings (for example 50), the admin must type the number of groups to confirm.
* The Start Sending button disables itself after the first click.

**Only one campaign sends at a time.** If a campaign is already sending, the new one is created with the status `queued` and starts automatically when the current one finishes.

---

## 11. SENDING PROGRESS SCREEN

Title: **Sending Message**.
* **Top:** "147 / 250 · 58.8%", a progress bar and **Estimated time remaining**.
* **Stat cards:** Sent, Processing, Pending, Failed.
* **Group-level table:** Group, Members, Status (Pending / Processing / Sent / Failed / Skipped / Cancelled), Time, Error (friendly text), Action.
* **Bottom buttons:** **Pause** / **Resume** and **Cancel** (Cancel needs confirmation).
* Live updates via Livewire polling every 2 seconds. No websockets, no Redis.
* Never show low-level browser automation details to the user.

---

## 12. GROUP MANAGER

Title: **Group Manager**. Subtitle: "Manage your WhatsApp student groups".

* **Top actions:** + Add Group, Import Groups, Sync from WhatsApp (available after Phase 14), Export.
* **Filters:** Search, Category, Status, Member Count range.
* **Stats:** Total Groups, Active, Inactive.
* **Table:** Checkbox, Group Name, Category, Members, Status, Last Sent, Created, Actions (View, Edit, Activate/Deactivate, Delete).
* **Bulk actions:** change category, activate, deactivate, delete (with confirmation).

**Group names must be unique** and must match the WhatsApp group name **exactly**, because WhatsApp Web finds groups by name.
* Validate uniqueness.
* Show a clear warning next to the name field explaining this.

### CSV Import
* Columns: `name, category, member_count, status`.
* Provide a **Download sample CSV** button.
* Before saving, show a preview that highlights duplicates, unknown categories and invalid rows.
* Unknown categories can be created automatically. The admin chooses this in the preview.
* Export uses the same format.

### Sync from WhatsApp (Phase 14)
* Read the group list from the connected WhatsApp Web session.
* Show a preview: new groups, existing matches, and groups missing from WhatsApp.
* The admin chooses which ones to import.
* Member count is filled only if WhatsApp Web shows it. Otherwise it stays manual.

---

## 13. CATEGORIES (editable)

Categories are **managed by the admin**, not hardcoded.
* The `categories` table is seeded with the defaults: MPSC, Police Bharti, Combined, Free, Premium, Other.
* Manage categories from Settings → Group Settings, or from a small modal in Group Manager:
  * add, rename, reorder, set color, delete.
* A category that still has groups cannot be deleted. The admin must first move its groups to another category.
* Every category dropdown in the app (groups, templates, filters) reads from this table.

---

## 14. GROUP DETAILS

Shows:
* Group Name, Category, Members, Status, WhatsApp Group Identifier, Created Date
* Last Message, Last Successful Send
* Send statistics (total sent, total failed)
* A list of recent sends

Buttons: Edit, Activate/Deactivate, Send Message (opens Send Message with this group preselected).

Do not expose Playwright selectors.

---

## 15. MESSAGE TEMPLATES

Title: **Message Templates**. Subtitle: "Save frequently used educational messages".
* **Top:** + Create Template, Search, filters (Category, Type).
* **Cards/table:** Title, Category, Type (Text / Text + Image / Text + PDF), Last Used, Usage Count.
* **Actions:** Use (opens Send Message prefilled), Edit, Duplicate, Delete.
* **Create/Edit fields:** Title, Category, Message (Marathi supported, with preview), Default attachment (optional, chosen from the Media Library), Tags (for example `#current-affairs`, `#mpsc`, `#daily-mcq`).

---

## 16. MEDIA LIBRARY

Title: **Media Library**. Subtitle: "Manage your educational images and PDF files".
* **Top:** Upload Media, Search, filters (Images, PDFs, Recent, Most Used), grid/list toggle.
* **Each item:** preview (image thumbnail, or a PDF icon with the filename), Filename, Type, Size, Uploaded Date, Used Count.
* **Actions:** Preview, Use in Message, Rename, Delete.
  * Delete needs confirmation.
  * Delete is blocked while a queued or sending campaign still uses the file.
* **Size limits** (configurable in Settings): images **16 MB**, PDFs **100 MB** by default.
* Files are stored privately. They are served only through an authenticated route.

---

## 17. SEND HISTORY

Title: **Send History**. Subtitle: "View all previously sent campaigns".
* **KPIs:** Total Sent, Successful, Failed, Groups Reached.
* **Filters:** date range, campaign, category, status, search (campaign, message, group).
* **Table:** Date & Time, Campaign, Message, Groups, Sent, Failed, Attachment, Status (Completed / Partially Failed / Failed / In Progress / Paused / Cancelled), Actions.
* Test sends are shown with a "Test" badge and can be filtered out.
* Clicking a campaign opens its detail: message, attachment, per-group results, timeline.

---

## 18. FAILED MESSAGES

Title: **Failed Messages**. Subtitle: "View messages that failed to send, check error reasons and retry".
* **KPIs:** Total Failed, Pending Retry, Permanent Failures, Affected Groups.
* **Table:** Checkbox, Date & Time, Message, Group Name, Error Reason, Type, Status, Actions.
* **Error reasons** (friendly text) map exactly to the categories in §34.
* No automatic infinite retries.
* **Retry Selected** (bulk) and an individual **Retry**.
* **Empty state:** "🎉 No failed messages — All recent messages were sent successfully."

---

## 19. FAILED MESSAGE DETAIL DRAWER

A right-side drawer titled **Message Details**. It shows the message title, date/time and status.

**Tabs:**
* **Message**
* **Error Details:** an error card with a friendly explanation and a suggested fix. Example: "Not a member — You are not a member of this group or the group no longer exists."
* **Group Info:** Group Name, Group ID, Category, Members, Message Type, File Name, File Size, Sent At, Status.

**Retry options:**
* **Retry Now**: try sending again.
* **Retry After Fix**: use when the underlying problem has been resolved.
* **Skip This Message**: marks it `skipped` (resolved). It will not be retried.

Primary button: **Retry Message**.

---

## 20. SETTINGS

Title: **Settings**. Subtitle: "Configure your WhatsApp sender, message settings and application preferences".

A left-hand settings navigation with these sections:
1. WhatsApp Connection
2. Sending Settings
3. Message Settings
4. Media & File Settings
5. Group Settings (includes Category management)
6. Notifications
7. Appearance
8. Data & Backup

Settings are stored in the `settings` table and read through a cached `Settings` helper.

---

## 21. WHATSAPP CONNECTION SETTINGS

**When connected**, show:
* Status 🟢 Connected
* Logged in as (profile name)
* Masked phone number (if available)
* Connected Since
* Session status

Buttons: **Disconnect** (with confirmation), **Refresh Session**.

**When disconnected**, show 🔴 Disconnected and the **Connect WhatsApp** button. Clicking Connect:
1. The Playwright worker opens a **visible Chromium window** with WhatsApp Web.
2. The admin scans the QR code there with their phone (normal linked-device flow).
3. The app shows "Waiting for QR scan…" and then switches to "Connected" automatically.

Also show the worker health: "Playwright worker running / not running", with a hint to start it.

Strictly prohibited:
* QR bypass
* session hijacking
* anti-detection techniques
* any technique intended to evade WhatsApp restrictions

---

## 22. SENDING SETTINGS

* **Delay between groups** (seconds). A fixed, conservative value. Default 15 s, minimum 5 s.
* **Daily sending limit.** Default 500 group-sends per day. It is a simple safety cap: when it is reached, the campaign pauses and shows a clear message. This protects the account and is **not** an evasion mechanism.
* **Maximum groups per campaign.** Default 300.
* **Test group:** the group used by the **Send Test** button.
* Show progress during sending.
* Skip groups already sent in this campaign. Always on for resume; see §31.

Prohibited: random timing meant to evade detection, anti-ban logic, CAPTCHA bypass, detection evasion.

---

## 23. MESSAGE SETTINGS

* Default message type: Text Only / Text + Image / Text + PDF
* Default footer/signature (Marathi supported)
* Auto-add footer (on/off)
* Enable link preview (on/off)
* Show character counter (on/off)

---

## 24. MEDIA SETTINGS

* Maximum image size (default 16 MB) and maximum PDF size (default 100 MB)
* Allowed types: jpg, jpeg, png, pdf (fixed)
* Storage quota shown in the sidebar (default 10 GB)
* Compress large images (on/off)
* Generate thumbnails (on/off)
* Auto-delete temporary uploads after N days

---

## 25. GROUP SETTINGS

* Default category
* Show inactive groups in the selector
* Remember previous group selection
* Confirm-by-typing threshold for large selections (default 50)
* **Category management** (see §13)

---

## 26. NOTIFICATIONS (V1: kept simple)

Browser desktop notifications (Notification API) plus the in-app bell for:
* campaign completed
* failures in a campaign
* WhatsApp disconnected
* queue/worker stopped

Each can be turned on or off.

---

## 27. APPEARANCE (V1)

* Sidebar collapsed by default (on/off)
* Compact tables (on/off)
* **Dark mode is moved to V2** (see §49).

---

## 28. DATA & BACKUP (V1)

* **Export All Data:** a ZIP with a database dump (JSON/SQL) and all media files.
* **Clear Temporary Files.**
* **Reset Application:** requires typing `RESET` to confirm, and creates an automatic export first.
* **Restore Backup is moved to V2.**

Never perform destructive actions from a single click.

---

## 29. DATABASE DESIGN

All tables use `utf8mb4` / `utf8mb4_unicode_ci`.

| Table | Columns |
|---|---|
| **users** | id, name, email, password, timestamps |
| **categories** | id, name (unique), color nullable, sort_order, timestamps |
| **groups** | id, name (**unique**), category_id (FK), member_count nullable, whatsapp_identifier nullable (unique), status, last_sent_at nullable, timestamps |
| **message_templates** | id, title, category_id nullable (FK), message (TEXT), attachment_id nullable (FK media), tags json nullable, usage_count, last_used_at nullable, timestamps |
| **media** | id, filename, original_name, path, thumbnail_path nullable, mime_type, size, type, usage_count, timestamps |
| **campaigns** | id, title, message (TEXT), attachment_id nullable (nullOnDelete), attachment_name nullable (snapshot for history), is_test (bool), total_groups, sent_count, failed_count, pending_count, skipped_count, status, created_by (FK users), started_at, paused_at, completed_at (all nullable), timestamps |
| **campaign_groups** | id, campaign_id (cascade), group_id nullable (nullOnDelete), group_name (snapshot, so history survives deleting a group), status, attempts, error_type nullable, error_message nullable, sent_at nullable, timestamps. **Unique (campaign_id, group_id).** |
| **send_logs** | id, campaign_id (cascade), group_id nullable (nullOnDelete), group_name (snapshot), status, message nullable, error_type nullable, error_message nullable, technical_details nullable (TEXT), timestamps (**no separate `timestamp` column**) |
| **settings** | id, key (unique), value (json/text), timestamps |
| **whatsapp_sessions** | id, profile_name, status, last_connected_at, last_seen_at, metadata json nullable, timestamps |
| **app_notifications** | id, type, title, body nullable, url nullable, read_at nullable, timestamps |

Required indexes: `groups.name`, `groups.category_id`, `groups.status`, `campaigns.status`, `campaigns.created_at`, `campaign_groups.campaign_id`, `campaign_groups.group_id`, `campaign_groups.status`, `send_logs.campaign_id`, `send_logs.group_id`, `send_logs.created_at`.

---

## 30. ENUMS

* **CampaignStatus:** draft, queued, sending, **paused**, completed, partially_failed, failed, cancelled
* **SendStatus:** pending, processing, sent, failed, **skipped**, cancelled
* **GroupStatus:** active, inactive
* **MediaType:** image, pdf
* **SendErrorType:** see §34. Each case has `label()` (short friendly text) and `description()` (explanation plus suggested fix), and says whether it is retryable.

---

## 31. QUEUE ARCHITECTURE

Never send to 250 groups from one synchronous HTTP request.

* `CreateCampaignJob` creates the `campaign_groups` rows and dispatches one `SendToGroupJob` per group on the `whatsapp` queue.
* Exactly **one** queue worker processes the `whatsapp` queue, so sends happen one at a time. There is a single browser profile.
* `SendToGroupJob`:
  * at most **3 attempts**, with conservative backoff
  * a fixed configurable delay between groups
  * records the error on final failure

### Rules

**Idempotency / duplicate protection**
* Before sending, the job re-checks the `campaign_groups` row. If it is already `sent` or `skipped`, the job exits without sending.
* The row is marked `processing` before the send and `sent` immediately after a success result.

**Resume after crash or restart**
* If the PC, the worker or the app restarts mid-campaign, the campaign continues from where it stopped.
* On startup, rows stuck in `processing` for more than N minutes are handled like this:
  * They are **not** automatically re-sent.
  * They are marked `failed` with error type `UNCONFIRMED` ("Delivery unconfirmed — may have been sent") and appear in Failed Messages. The admin decides whether to Retry or Skip.

**Pause / Cancel**
* Jobs check the campaign status before sending.
  * A paused campaign stops dispatching and resumes on **Resume**.
  * A cancelled campaign marks its remaining rows `cancelled`.

**Only one campaign at a time**
* A new campaign waits as `queued` until the current one finishes.

**Disconnect handling**
* A `WHATSAPP_DISCONNECTED` result **pauses the campaign** immediately, without using up retries, and notifies the admin.

**Daily limit**
* When the limit is reached, the campaign pauses with a clear message.

**Counters**
* Campaign counters update atomically.
* The final status (completed / partially_failed / failed) is calculated when the last job finishes.

---

## 32. PLAYWRIGHT WORKER

A separate long-running Node process (`playwright/worker.js`).

1. Launch Chromium **visibly** with a persistent context at `storage/app/whatsapp-session/`.
2. Open WhatsApp Web.
3. Detect the state: STARTING, WAITING_FOR_QR, CONNECTED or DISCONNECTED.
4. For QR login, the admin scans in the visible window (normal linked-device flow).
5. Push state changes to Laravel at `POST /internal/whatsapp/state` (token-protected, CSRF-exempt, local only).
6. Expose these endpoints on `127.0.0.1` only. Every request needs the `X-Worker-Token` header.
   * `GET /status`
   * `POST /connect`, `POST /disconnect`, `POST /restart`
   * `POST /send` with `{ group, message, attachment_path }`
   * `GET /groups` (for Sync from WhatsApp)
7. Accept only **one send at a time**. A concurrent request is rejected with a "busy" response.
8. **Sending a message:**
   * Search for the group by its exact name. Verify that the opened chat title matches exactly before sending.
   * Put the message text in via paste/insert rather than per-key typing, so Devanagari, emoji and line breaks are preserved. Line breaks use Shift+Enter semantics; Enter alone sends.
   * With an attachment: upload the file and put the message text in the caption field.
   * Confirm the send by waiting for the message to appear as sent (at least the single tick), up to a timeout.
9. Return structured JSON:
   * Success: `{ "success": true, "group": "…", "timestamp": "…" }`
   * Failure: `{ "success": false, "group": "…", "error_type": "…", "error_message": "…" }`

**Selector abstraction:** all WhatsApp Web locators live in `playwright/services/selectors.js`. Prefer role, aria-label and `data-*` locators over CSS classes, so that WhatsApp Web UI changes are fixed in one place.

Prohibited: any mechanism to bypass WhatsApp security, CAPTCHA, anti-abuse systems, rate limits or detection.

---

## 33. LOCAL SESSION MANAGEMENT

* A persistent browser profile in `storage/app/whatsapp-session/`.
* Never store passwords. Never expose session files through any route. Keep them out of git.
* The admin can connect, disconnect, restart the session and check its status.

---

## 34. ERROR HANDLING

| Error type | Friendly message | Retryable |
|---|---|---|
| `WHATSAPP_DISCONNECTED` | WhatsApp is not connected. | Pauses campaign |
| `WORKER_UNAVAILABLE` | The sending service is not running. | Pauses campaign |
| `GROUP_NOT_FOUND` | Group not found. Check that the name matches WhatsApp exactly. | No |
| `NOT_MEMBER` | You are not a member of this group, or it no longer exists. | No |
| `ONLY_ADMINS_CAN_SEND` | Only admins can send messages in this group. | No |
| `MEDIA_UPLOAD_FAILED` | The attachment could not be uploaded. | Yes |
| `MESSAGE_SEND_FAILED` | The message could not be sent. | Yes |
| `BROWSER_ERROR` | A browser error occurred. | Yes |
| `TIMEOUT` | WhatsApp took too long to respond. | Yes |
| `DAILY_LIMIT_REACHED` | Daily sending limit reached. | Pauses campaign |
| `UNCONFIRMED` | Sending was interrupted; it is not known whether the group received the message. | No (admin decides) |
| `UNKNOWN_ERROR` | An unknown error occurred. | Yes |

* The "Group muted" error is removed: muted groups can still receive messages.
* Every error record stores the friendly message, technical details, campaign ID, group ID and timestamp.
* Users see friendly text. Technical details go to the logs and to a collapsible "Technical details" section.

---

## 35. LOGGING

A dedicated `whatsapp` log channel writes to `storage/logs/whatsapp.log`.

Log:
* session start, connected, disconnected
* campaign started, paused, resumed, completed
* job started, completed, failed
* browser errors
* worker start and stop

Never log passwords, authentication secrets, session data or the worker token.

---

## 36. SECURITY

* Protected admin login with throttling.
* Validate uploads by both MIME type and extension, and enforce size limits.
* Store files under random filenames to prevent path traversal.
* CSRF protection on all forms. Escape all rendered content, including message previews.
* Validate all form input. Use authorization policies.
* The session directory and media are never publicly accessible.
* The worker listens on 127.0.0.1 only and requires the token.
* Never build shell commands from user input.

---

## 37. UX REQUIREMENTS

* Every action gives visual feedback: loading states on buttons ("Sending..."), and toasts for success ("Message sent successfully") and errors ("Unable to send to MPSC Batch 04").
* Use skeletons or spinners while loading.
* Every list has a designed empty state.
* Destructive actions always need confirmation.

---

## 38. RESPONSIVE DESIGN

* **Primary:** desktop, at 1440, 1280 and 1024px.
* **Supported:** tablet.
* **Mobile:** usable but not a priority. The sidebar becomes a drawer and tables scroll horizontally.
* Never break the information hierarchy.

---

## 39. COMPONENT SYSTEM

Build reusable Blade/Livewire components and do not duplicate UI code:
AppSidebar, TopHeader, PageHeader, StatCard, StatusBadge, PrimaryButton, SecondaryButton, DangerButton, SearchInput, FilterDropdown, DataTable, EmptyState, FileUpload, FilePreview, GroupSelector, MessageComposer, MessagePreview, ConfirmModal, Drawer, ProgressBar, Toast, Pagination, WhatsAppStatusBadge.

---

## 40. ICONS (Lucide)

| Use | Icon |
|---|---|
| Dashboard | LayoutDashboard |
| Send | Send |
| Groups | Users |
| Templates | FileText |
| Media | Image |
| History | Clock3 |
| Failed | TriangleAlert |
| Settings | Settings |
| WhatsApp | MessageCircle |
| Search | Search |
| Upload | Upload |
| Retry | RefreshCw |
| Delete | Trash2 |
| Edit | Pencil |

---

## 41. SAMPLE SEED DATA

Seed data must make every screen look complete right after installation:
* the default categories
* **at least 30 groups** (MPSC Batch 01–10, Police Batch 01–06, Combined Batch 01–05, Free Batch 01–05, Premium Batch 01–04, …)
* templates, **including Marathi examples** (for example "आजचे चालू घडामोडी")
* sample media records
* campaigns with mixed statuses, failed messages and send history
* one test group

---

## 42. SEARCH

* **Global search:** groups, campaign names, message text, templates, error messages. Works with Marathi text.
* **Group search:** name and category.
* **History search:** campaign, message, group.

---

## 43. FILTERING

Every table has working filters that change the database query. Use Livewire for reactive filtering. No decorative filters.

---

## 44. PAGINATION

Database pagination everywhere. Default 25 rows. Options 25 / 50 / 100. The group selector is paginated too, but "Select All" works across the whole filtered set.

---

## 45. PERFORMANCE

The app must stay fast with 250+ groups, thousands of send logs, hundreds of campaigns and hundreds of media files. Use the indexes from §29, eager loading (no N+1 queries) and database pagination.

---

## 46. DO NOT OVERENGINEER V1

Do NOT add AI content generation, CRM, student management, payments, an analytics platform, multi-tenancy, subscriptions, complex roles, cloud infrastructure or unnecessary APIs.

V1 is strictly: Message + Image/PDF + Groups + Categories + WhatsApp Web + Test Send + Send + Progress + History + Failed + Templates + Media + Settings.

---

## 47. DEVELOPMENT ORDER

After each phase: **implement → test → report changes → STOP**, and wait for approval. Never continue automatically.

1. Environment + Laravel installation. Docker MySQL 8, PHP extensions, Composer, Livewire, Tailwind, Poppins, Lucide, git.
2. Database migrations, models, enums, factories, seeders.
3. Authentication.
4. App shell + Dashboard.
5. Group Manager + Categories + CSV import/export.
6. Message Templates.
7. Media Library.
8. Send Message screen (composer, preview, group selector, Review & Send, Send Test UI).
9. Campaign/Queue system, using `FakeWhatsAppService` (pause/resume/cancel, resume-after-crash, daily limit).
10. Send History.
11. Failed Messages.
12. Settings.
13. WhatsApp session manager (Laravel side).
14. Playwright worker + integration, then Sync from WhatsApp.
15. End-to-end testing. Start with a test group, then 2–3 groups. **Never start with 250.**

Do not start Playwright work before the application layer is stable.

---

## 48. TESTING

Feature tests for:
* authentication
* group CRUD and unique names
* categories (including the delete protection)
* CSV import
* template CRUD
* media upload validation
* campaign creation and validation
* job creation
* send logs
* retry, skip, pause, resume and cancel
* duplicate-send protection
* resume after crash
* daily limit
* one campaign at a time
* filters and pagination
* settings
* Marathi text round-trip (save, preview, send payload)

Playwright:
* Verify the worker separately first (`node playwright/scripts/check-status.js`).
* Then send to the test group, then to 2–3 groups.

---

## 49. FUTURE (V2) — do not build in V1

* **Scheduled sending** (for example Current Affairs automatically every day at 7:00 AM)
* Dark mode
* Restore from backup
* Automatic member-count refresh from WhatsApp
* Multiple attachments per message

---

## 50. ACCEPTANCE CRITERIA

1. The admin can log in.
2. The dashboard shows real database statistics.
3. Groups can be added, edited, deleted and categorized. Names are unique.
4. Categories can be managed by the admin.
5. Groups can be imported from and exported to CSV.
6. Templates can be created and used, including Marathi text.
7. Images and PDFs can be uploaded.
8. A message can be composed with Marathi, emoji and line breaks, and previewed WhatsApp-style.
9. Groups can be selected, including select-all across the filtered set.
10. Send Test works with the test group.
11. A campaign can be created with review, ETA and large-selection confirmation.
12. Creating a campaign creates individual group jobs.
13. Progress is visible live, and Pause, Resume and Cancel work.
14. Successful and failed sends are recorded.
15. No group receives the same campaign twice, including after a crash or restart.
16. Failed messages can be retried or skipped.
17. Send history is searchable and filterable.
18. The Media Library works.
19. Settings work, including the delay, daily limit and test group.
20. WhatsApp connection state is visible in the header and in Settings.
21. WhatsApp connects through the normal linked-device QR flow in a visible Chromium window.
22. The Playwright worker communicates with Laravel over 127.0.0.1 with a token.
23. Marathi text arrives in WhatsApp exactly as composed.
24. Errors are logged correctly and shown in friendly language.
25. The whole app starts with `start.bat` on a local Windows PC. No WhatsApp API is required.

---

## 51. VISUAL QUALITY

The design must include:
* a dark navy sidebar, white workspace and blue primary actions
* Poppins typography
* rounded cards, subtle shadows and clean borders
* a green connection indicator, red failed states and yellow warning states
* purple/blue secondary icons
* compact tables, right-side drawers and clean modals

It must feel like a polished internal SaaS product, not a basic CRUD app.

---

## 52. FINAL IMPLEMENTATION RULES

1. Inspect the project before writing code. Do not overwrite working code unnecessarily.
2. Build incrementally, phase by phase, and stop after each phase for approval.
3. Run migrations and tests, and fix errors.
4. Verify every screen and its responsive behavior.
5. Verify the local installation on Windows.
6. Verify the Playwright connection separately from the Laravel UI.
7. Do not install unnecessary packages.

Priority order for decisions: **Simplicity → Reliability → Maintainability → Performance**.

END OF MASTER PROMPT
