<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['compact-tables' => config('educationhub.appearance.compact_tables')])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <script>
        // Apply the sidebar state before first paint to avoid a layout jump: the ☰ choice saved in this
        // browser wins, otherwise Settings → Appearance → Sidebar collapsed by default.
        try {
            const saved = localStorage.getItem('sidebar-collapsed');
            if (saved === null ? @js((bool) config('educationhub.appearance.sidebar_collapsed')) : saved === '1') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <x-layout.sidebar />

    <div class="transition-[padding] duration-200 lg:pl-60 lg:collapsed:pl-[76px]">
        <x-layout.header />

        <main class="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            {{ $slot }}
        </main>
    </div>

    <x-ui.toasts />
</body>
</html>
