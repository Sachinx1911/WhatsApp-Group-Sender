{{-- Temporary page for screens built in later phases (see App\Support\Navigation). --}}
<x-layouts::app :title="$item['label']">
    <x-ui.page-header :title="$item['label']" :subtitle="$item['description']" />

    <x-ui.card>
        <x-ui.empty-state :icon="$item['icon']" :title="$item['label'].' is coming in Phase '.$item['phase']">
            This screen is part of the step-by-step build and will be added in Phase {{ $item['phase'] }}.
            <x-slot:actions>
                <x-ui.button :href="route('dashboard')" variant="secondary" icon="layout-dashboard">Back to Dashboard</x-ui.button>
            </x-slot:actions>
        </x-ui.empty-state>
    </x-ui.card>
</x-layouts::app>
