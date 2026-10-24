{{-- Workspace colors as CSS variables. BrandPalette only emits validated hex values. --}}
@php
    $brandPalette = \App\Support\BrandPalette::from(
        isset($workspaceBranding) ? $workspaceBranding->primaryColor : null,
        isset($workspaceBranding) ? $workspaceBranding->accentColor : null,
    );
@endphp
<style>{!! $brandPalette->css() !!}</style>
<meta name="theme-color" content="#FFFFFF">
