<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Book')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Same palette as the staff UI (resources/ui/app.css): lake ink, water teal, buoy orange for warnings. */
        :root { color-scheme: light; --ink: #12333b; --ink-soft: #3d5a61; --muted: #5f7579; --water: #0e7c86; --water-deep: #0a5f67; --water-wash: #e3f1f2; --line: #d5dfe0; --line-strong: #b9c8ca; --page: #f3f6f6; --danger: #b42318; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Source Sans 3", system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 1.05rem; line-height: 1.5; color: var(--ink); background: var(--page); font-variant-numeric: tabular-nums; }
        main { max-width: 38rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        h1, h2 { font-family: "Bricolage Grotesque", "Source Sans 3", system-ui, sans-serif; line-height: 1.15; margin: 0 0 0.5rem; }
        h1 { font-size: 2rem; letter-spacing: -0.01em; }
        h2 { font-size: 1.3rem; }
        p { margin: 0 0 0.75rem; }
        a { color: var(--water-deep); }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid var(--water); outline-offset: 2px; }
        form { display: grid; gap: 1rem; background: #fff; padding: 1.25rem; border: 1px solid var(--line); border-radius: 10px; }
        label { display: grid; gap: 0.3rem; font-weight: 600; font-size: 0.95rem; color: var(--ink-soft); }
        input, select { font: inherit; font-size: 1rem; color: var(--ink); padding: 0.6rem 0.7rem; min-height: 2.75rem; border: 1px solid var(--line-strong); border-radius: 6px; background: #fff; width: 100%; }
        fieldset { border: 0; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
        legend { font-weight: 600; font-size: 0.95rem; color: var(--ink-soft); margin-bottom: 0.4rem; }
        fieldset label { display: flex; align-items: center; gap: 0.6rem; font-weight: 600; color: var(--ink); border: 1px solid var(--line-strong); border-radius: 6px; padding: 0.6rem 0.75rem; cursor: pointer; }
        fieldset label:has(input:checked) { border-color: var(--water); background: var(--water-wash); }
        fieldset input[type="radio"] { width: 1.1rem; height: 1.1rem; min-height: 0; margin: 0; accent-color: var(--water); flex: none; }
        button { font: inherit; font-weight: 700; padding: 0.75rem 1.1rem; min-height: 2.9rem; background: var(--water); color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
        button:hover { background: var(--water-deep); }
        .error { background: #fde8e7; color: var(--danger); border-radius: 6px; padding: 0.75rem 1rem; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 1.1rem 1.25rem; margin: 0 0 0.75rem; display: grid; grid-template-columns: 1fr auto; gap: 0.25rem 1rem; align-items: center; }
        .card h2 { margin: 0; grid-column: 1; }
        .card .price { grid-column: 1; margin: 0; }
        .card p:last-child { grid-column: 2; grid-row: 1 / span 2; margin: 0; }
        .card p:last-child a { display: inline-block; text-decoration: none; background: var(--water); color: #fff; font-weight: 700; padding: 0.6rem 1rem; border-radius: 6px; }
        .card p:last-child a:hover { background: var(--water-deep); }
        .price { font-weight: 700; font-size: 1.15rem; }
        .price span { font-weight: 400; font-size: 0.9rem; color: var(--muted); margin-left: 0.25rem; }
        dl { margin: 0; }
        .line { display: flex; justify-content: space-between; gap: 1rem; padding: 0.4rem 0; border-top: 1px solid var(--line); }
        .line:first-child { border-top: 0; }
        .line dt { color: var(--ink-soft); }
        .line dd { margin: 0; font-weight: 600; }
        .line.total { border-top: 2px solid var(--ink); font-size: 1.2rem; }
        .booking-fee-refund-terms { margin-top: 1rem; padding: 0.9rem 1rem; background: #eef1f1; border-radius: 6px; font-size: 0.92rem; }
        .booking-fee-refund-terms h3 { margin: 0 0 0.4rem; font-size: 1rem; }
        .booking-fee-refund-terms ul { margin: 0; padding-left: 1.1rem; display: grid; gap: 0.3rem; }
        .disclosure-version { color: var(--muted); margin: 0.5rem 0 0; }
        @media (max-width: 480px) {
            .card { grid-template-columns: 1fr; }
            .card p:last-child { grid-column: 1; grid-row: auto; margin-top: 0.5rem; }
        }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
