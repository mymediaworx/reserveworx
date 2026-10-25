<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\IncidentPhoto;
use App\Services\Operations\IncidentPhotos;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffIncidentPhotoController extends Controller
{
    public function __construct(private readonly IncidentPhotos $photos) {}

    public function show(Incident $incident, IncidentPhoto $photo): StreamedResponse
    {
        $booking = Booking::query()->find($incident->booking_id);
        abort_unless($booking instanceof Booking, 404);
        $this->authorize('view', $booking);
        abort_unless($photo->incident_id === $incident->id, 404);

        return $this->photos->response($photo);
    }
}
