<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your booking is confirmed</title>
</head>
<body style="margin:0;padding:16px;max-width:100%;">
    <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">Your booking for {{ $serviceName }} is confirmed.</p>
    <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">{{ $when }}</p>
    <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">Booking reference {{ $reference }}</p>
    @if (is_string($receiptHtml ?? null) && $receiptHtml !== '')
        <div class="receipt-wrap" style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">{!! $receiptHtml !!}</div>
        <style>
            .receipt-wrap, .receipt-summary, .receipt-summary dt, .receipt-summary dd, .booking-fee-refund-terms, .booking-fee-refund-terms li {
                max-width: 100%;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
            @media (max-width: 390px) {
                .receipt-wrap, .receipt-summary, .booking-fee-refund-terms, .booking-fee-refund-terms li {
                    max-width: 100%;
                    min-width: 0;
                    overflow-wrap: anywhere;
                }
            }
        </style>
    @endif
    <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">Show this code when you arrive.</p>
    <div>{!! $checkInSvg !!}</div>
    <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">Show this link to staff if the code does not appear: {{ $checkInUrl }}</p>
    @if (is_string($waiverUrl ?? null))
        <p style="margin:0 0 12px;overflow-wrap:anywhere;word-break:break-word;max-width:100%;"><strong>Please sign your waiver before you arrive:</strong> <a href="{{ $waiverUrl }}">Sign your waiver</a></p>
    @endif
</body>
</html>
