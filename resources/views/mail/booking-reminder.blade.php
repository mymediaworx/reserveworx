<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking reminder</title>
</head>
<body style="margin:0;padding:16px;max-width:100%;">
    <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">Your booking for {{ $serviceName }} is coming up.</p>
    <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">{{ $when }}</p>
    <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">Booking reference {{ $reference }}</p>
    <p style="margin:0;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">This reminder is {{ $ahead }} before the start.</p>
</body>
</html>
