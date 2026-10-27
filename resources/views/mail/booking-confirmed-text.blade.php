Your booking for {{ $serviceName }} is confirmed.
{{ $when }}
Booking reference {{ $reference }}
@if (is_string($receiptText ?? null) && $receiptText !== '')

{{ $receiptText }}
@endif

Show this link to staff when you arrive:
{{ $checkInUrl }}
@if (is_string($waiverUrl ?? null))

Please sign your waiver before you arrive:
{{ $waiverUrl }}
@endif
