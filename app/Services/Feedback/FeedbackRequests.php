<?php

namespace App\Services\Feedback;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\TenantStatus;
use App\Enums\TransactionalEmail;
use App\Jobs\SendFeedbackRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\FeedbackRequest;
use App\Models\NotificationHook;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantNotificationSetting;
use App\Notifications\FeedbackRequestNotification;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationHooks;
use App\Services\Notifications\TransactionalEmailPreferences;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * One feedback request per booking, queued when the visit is returned or completed.
 * The email waits for the workspace delay, then a signed page collects one rating.
 */
final class FeedbackRequests
{
    public const DEFAULT_DELAY_MINUTES = 120;

    public const MAX_DELAY_MINUTES = 10080;

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly NotificationHooks $hooks,
        private readonly TransactionalEmailPreferences $preferences,
        private readonly AuditLogger $audit,
    ) {}

    public function queue(Booking $booking, bool $fromReturn = false): void
    {
        $context = $this->tenants->id();

        if (! is_string($context) || $context !== $booking->tenant_id) {
            return;
        }

        $finished = $fromReturn
            || $booking->operational_status === OperationalStatus::Returned
            || $booking->operational_status === OperationalStatus::Completed;

        if (! $finished || $booking->status !== BookingStatus::Confirmed) {
            return;
        }

        try {
            FeedbackRequest::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'customer_id' => $booking->customer_id,
                'send_at' => CarbonImmutable::now('UTC')->addMinutes($this->delayMinutes($booking->tenant_id)),
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }
    }

    public function dispatchDue(): void
    {
        Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Onboarding->value])
            ->orderBy('id')
            ->each(function (Tenant $tenant): void {
                $previous = $this->tenants->get();
                $this->tenants->set($tenant);

                try {
                    $ids = FeedbackRequest::query()
                        ->whereNull('sent_at')
                        ->whereNull('skipped_at')
                        ->where('send_at', '<=', CarbonImmutable::now('UTC'))
                        ->orderBy('send_at')
                        ->orderBy('id')
                        ->limit(50)
                        ->pluck('id');

                    foreach ($ids as $id) {
                        if (! is_string($id)) {
                            continue;
                        }

                        SendFeedbackRequest::dispatch($tenant->id, $id);
                    }
                } finally {
                    if ($previous instanceof Tenant) {
                        $this->tenants->set($previous);
                    } else {
                        $this->tenants->forget();
                    }
                }
            });
    }

    public function deliver(Tenant $tenant, string $feedbackRequestId): void
    {
        if ($this->tenants->id() !== $tenant->id) {
            throw new TenantContextException('Refusing to send feedback for a different workspace.');
        }

        DB::transaction(function () use ($tenant, $feedbackRequestId): void {
            $request = FeedbackRequest::query()->whereKey($feedbackRequestId)->lockForUpdate()->first();

            if (! $request instanceof FeedbackRequest) {
                $this->rejectForeign($feedbackRequestId, $tenant->id);

                return;
            }

            if ($request->tenant_id !== $tenant->id) {
                throw new TenantContextException('Refusing to send feedback for a different workspace.');
            }

            if ($request->sent_at !== null || $request->skipped_at !== null || ! $this->isDue($request)) {
                return;
            }

            $booking = Booking::query()->with(['customer', 'service'])->find($request->booking_id);
            $reason = $this->skipReason($tenant, $booking);

            if ($reason === null && $booking instanceof Booking) {
                $this->send($tenant, $booking);
                $request->sent_at = Carbon::now('UTC');
                $request->skip_reason = null;
                $request->save();
                $this->recordHook($tenant->id, $booking, $request, 'sent', null);

                return;
            }

            $request->skipped_at = Carbon::now('UTC');
            $request->skip_reason = $reason ?? 'The booking is no longer confirmed.';
            $request->save();
            $this->recordHook($tenant->id, $booking instanceof Booking ? $booking : null, $request, 'skipped', $request->skip_reason);
        });
    }

    public function markUndelivered(string $tenantId, string $feedbackRequestId): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $previous = $this->tenants->get();
        $this->tenants->set($tenant);

        try {
            DB::transaction(function () use ($tenant, $feedbackRequestId): void {
                $request = FeedbackRequest::query()->whereKey($feedbackRequestId)->lockForUpdate()->first();

                if (! $request instanceof FeedbackRequest || $request->tenant_id !== $tenant->id) {
                    return;
                }

                if ($request->sent_at !== null || $request->skipped_at !== null) {
                    return;
                }

                $reason = 'Feedback email could not be sent.';
                $request->skipped_at = Carbon::now('UTC');
                $request->skip_reason = $reason;
                $request->save();
                $booking = Booking::query()->find($request->booking_id);
                $this->recordHook($tenant->id, $booking instanceof Booking ? $booking : null, $request, 'skipped', $reason);
            });
        } finally {
            if ($previous instanceof Tenant) {
                $this->tenants->set($previous);
            } else {
                $this->tenants->forget();
            }
        }
    }

    public function forBooking(Booking $booking): ?FeedbackRequest
    {
        $this->assertTenant($booking->tenant_id);

        $request = FeedbackRequest::query()->where('booking_id', $booking->id)->first();

        if (! $request instanceof FeedbackRequest || $request->tenant_id !== $booking->tenant_id) {
            return null;
        }

        return $request;
    }

    public function rate(Booking $booking, int $rating, ?string $comment): FeedbackRequest
    {
        $this->assertTenant($booking->tenant_id);

        if ($rating < 1 || $rating > 5) {
            abort(422);
        }

        return DB::transaction(function () use ($booking, $rating, $comment): FeedbackRequest {
            $request = FeedbackRequest::query()->where('booking_id', $booking->id)->lockForUpdate()->first();

            if (! $request instanceof FeedbackRequest || $request->tenant_id !== $booking->tenant_id) {
                abort(404);
            }

            if ($request->rated_at !== null) {
                return $request;
            }

            $text = is_string($comment) ? trim(str_replace("\0", '', $comment)) : '';

            if (mb_strlen($text) > 2000) {
                abort(422);
            }

            $request->rating = $rating;
            $request->comment = $text === '' ? null : $text;
            $request->rated_at = Carbon::now('UTC');
            $request->save();

            $this->audit->log('feedback.rated', $request, [
                'booking_id' => $booking->id,
                'rating' => $rating,
            ], request: request());

            return $request;
        });
    }

    public function optOut(Booking $booking): void
    {
        $this->assertTenant($booking->tenant_id);
        $customer = $booking->customer;

        if (! $customer instanceof Customer || $customer->tenant_id !== $booking->tenant_id || $customer->anonymized_at !== null) {
            return;
        }

        if ($customer->feedback_opted_out_at !== null) {
            return;
        }

        $customer->feedback_opted_out_at = Carbon::now('UTC');
        $customer->save();
        $this->audit->log('feedback.opted_out', $customer, [
            'customer_id' => $customer->id,
            'booking_id' => $booking->id,
        ], request: request());
    }

    public function delayMinutes(string $tenantId): int
    {
        $row = TenantNotificationSetting::query()->where('tenant_id', $tenantId)->first();

        if (! $row instanceof TenantNotificationSetting) {
            return self::DEFAULT_DELAY_MINUTES;
        }

        return $row->feedbackDelayMinutes();
    }

    public function publicReviewUrl(string $tenantId): ?string
    {
        $this->assertTenant($tenantId);
        $setting = TenantNotificationSetting::query()->where('tenant_id', $tenantId)->first();
        $url = $setting instanceof TenantNotificationSetting ? $setting->review_url : null;

        return self::acceptableReviewUrl(is_string($url) ? $url : null);
    }

    public static function acceptableReviewUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);

        if ($trimmed === '' || mb_strlen($trimmed) > 2048 || preg_match('/\s/', $trimmed) === 1) {
            return null;
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if ($scheme !== 'https' || ! is_string($host) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $trimmed;
    }

    private function send(Tenant $tenant, Booking $booking): void
    {
        $customer = $booking->customer;
        $email = $customer instanceof Customer ? $customer->email : null;

        if (! is_string($email) || $email === '') {
            return;
        }

        $links = $this->signedLinks($booking->id, $this->origin($tenant->id));
        $setting = TenantNotificationSetting::query()->where('tenant_id', $tenant->id)->first();
        $replyTo = $setting instanceof TenantNotificationSetting ? $setting->reply_to : null;
        $serviceName = $booking->service?->name;
        $serviceName = is_string($serviceName) ? trim($serviceName) : '';

        Notification::route('mail', $email)->notify(new FeedbackRequestNotification(
            $this->businessName($tenant),
            $serviceName,
            $links['rate'],
            $links['opt_out'],
            is_string($replyTo) && $replyTo !== '' ? $replyTo : null,
        ));
    }

    /**
     * @return array{rate: string, opt_out: string}
     */
    private function signedLinks(string $bookingId, ?string $origin): array
    {
        if ($origin === null) {
            throw new TenantContextException('Refusing to sign a feedback link without a verified domain.');
        }

        URL::forceRootUrl($origin);

        try {
            return [
                'rate' => URL::signedRoute('feedback.show', ['booking' => $bookingId]),
                'opt_out' => URL::signedRoute('feedback.opt-out', ['booking' => $bookingId]),
            ];
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function skipReason(Tenant $tenant, ?Booking $booking): ?string
    {
        if (! $booking instanceof Booking || $booking->tenant_id !== $tenant->id || $booking->status !== BookingStatus::Confirmed) {
            return 'The booking is no longer confirmed.';
        }

        if (! $this->preferences->enabled($tenant->id, TransactionalEmail::BookingFeedback->value)) {
            return 'The workspace turned off feedback email.';
        }

        $customer = $booking->customer;

        if ($customer instanceof Customer && $customer->tenant_id !== $tenant->id) {
            throw new TenantContextException('Refusing to send feedback for a different workspace.');
        }

        if ($customer instanceof Customer && $customer->anonymized_at !== null) {
            return 'The customer was removed.';
        }

        if ($customer instanceof Customer && $customer->feedback_opted_out_at !== null) {
            return 'The customer opted out of feedback email.';
        }

        $email = $customer instanceof Customer ? $customer->email : null;

        if (! is_string($email) || $email === '') {
            return 'The booking has no customer email.';
        }

        $mailer = config('mail.default');
        $mailer = is_string($mailer) ? $mailer : '';

        if (! $this->delivers($mailer)) {
            return $this->mailerReason($mailer);
        }

        if ($this->origin($tenant->id) === null) {
            return 'The workspace has no verified domain.';
        }

        return null;
    }

    private function isDue(FeedbackRequest $request): bool
    {
        return CarbonImmutable::instance($request->send_at)->lessThanOrEqualTo(CarbonImmutable::now('UTC'));
    }

    private function delivers(string $mailer): bool
    {
        return $mailer !== '' && ! in_array($mailer, ['log', 'array'], true);
    }

    private function mailerReason(string $mailer): string
    {
        if ($mailer === 'log') {
            return 'The log mailer does not send feedback email.';
        }

        if ($mailer === 'array') {
            return 'The array mailer does not send feedback email.';
        }

        return 'The mailer does not send feedback email.';
    }

    private function origin(string $tenantId): ?string
    {
        $hostname = Domain::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->value('hostname');

        if (! is_string($hostname) || $hostname === '') {
            return null;
        }

        $scheme = app()->environment('production') ? 'https' : 'http';

        return $scheme.'://'.$hostname;
    }

    private function businessName(Tenant $tenant): string
    {
        $branding = TenantBranding::query()->where('tenant_id', $tenant->id)->first();
        $display = $branding instanceof TenantBranding ? trim($branding->display_name) : '';

        if ($display !== '') {
            return $display;
        }

        $name = trim($tenant->name);

        return $name !== '' ? $name : 'your host';
    }

    private function recordHook(string $tenantId, ?Booking $booking, FeedbackRequest $request, string $status, ?string $error): void
    {
        $bookingId = $booking instanceof Booking ? $booking->id : $request->booking_id;
        $dedupe = 'booking.feedback:'.$bookingId;
        $this->hooks->record($tenantId, $bookingId, TransactionalEmail::BookingFeedback->value, $dedupe, [
            'booking_id' => $bookingId,
            'feedback_request_id' => $request->id,
        ]);
        $hook = NotificationHook::query()->where('dedupe_key', $dedupe)->first();

        if (! $hook instanceof NotificationHook) {
            return;
        }

        $hook->delivery_status = $status;
        $hook->delivery_error = $error === null ? null : mb_substr($error, 0, 255);
        $hook->attempted_at = Carbon::now('UTC');
        $hook->save();
    }

    private function rejectForeign(string $feedbackRequestId, string $tenantId): void
    {
        $foreign = FeedbackRequest::withoutGlobalScope(TenantScope::class)->whereKey($feedbackRequestId)->first();

        if ($foreign instanceof FeedbackRequest && $foreign->tenant_id !== $tenantId) {
            throw new TenantContextException('Refusing to send feedback for a different workspace.');
        }
    }

    private function assertTenant(string $tenantId): void
    {
        $context = $this->tenants->id();

        if (! is_string($context) || $context !== $tenantId) {
            throw new TenantContextException('Refusing feedback for a different workspace.');
        }
    }
}
