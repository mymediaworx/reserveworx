<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Book')</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: 1.05rem; line-height: 1.5; color: #142033; background: #f4f7fb; }
        main { max-width: 36rem; margin: 0 auto; padding: 1.25rem; }
        h1 { font-size: 1.6rem; line-height: 1.25; margin: 0 0 0.5rem; }
        a { color: #0b3d91; }
        a:focus, button:focus, input:focus, select:focus { outline: 3px solid #0b3d91; outline-offset: 2px; }
        form { display: grid; gap: 0.85rem; background: #fff; padding: 1rem; border: 1px solid #c5d0e0; }
        label { display: grid; gap: 0.25rem; font-weight: 700; }
        input, select { font: inherit; padding: 0.55rem; border: 1px solid #5c6b82; background: #fff; color: #142033; }
        button { font: inherit; padding: 0.7rem 1rem; background: #0b3d91; color: #fff; border: 0; cursor: pointer; }
        .error { background: #fff4f4; color: #6d1212; border: 1px solid #6d1212; padding: 0.75rem; }
        .card { background: #fff; border: 1px solid #c5d0e0; padding: 1rem; margin: 0 0 0.75rem; }
        .price { font-weight: 700; }
        dl { margin: 0; }
        .line { display: flex; justify-content: space-between; gap: 1rem; padding: 0.25rem 0; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
