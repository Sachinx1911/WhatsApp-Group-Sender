<?php

namespace App\Support;

/**
 * Sidebar navigation (docs/MASTER_PROMPT.md §6). Single source for the sidebar
 * and for the placeholder pages of screens built in later phases.
 */
class Navigation
{
    /** @return array<int, array{label: string, route: string, icon: string, phase: int, description: string}> */
    public static function items(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'layout-dashboard', 'phase' => 4,
                'description' => 'Overview of your WhatsApp education content distribution'],
            ['label' => 'Send Message', 'route' => 'send.create', 'icon' => 'send', 'phase' => 8,
                'description' => 'Create and distribute educational content to selected WhatsApp groups'],
            ['label' => 'Group Manager', 'route' => 'groups.index', 'icon' => 'users', 'phase' => 5,
                'description' => 'Manage your WhatsApp student groups'],
            ['label' => 'Message Templates', 'route' => 'templates.index', 'icon' => 'file-text', 'phase' => 6,
                'description' => 'Save frequently used educational messages'],
            ['label' => 'Media Library', 'route' => 'media.index', 'icon' => 'image', 'phase' => 7,
                'description' => 'Manage your educational images and PDF files'],
            ['label' => 'Send History', 'route' => 'history.index', 'icon' => 'clock-3', 'phase' => 10,
                'description' => 'View all previously sent campaigns'],
            ['label' => 'Failed Messages', 'route' => 'failed.index', 'icon' => 'triangle-alert', 'phase' => 11,
                'description' => 'View messages that failed to send, check error reasons and retry'],
            ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'settings', 'phase' => 12,
                'description' => 'Configure your WhatsApp sender, message settings and application preferences'],
        ];
    }

    /** @return array{label: string, route: string, icon: string, phase: int, description: string} */
    public static function find(string $route): array
    {
        return collect(self::items())->firstWhere('route', $route);
    }
}
