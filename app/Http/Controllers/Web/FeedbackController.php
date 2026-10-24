<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FeedbackRequest;
use App\Services\Feedback\FeedbackRequests;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackRequests $feedback) {}

    public function show(Booking $booking): View
    {
        $request = $this->requestFor($booking);

        return view('feedback.show', [
            'booking' => $booking,
            'feedback' => $request,
            'serviceName' => $this->serviceName($booking),
            'reviewUrl' => $this->reviewUrl($booking, $request),
            'optOutUrl' => URL::signedRoute('feedback.opt-out', ['booking' => $booking->id]),
            'optedOut' => $this->optedOut($booking),
        ]);
    }

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $comment = $validated['comment'] ?? null;
        $rating = $validated['rating'];
        $this->feedback->rate(
            $booking,
            is_int($rating) ? $rating : (int) $rating,
            is_string($comment) ? $comment : null,
        );

        return redirect()->to($request->fullUrl());
    }

    public function optOut(Request $request, Booking $booking): View|RedirectResponse
    {
        $this->requestFor($booking);

        if ($request->isMethod('post')) {
            $this->feedback->optOut($booking);

            return redirect()->to($request->fullUrl());
        }

        return view('feedback.opt-out', [
            'booking' => $booking,
            'serviceName' => $this->serviceName($booking),
            'optedOut' => $this->optedOut($booking),
        ]);
    }

    private function requestFor(Booking $booking): FeedbackRequest
    {
        $request = $this->feedback->forBooking($booking);
        abort_unless($request instanceof FeedbackRequest, 404);

        return $request;
    }

    private function reviewUrl(Booking $booking, FeedbackRequest $request): ?string
    {
        $rating = $request->rating;

        if (! is_int($rating) || $rating < 4) {
            return null;
        }

        return $this->feedback->publicReviewUrl($booking->tenant_id);
    }

    private function serviceName(Booking $booking): string
    {
        $name = $booking->service?->name;

        return is_string($name) ? $name : 'your visit';
    }

    private function optedOut(Booking $booking): bool
    {
        $customer = $booking->customer;

        return $customer instanceof Customer && $customer->feedback_opted_out_at !== null;
    }
}
