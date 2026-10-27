Your booking for {{ $serviceName }} is confirmed.
{{ $when }}
Booking reference {{ $reference }}

Show this link to staff when you arrive:
{{ $checkInUrl }}
@if (is_string($waiverUrl ?? null))

Please sign your waiver before you arrive:
{{ $waiverUrl }}
@endif
