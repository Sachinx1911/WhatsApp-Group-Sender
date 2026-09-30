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
        'delay_seconds' => 15,
        // Typical time WhatsApp Web needs to open a group and send (used for estimates only).
        'average_send_seconds' => 8,
        'max_groups_per_campaign' => 300,
        'daily_limit' => 500,
        // Above this many groups the admin must type the number to confirm.
        'large_selection_threshold' => 50,
        // Group used by "Send Test". Falls back to a group named "Education Hub Test Group".
        'test_group_id' => env('TEST_GROUP_ID'),
    ],

    'message' => [
        'footer' => null,
        'auto_add_footer' => false,
    ],

    'media' => [
        'max_image_mb' => 16,
        'max_pdf_mb' => 100,
        'max_files_per_upload' => 10,
        'thumbnail_size' => 320,
        // Re-encode very large photos (longest side > 2560px) to save space before sending.
        'compress_images' => false,
    ],

];
