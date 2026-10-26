<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Your booking is confirmed</title>
</head>
<body>
    <p>Your booking for {{ $serviceName }} is confirmed.</p>
    <p>Show this code when you arrive.</p>
    <div>{!! $checkInSvg !!}</div>
    <p style="overflow-wrap:anywhere">Show this link to staff if the code does not appear: {{ $checkInUrl }}</p>
    @if (is_string($waiverUrl ?? null))
        <p><strong>Please sign your waiver before you arrive:</strong> <a href="{{ $waiverUrl }}">Sign your waiver</a></p>
    @endif
</body>
</html>
