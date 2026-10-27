<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\Operations\WaiverPdf;
use Illuminate\Http\Response;

/**
 * Staff download of one stored waiver signature. The file matches the API PDF.
 * This is not an electronic-signature certificate.
 */
class StaffWaiverPdfController extends Controller
{
    public function __construct(private readonly WaiverPdf $pdf) {}

    public function show(Booking $booking, WaiverSignature $signature): Response
    {
        abort_unless($signature->booking_id === $booking->id, 404);
        $this->authorize('view', $booking);

        $version = WaiverVersion::query()->find($signature->waiver_version_id);

        if (! $version instanceof WaiverVersion) {
            abort(404);
        }

        return response($this->pdf->render($signature, $version), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="waiver-'.$signature->id.'.pdf"',
        ]);
    }
}
