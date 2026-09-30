<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrator account
    |--------------------------------------------------------------------------
    |
    | The single admin account is created by AdminUserSeeder from these values.
    | Set them in .env; the password is never stored anywhere else in plain text.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@educationhub.local'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media storage quota (GB)
    |--------------------------------------------------------------------------
    |
    | Shown in the sidebar Storage Usage card. Becomes editable in Settings.
    |
    */

    'storage_quota_gb' => (int) env('STORAGE_QUOTA_GB', 10),

    /*
    |--------------------------------------------------------------------------
    | Media uploads
    |--------------------------------------------------------------------------
    |
    | Allowed types are fixed (docs/MASTER_PROMPT.md §24). Limits and image
    | options become editable in Settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sending (docs/MASTER_PROMPT.md §22) — editable in Settings (Phase 12)
    |--------------------------------------------------------------------------
    */

    'sending' => [
        // Fixed, conservative pause between two groups. No randomisation.
        'delay_seconds' => (int) env('SEND_DELAY_SECONDS', 15),
        // Typical time WhatsApp Web needs to open a group and send (used for estimates only).
        'average_send_seconds' => 8,
        'max_groups_per_campaign' => 300,
        'daily_limit' => 500,
        // Above this many groups the admin must type the number to confirm.
        'large_selection_threshold' => 50,
        // Group used by "Send Test". Falls back to a group named "Education Hub Test Group".
        'test_group_id' => env('TEST_GROUP_ID'),
        // After "Start Sending", open the live progress screen (otherwise Send History).
        'show_progress' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp automation layer
    |--------------------------------------------------------------------------
    |
    | "fake" pretends to send (nothing leaves this computer). "playwright" uses
    | the local Chromium worker (Phase 14).
    |
    */

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'fake'),
        // Automatic tries for temporary problems (timeouts, upload errors) before a group is marked failed.
        'max_attempts' => 3,
        // Wait before retrying a temporary problem, in seconds.
        'retry_backoff' => [30, 120],
        // A group left "processing" this long (e.g. the PC restarted mid-send) is marked "Delivery unconfirmed".
        'stuck_after_minutes' => 5,
        'fake' => [
            // Simulated send time so progress can be watched during development.
            'delay_ms' => (int) env('WHATSAPP_FAKE_DELAY_MS', 1500),
            // Group name => error code, to rehearse failures, e.g. ['Police Batch 04' => 'NOT_MEMBER'].
            'failures' => [],
        ],
    ],

    'message' => [
        // text | image | pdf — which files "Choose from Media Library" shows first.
        'default_type' => 'text',
        'footer' => null,
        'auto_add_footer' => false,
        // Passed to the WhatsApp worker (Phase 14): let WhatsApp show link previews.
        'link_preview' => true,
        'show_counter' => true,
    ],

    'groups' => [
        // Category for new groups and CSV rows without one (null = "Other" / first category).
        'default_category_id' => null,
        'show_inactive_in_selector' => true,
        'remember_selection' => false,
        // Set automatically when "Remember previous group selection" is on.
        'last_selection' => [],
        // contains | starts_with
        'search_mode' => 'contains',
    ],

    'notifications' => [
        // Browser desktop notifications (the in-app bell always shows everything).
        'desktop' => false,
        'campaign_completed' => true,
        'campaign_failures' => true,
        'whatsapp_disconnected' => true,
        'queue_stopped' => true,
    ],

    'appearance' => [
        'sidebar_collapsed' => false,
        'compact_tables' => false,
    ],

    'media' => [
        'max_image_mb' => 16,
        'max_pdf_mb' => 100,
        'max_files_per_upload' => 10,
        'thumbnail_size' => 320,
        // Re-encode very large photos (longest side > 2560px) to save space before sending.
        'compress_images' => false,
        'generate_thumbnails' => true,
        // Unfinished uploads (livewire-tmp) older than this are removed by "Clear Temporary Files".
        'temp_retention_days' => 1,
    ],

];
