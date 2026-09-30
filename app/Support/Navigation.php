<?php

namespace App\Support;

/** Sidebar navigation (docs/MASTER_PROMPT.md §6). */
class Navigation
{
    /**
     * "match" (optional): route patterns that also highlight the item, e.g. campaign details under Send History.
     *
     * @return array<int, array{label: string, route: string, icon: string, match?: array<int, string>}>
     */
    public static function items(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'layout-dashboard'],
            ['label' => 'Send Message', 'route' => 'send.create', 'icon' => 'send'],
            ['label' => 'Group Manager', 'route' => 'groups.index', 'icon' => 'users'],
            ['label' => 'Message Templates', 'route' => 'templates.index', 'icon' => 'file-text'],
            ['label' => 'Media Library', 'route' => 'media.index', 'icon' => 'image'],
            ['label' => 'Send History', 'route' => 'history.index', 'icon' => 'clock-3', 'match' => ['history.*', 'campaigns.*']],
            ['label' => 'Failed Messages', 'route' => 'failed.index', 'icon' => 'triangle-alert'],
            ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'settings'],
        ];
    }
}
