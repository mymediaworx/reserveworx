<?php

namespace App\Livewire\Staff;

use App\Enums\TransactionalEmail;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\FeedbackRequest;
use App\Models\Service;
use App\Services\Branding\WorkspaceProfiles;
use App\Services\Feedback\FeedbackRequests;
use App\Support\Display;
use App\Support\DisplayZone;
use DateTimeInterface;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.staff')]
#[Title('Feedback')]
class Feedback extends Component
{
    use WithinTenant, WithPagination;

    #[Url]
    public string $stars = '';

    #[Url]
    public string $service = '';

    #[Url]
    public string $commented = '';

    public int $delayMinutes = FeedbackRequests::DEFAULT_DELAY_MINUTES;

    public string $reviewUrl = '';

    public bool $feedbackEnabled = true;

    public ?string $flash = null;

    public function mount(WorkspaceProfiles $profiles): void
    {
        $this->authorize('viewAny', Booking::class);
        $setting = $profiles->notificationSettings();
        $this->authorize('view', $setting);
        $this->delayMinutes = $setting->feedbackDelayMinutes();
        $stored = $setting->review_url;
        $this->reviewUrl = is_string($stored) ? $stored : '';
        $this->feedbackEnabled = $setting->allows(TransactionalEmail::BookingFeedback->value);
    }

    public function updatedStars(): void
    {
        $this->resetPage();
    }

    public function updatedService(): void
    {
        $this->resetPage();
    }

    public function updatedCommented(): void
    {
        $this->resetPage();
    }

    public function saveSettings(WorkspaceProfiles $profiles): void
    {
        $setting = $profiles->notificationSettings();
        $this->authorize('update', $setting);
        $this->validate([
            'delayMinutes' => ['required', 'integer', 'min:0', 'max:'.FeedbackRequests::MAX_DELAY_MINUTES],
            'reviewUrl' => ['nullable', 'string', 'max:2048'],
            'feedbackEnabled' => ['boolean'],
        ]);
        $review = trim($this->reviewUrl);

        if ($review !== '' && FeedbackRequests::acceptableReviewUrl($review) === null) {
            $this->addError('reviewUrl', 'The review link must be an https address.');

            return;
        }

        $before = $setting->auditSnapshot();
        $preferences = $setting->preferences();
        $preferences[TransactionalEmail::BookingFeedback->value] = $this->feedbackEnabled;
        $setting->transactional_emails = $preferences;
        $setting->feedback_delay_minutes = $this->delayMinutes;
        $setting->review_url = $review === '' ? null : $review;
        $setting->save();
        $this->audit('notification_settings.updated', $setting, [
            'before' => $before,
            'after' => $setting->auditSnapshot(),
        ]);
        $this->flash = 'Feedback settings saved.';
    }

    public function whenLabel(FeedbackRequest $request): string
    {
        $zone = DisplayZone::forLocation($request->booking?->location);
        $at = $request->rated_at;

        if (! $at instanceof DateTimeInterface) {
            return '';
        }

        return Display::dateTimeWithZone($at, $zone);
    }

    public function starsLabel(FeedbackRequest $request): string
    {
        $rating = $request->rating;

        if (! is_int($rating) || $rating < 1) {
            return '';
        }

        $filled = min(5, $rating);

        return str_repeat('★', $filled).str_repeat('☆', 5 - $filled);
    }

    public function customerName(FeedbackRequest $request): string
    {
        $name = $request->customer?->name;

        return is_string($name) && $name !== '' ? $name : 'Customer';
    }

    public function serviceName(FeedbackRequest $request): string
    {
        $name = $request->booking?->service?->name;

        return is_string($name) && $name !== '' ? $name : 'Booking';
    }

    public function render(): View
    {
        $stars = in_array($this->stars, ['1', '2', '3', '4', '5'], true) ? (int) $this->stars : null;
        $serviceId = $this->service !== '' ? $this->service : null;
        $commented = in_array($this->commented, ['yes', 'no'], true) ? $this->commented : null;

        $query = FeedbackRequest::query()
            ->whereNotNull('rating')
            ->when($stars !== null, fn ($rows) => $rows->where('rating', $stars))
            ->when($commented === 'yes', fn ($rows) => $rows->whereNotNull('comment')->where('comment', '!=', ''))
            ->when($commented === 'no', fn ($rows) => $rows->where(fn ($inner) => $inner->whereNull('comment')->orWhere('comment', '')))
            ->when($serviceId !== null, function ($rows) use ($serviceId) {
                $rows->whereHas('booking', fn ($bookings) => $bookings->where('service_id', $serviceId));
            });

        $raw = (clone $query)->avg('rating');
        $count = (clone $query)->count();
        $average = is_numeric($raw) ? number_format(round((float) $raw, 1), 1) : null;

        return view('livewire.staff.feedback', [
            'ratings' => $query->with(['booking.service', 'booking.location', 'customer'])->orderByDesc('rated_at')->orderByDesc('id')->paginate(25),
            'average' => $average,
            'count' => $count,
            'filtered' => $stars !== null || $serviceId !== null || $commented !== null,
            'services' => Service::query()->orderBy('name')->get(),
            'waiting' => FeedbackRequest::query()->whereNull('sent_at')->whereNull('skipped_at')->whereNull('rating')->count(),
            'canUpdate' => $this->allows('settings.update'),
        ]);
    }
}
