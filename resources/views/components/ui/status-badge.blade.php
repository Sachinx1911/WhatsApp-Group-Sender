{{-- Renders any status enum that provides label() and color(). --}}
@props(['status'])

<x-ui.badge :color="$status->color()" dot {{ $attributes }}>{{ $status->label() }}</x-ui.badge>
