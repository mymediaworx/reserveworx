@php
    $widgetBrandName = isset($workspaceBranding) && $workspaceBranding->displayName !== '' ? $workspaceBranding->displayName : null;
    $widgetLogo = isset($workspaceBranding) ? $workspaceBranding->logoUrl : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title', 'Book')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @include('layouts.partials.brand-style')
    <style>
        /* Same look as the staff app (resources/ui/app.css). Colors come from the workspace's branding. */
        :root { color-scheme: light; --ink: #1F2937; --ink-soft: #374151; --muted: #6B7280; --line: #E5E7EB; --line-strong: #D1D5DB; --page: #F3F4F6; --danger: #B91C1C; --shadow: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.06); }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 1rem; line-height: 1.5; color: var(--ink); background: var(--page); font-variant-numeric: tabular-nums; -webkit-font-smoothing: antialiased; }
        .w-bar { display: flex; align-items: center; gap: .75rem; padding: .85rem 1rem; background: #fff; border-bottom: 1px solid var(--line); }
        .w-avatar { display: inline-grid; place-items: center; width: 2.5rem; height: 2.5rem; border-radius: 50%; overflow: hidden; background: var(--brand); color: var(--brand-ink); font-weight: 700; flex: none; }
        .w-avatar img { width: 100%; height: 100%; object-fit: cover; background: #fff; }
        .w-name { font-weight: 600; font-size: 1.1rem; }
        .w-hero { display: flex; align-items: center; gap: .9rem; margin: .5rem 0 1rem; }
        .w-hero h1 { margin: 0; }
        .w-avatar-lg { width: 3.5rem; height: 3.5rem; font-size: 1.2rem; }
        main { max-width: 38rem; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
        h1, h2 { line-height: 1.2; margin: 0 0 .5rem; letter-spacing: -.01em; }
        h1 { font-size: 1.5rem; font-weight: 600; }
        h2 { font-size: 1.1rem; font-weight: 600; }
        p { margin: 0 0 .75rem; }
        a { color: var(--brand-text); }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }
        form { display: grid; gap: 1rem; background: #fff; padding: 1.25rem; border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow); margin-bottom: .75rem; }
        label { display: grid; gap: .35rem; font-weight: 500; font-size: .9rem; color: var(--ink-soft); }
        input, select { font: inherit; font-size: 1rem; color: var(--ink); padding: .6rem .75rem; min-height: 2.875rem; border: 1px solid var(--line-strong); border-radius: 10px; background: #fff; width: 100%; }
        input:focus, select:focus { border-color: var(--brand); outline: 3px solid var(--brand-soft); outline-offset: 0; }
        fieldset { border: 0; margin: 0; padding: 0; display: grid; gap: .5rem; }
        legend { font-weight: 500; font-size: .9rem; color: var(--ink-soft); margin-bottom: .4rem; }
        fieldset label { display: flex; align-items: center; gap: .6rem; font-weight: 500; color: var(--ink); border: 1px solid var(--line-strong); border-radius: 10px; padding: .65rem .8rem; cursor: pointer; }
        fieldset label:has(input:checked) { border-color: var(--brand); background: var(--brand-soft); color: var(--brand-soft-ink); }
        fieldset input[type="radio"] { width: 1.15rem; height: 1.15rem; min-height: 0; margin: 0; accent-color: var(--brand); flex: none; }
        button { font: inherit; font-weight: 600; padding: .75rem 1.1rem; min-height: 3rem; background: var(--brand); color: var(--brand-ink); border: 0; border-radius: 10px; cursor: pointer; }
        button:hover { background: var(--brand-strong); }
        .error { background: #FEE2E2; color: var(--danger); border-radius: 10px; padding: .75rem 1rem; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow); padding: 1rem 1.1rem; margin: 0 0 .75rem; display: grid; grid-template-columns: 1fr auto; gap: .2rem 1rem; align-items: center; }
        .card h2 { margin: 0; grid-column: 1; }
        .card .price { grid-column: 1; margin: 0; }
        .card p:last-child { grid-column: 2; grid-row: 1 / span 2; margin: 0; }
        .card p:last-child a { display: inline-block; text-decoration: none; background: var(--brand); color: var(--brand-ink); font-weight: 600; padding: .65rem 1.05rem; border-radius: 10px; }
        .card p:last-child a:hover { background: var(--brand-strong); }
        .price { font-weight: 600; font-size: 1.05rem; }
        .price span { font-weight: 400; font-size: .85rem; color: var(--muted); margin-left: .25rem; }
        dl { margin: 0; }
        .line { display: flex; justify-content: space-between; gap: 1rem; padding: .5rem 0; border-top: 1px solid var(--line); }
        .line:first-child { border-top: 0; }
        .line dt { color: var(--ink-soft); }
        .line dd { margin: 0; font-weight: 600; }
        .line.total { border-top: 2px solid var(--ink); font-size: 1.15rem; }
        .booking-fee-refund-terms { margin: 1rem 0; padding: .9rem 1rem; background: #fff; border: 1px solid var(--line); border-radius: 12px; font-size: .875rem; }
        .booking-fee-refund-terms h3 { margin: 0 0 .4rem; font-size: .95rem; }
        .booking-fee-refund-terms ul { margin: 0; padding-left: 1.1rem; display: grid; gap: .3rem; }
        .disclosure-version { color: var(--muted); margin: .5rem 0 0; }
        .w-support { margin-top: 2rem; font-size: .875rem; color: var(--muted); }
        @media (max-width: 480px) {
            .card { grid-template-columns: 1fr; }
            .card p:last-child { grid-column: 1; grid-row: auto; margin-top: .5rem; }
        }
    </style>
</head>
<body>
@if ($widgetBrandName && ! View::hasSection('hide_bar'))
    <header class="w-bar">
        <span class="w-avatar">
            @if ($widgetLogo)
                <img src="{{ $widgetLogo }}" alt="">
            @else
                @php $parts = preg_split('/\s+/', trim($widgetBrandName)) ?: []; @endphp
                <span aria-hidden="true">{{ strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1)) }}</span>
            @endif
        </span>
        <span class="w-name">{{ $widgetBrandName }}</span>
    </header>
@endif
<main>
    @yield('content')
    @if (isset($workspaceBranding) && ($workspaceBranding->supportEmail || $workspaceBranding->supportPhone))
        <p class="w-support">
            Questions?
            @if ($workspaceBranding->supportPhone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $workspaceBranding->supportPhone) }}">{{ $workspaceBranding->supportPhone }}</a>@endif
            @if ($workspaceBranding->supportEmail && $workspaceBranding->supportPhone) or @endif
            @if ($workspaceBranding->supportEmail)<a href="mailto:{{ $workspaceBranding->supportEmail }}">{{ $workspaceBranding->supportEmail }}</a>@endif
        </p>
    @endif
</main>
<script>
    /* Embedded on another site: report this page's height so embed.js can size the frame. */
    (function () {
        if (window.parent === window) { return; }
        var last = 0;
        function send() {
            var height = document.documentElement.scrollHeight;
            if (Math.abs(height - last) < 2) { return; }
            last = height;
            window.parent.postMessage({ type: 'reserveworx:height', height: height }, '*');
        }
        window.addEventListener('load', send);
        if ('ResizeObserver' in window) { new ResizeObserver(send).observe(document.body); }
        send();
    })();
</script>
</body>
</html>
