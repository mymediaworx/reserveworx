<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Enums\TenantStatus;
use App\Events\BookingReminderDue;
use App\Listeners\SendBookingReminder;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\Customer;
use App\Models\IntegrationOutbox;
use App\Models\Location;
use App\Models\NotificationHook;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Notifications\BookingReminderNotification;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class BookingReminderTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_owner_and_manager_can_read_and_update_offsets_and_staff_cannot(): void
    {
        $owner = $this->workspace('dock.test');
        $manager = $this->workspace('manager.test', 'manager');
        $staff = $this->workspace('staff.test', 'staff');

        $this->actingAsWorkspace($owner);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/booking-reminder-offsets'))
            ->assertOk()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.offsets_minutes.0', 1440)
            ->assertJsonPath('data.offsets_minutes.1', 120);

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/booking-reminder-offsets'), [
            'offsets_minutes' => [30, 120],
        ])->assertOk()
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.offsets_minutes.0', 120)
            ->assertJsonPath('data.offsets_minutes.1', 30);

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/booking-reminder-offsets'), [
            'offsets_minutes' => [0],
        ])->assertUnprocessable();

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/booking-reminder-offsets'), [
            'offsets_minutes' => [60, 60],
        ])->assertUnprocessable();

        app(TenantManager::class)->set($owner['tenant']);
        $setting = TenantSetting::query()->firstOrFail();
        $this->assertSame([120, 30], $setting->reminderOffsets());
        $this->assertSame(15, $setting->hold_minutes);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $owner['tenant']->id,
            'action' => 'reminders.updated',
        ]);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($staff);
        $this->getJson($this->tenantUrl('staff.test', '/api/v1/booking-reminder-offsets'))->assertForbidden();
        $this->patchJson($this->tenantUrl('staff.test', '/api/v1/booking-reminder-offsets'), [
            'offsets_minutes' => [45],
        ])->assertForbidden();

        $this->actingAsWorkspace($manager);
        $this->patchJson($this->tenantUrl('manager.test', '/api/v1/booking-reminder-offsets'), [
            'offsets_minutes' => [],
        ])->assertOk()
            ->assertJsonPath('data.offsets_minutes', [])
            ->assertJsonPath('data.is_default', false);

        $this->actingAsWorkspace($owner);
        $this->getJson($this->tenantUrl('manager.test', '/api/v1/booking-reminder-offsets'))->assertForbidden();
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/booking-reminder-offsets'))
            ->assertOk()
            ->assertJsonPath('data.offsets_minutes.0', 120);
    }

    public function test_a_due_confirmed_booking_is_queued_once_and_a_second_run_does_not_repeat_it(): void
    {
        Mail::fake();
        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $workspace = $this->workspace('dock.test');
        $first = $this->booking($workspace, $now->addHours(3), 'UTC');
        $second = $this->booking($workspace, $now->addMinutes(30), 'UTC');
        $this->booking($workspace, $now->addHours(25), 'UTC');
        $this->booking($workspace, $now->subMinute(), 'UTC');
        $this->booking($workspace, $now->addHour(), 'UTC', BookingStatus::Cancelled);
        $this->booking($workspace, $now->addHour(), 'UTC', BookingStatus::Held);

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, BookingReminder::query()->where('booking_id', $first->id)->count());
        $this->assertSame(1440, BookingReminder::query()->where('booking_id', $first->id)->firstOrFail()->offset_minutes);
        $this->assertNotNull(BookingReminder::query()->where('booking_id', $first->id)->firstOrFail()->dispatched_at);
        $this->assertSame(2, BookingReminder::query()->where('booking_id', $second->id)->count());
        $this->assertSame(3, BookingReminder::query()->count());
        $this->assertSame(3, NotificationHook::query()->where('kind', 'booking.reminder')->count());
        $this->assertSame(3, IntegrationOutbox::query()->where('kind', 'booking.reminder')->count());
        $this->assertSame('skipped', NotificationHook::query()->where('kind', 'booking.reminder')->firstOrFail()->delivery_status);
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
    }

    public function test_the_dispatch_is_queued_once_per_booking_and_offset(): void
    {
        Queue::fake();
        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $workspace = $this->workspace('dock.test');
        $this->booking($workspace, $now->addHours(3), 'UTC');

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        Queue::assertPushed(CallQueuedListener::class, 1);
        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            return $job->class === SendBookingReminder::class;
        });
    }

    public function test_a_log_mailer_records_the_reminder_without_sending_mail(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();
        Notification::fake();
        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $workspace = $this->workspace('dock.test');
        $booking = $this->booking($workspace, $now->addHours(3), 'America/New_York');

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        app(TenantManager::class)->set($workspace['tenant']);
        $hook = NotificationHook::query()->where('kind', 'booking.reminder')->firstOrFail();
        $this->assertSame($booking->id, $hook->booking_id);
        $this->assertSame('skipped', $hook->delivery_status);
        $this->assertSame('The log mailer does not send reminder email.', $hook->delivery_error);
        $this->assertSame('America/New_York', $hook->payload['timezone']);
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_a_delivering_mailer_sends_one_notification_and_skips_a_booking_without_email(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $workspace = $this->workspace('dock.test');
        $this->booking($workspace, $now->addHours(3), 'UTC', BookingStatus::Confirmed, 'guest@example.com');
        $silent = $this->booking($workspace, $now->addHours(3), 'UTC', BookingStatus::Confirmed, null);

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingReminderNotification::class, 1);

        app(TenantManager::class)->set($workspace['tenant']);
        $sent = NotificationHook::query()->where('booking_id', '!=', $silent->id)->where('kind', 'booking.reminder')->firstOrFail();
        $skipped = NotificationHook::query()->where('booking_id', $silent->id)->firstOrFail();
        $this->assertSame('sent', $sent->delivery_status);
        $this->assertSame('skipped', $skipped->delivery_status);
        $this->assertSame('The booking has no customer email.', $skipped->delivery_error);
        app(TenantManager::class)->forget();
    }

    public function test_a_turned_off_booking_reminder_preference_records_the_hook_and_does_not_email(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/notification-settings'), [
            'transactional_emails' => ['booking.reminder' => false],
        ])->assertOk();
        $this->booking($workspace, $now->addHours(3), 'UTC');

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        Notification::assertNothingSent();

        app(TenantManager::class)->set($workspace['tenant']);
        $hook = NotificationHook::query()->where('kind', 'booking.reminder')->firstOrFail();
        $this->assertSame('skipped', $hook->delivery_status);
        $this->assertSame('The workspace turned off booking reminder email.', $hook->delivery_error);
        $this->assertSame(1, BookingReminder::query()->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.reminder')->count());
        app(TenantManager::class)->forget();
    }

    public function test_reminders_follow_elapsed_time_across_the_spring_forward(): void
    {
        Mail::fake();
        $start = CarbonImmutable::parse('2026-03-08 10:00:00', 'America/New_York');
        $absolute = $start->utc()->subMinutes(1440);
        $calendar = $start->subDay()->utc();
        $this->assertTrue($absolute->lt($calendar));

        $workspace = $this->workspace('dock.test');
        $booking = $this->booking($workspace, $start, 'America/New_York');

        Carbon::setTestNow($absolute->subMinute());
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, BookingReminder::query()->count());
        app(TenantManager::class)->forget();

        Carbon::setTestNow($absolute);
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, BookingReminder::query()->count());
        $this->assertSame(1440, BookingReminder::query()->firstOrFail()->offset_minutes);
        $hook = NotificationHook::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('America/New_York', $hook->payload['timezone']);
        $this->assertSame($start->toIso8601String(), $hook->payload['local_starts_at']);
        $this->assertSame($start->utc()->toIso8601String(), $hook->payload['starts_at']);
        app(TenantManager::class)->forget();
    }

    public function test_reminders_follow_elapsed_time_across_the_fall_back(): void
    {
        Mail::fake();
        $start = CarbonImmutable::parse('2026-11-01 10:00:00', 'America/New_York');
        $absolute = $start->utc()->subMinutes(1440);
        $calendar = $start->subDay()->utc();
        $this->assertTrue($calendar->lt($absolute));

        $workspace = $this->workspace('dock.test');
        $this->booking($workspace, $start, 'America/New_York');

        Carbon::setTestNow($calendar);
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, BookingReminder::query()->count());
        app(TenantManager::class)->forget();

        Carbon::setTestNow($absolute);
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, BookingReminder::query()->where('offset_minutes', 1440)->count());
        $this->assertSame(0, BookingReminder::query()->where('offset_minutes', 120)->count());
        app(TenantManager::class)->forget();
    }

    public function test_reminders_stay_inside_the_workspace_that_owns_the_booking(): void
    {
        Mail::fake();
        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaBooking = $this->booking($alpha, $now->addHours(3), 'America/Chicago');
        $betaBooking = $this->booking($beta, $now->addHours(3), 'America/Denver');
        $suspended = $this->workspace('closed.test', 'owner', ['status' => TenantStatus::Suspended]);
        $suspendedBooking = $this->booking($suspended, $now->addHours(3), 'UTC');
        $onboarding = $this->workspace('new.test', 'owner', ['status' => TenantStatus::Onboarding]);
        $onboardingBooking = $this->booking($onboarding, $now->addHours(3), 'UTC');

        BookingReminderDue::dispatch($alpha['tenant']->id, $betaBooking->id, 1440);

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(0, NotificationHook::query()->count());
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(0, NotificationHook::query()->count());
        app(TenantManager::class)->forget();

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame([$alphaBooking->id], NotificationHook::query()->pluck('booking_id')->all());
        $this->assertSame('America/Chicago', NotificationHook::query()->firstOrFail()->payload['timezone']);
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame([$betaBooking->id], NotificationHook::query()->pluck('booking_id')->all());
        $this->assertSame('America/Denver', NotificationHook::query()->firstOrFail()->payload['timezone']);
        app(TenantManager::class)->forget();

        $this->assertSame(0, BookingReminder::withoutGlobalScope(TenantScope::class)->where('booking_id', $suspendedBooking->id)->count());
        $this->assertSame(1, BookingReminder::withoutGlobalScope(TenantScope::class)->where('booking_id', $onboardingBooking->id)->count());
        $this->assertSame(0, NotificationHook::withoutGlobalScope(TenantScope::class)->where('booking_id', $betaBooking->id)->where('tenant_id', $alpha['tenant']->id)->count());
    }

    public function test_an_empty_offset_list_sends_nothing(): void
    {
        Mail::fake();
        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/booking-reminder-offsets'), [
            'offsets_minutes' => [],
        ])->assertOk();
        $this->booking($workspace, $now->addHour(), 'UTC');

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        $this->assertSame(0, BookingReminder::withoutGlobalScope(TenantScope::class)->count());
        Mail::assertNothingSent();
    }

    public function test_schedule_run_dispatches_due_reminders_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'bookings:dispatch-reminders'),
        );

        $this->assertInstanceOf(ScheduledEvent::class, $event);
        $this->assertSame('*/5 * * * *', $event->expression);

        Carbon::setTestNow(Carbon::parse('2026-06-16 12:03:00', 'UTC'));
        $this->assertFalse($event->isDue(app()));

        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $this->assertTrue($event->isDue(app()));
        $this->artisan('schedule:run')
            ->expectsOutputToContain('bookings:dispatch-reminders')
            ->assertSuccessful();
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function booking(
        array $workspace,
        CarbonImmutable $start,
        string $timezone,
        BookingStatus $status = BookingStatus::Confirmed,
        ?string $email = 'guest@example.com',
    ): Booking {
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => $timezone,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);

        try {
            $service = Service::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'location_id' => $location->id,
                'name' => 'Hourly boat '.$start->utc()->format('YmdHis').$status->value,
                'kind' => ServiceKind::Rental,
            ]);
            $customerId = null;

            if ($email !== null) {
                $customerId = Customer::query()->create([
                    'tenant_id' => $workspace['tenant']->id,
                    'name' => 'Guest',
                    'email' => $email,
                ])->id;
            }

            $startUtc = $start->utc();

            return Booking::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'location_id' => $location->id,
                'service_id' => $service->id,
                'customer_id' => $customerId,
                'status' => $status,
                'payment_status' => PaymentStatus::Unpaid,
                'operational_status' => OperationalStatus::NotArrived,
                'starts_at' => $startUtc,
                'ends_at' => $startUtc->addHour(),
                'participants' => 1,
                'payload_hash' => str_repeat('ab', 32),
            ]);
        } finally {
            app(TenantManager::class)->forget();
        }
    }
}
