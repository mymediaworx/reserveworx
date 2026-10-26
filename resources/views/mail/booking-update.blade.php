<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $subject }}</title>
</head>
<body>
    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach
    <p>{{ $businessName }}</p>
</body>
</html>
