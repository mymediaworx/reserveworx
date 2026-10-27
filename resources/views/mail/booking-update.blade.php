<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:16px;max-width:100%;">
    @foreach ($lines as $line)
        <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">{{ $line }}</p>
    @endforeach
    <p style="margin:0;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">{{ $businessName }}</p>
</body>
</html>
