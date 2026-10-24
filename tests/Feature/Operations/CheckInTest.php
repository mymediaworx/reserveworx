<?php

namespace Tests\Feature\Operations;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Participant;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Notifications\BookingConfirmedNotification;
use App\Services\CheckIn\CheckInLink;
use App\Services\Notifications\BookingConfirmationMail;
use App\Services\Notifications\BookingEvents;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\Common\LuminanceSourceAbstract;
use chillerlan\QRCode\Decoder\Decoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_opening_a_signed_link_checks_a_confirmed_booking_in_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 16:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $booking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $url = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $booking->id);

        $this->actingAs($alpha['user'])
            ->get($url)
            ->assertOk()
            ->assertSee('Checked in.');

        $this->useTenant($alpha['tenant']);
        $checked = Booking::query()->findOrFail($booking->id);
        $this->assertSame(OperationalStatus::CheckedIn, $checked->operational_status);
        $this->assertSame($alpha['user']->id, $checked->checked_in_by);
        $this->assertNotNull($checked->checked_in_at);
        $seenAt = $checked->checked_in_at->toIso8601String();
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.checked_in')->count());
        $audit = AuditLog::query()->where('action', 'booking.checked_in')->firstOrFail();
        $this->assertSame('checked_in', $audit->properties['operational_status'] ?? null);
        $this->assertFalse($audit->properties['waiver_override'] ?? true);
        $this->assertStringNotContainsString('signature', (string) json_encode($audit->properties));

        Carbon::setTestNow(Carbon::parse('2026-06-16 17:00:00', 'UTC'));
        $this->get($url)
            ->assertOk()
            ->assertSee('Already checked in.');

        $this->useTenant($alpha['tenant']);
        $again = Booking::query()->findOrFail($booking->id);
        $this->assertSame($seenAt, $again->checked_in_at?->toIso8601String());
        $this->assertSame(OperationalStatus::CheckedIn, $again->operational_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.checked_in')->count());
    }

    public function test_a_tampered_signature_or_swapped_booking_does_not_check_in(): void
    {
        $alpha = $this->workspace('alpha.test');
        $location = $this->location($alpha, 'North dock');
        $first = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $second = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 19:00:00', 'UTC'), 'Grace North');
        $url = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $first->id);
        $parts = parse_url($url);
        $this->assertIsArray($parts);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['signature'] = str_repeat('ab', 32);
        $tampered = $parts['scheme'].'://'.$parts['host'].$parts['path'].'?'.http_build_query($query);
        $swapped = $parts['scheme'].'://'.$parts['host'].str_replace((string) $first->id, (string) $second->id, (string) $parts['path']).'?'.$parts['query'];

        $this->actingAs($alpha['user'])->get($tampered)->assertForbidden();
        $this->actingAs($alpha['user'])->get($swapped)->assertForbidden();

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($first->id)->operational_status);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($second->id)->operational_status);
        $this->assertSame(0, AuditLog::query()->where('action', 'booking.checked_in')->count());
    }

    public function test_another_workspace_is_not_listed_and_its_link_does_not_check_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 16:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaBooking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $betaBooking = $this->booking($beta, $this->location($beta, 'South dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Beta Only');
        $betaUrl = $this->signedUrl('staff.check-in.arrive', 'beta.test', $betaBooking->id);
        $alphaHost = str_replace('://beta.test', '://alpha.test', $betaUrl);
        $forged = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $betaBooking->id);

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/check-in'))
            ->assertOk()
            ->assertSee('Ada North')
            ->assertDontSee('Beta Only');

        $this->actingAs($alpha['user'])->get($alphaHost)->assertNotFound();
        $this->actingAs($alpha['user'])->get($betaUrl)->assertForbidden();
        $this->actingAs($alpha['user'])->get($forged)->assertNotFound();

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($alphaBooking->id)->operational_status);
        $this->useTenant($beta['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($betaBooking->id)->operational_status);
        $this->assertNull(Booking::query()->findOrFail($betaBooking->id)->checked_in_at);
    }

    public function test_a_guest_is_sent_to_sign_in_and_the_booking_stays_open(): void
    {
        $alpha = $this->workspace('alpha.test');
        $booking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $url = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $booking->id);

        $this->get($url)->assertRedirect('/staff/login');

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($booking->id)->operational_status);
        $this->assertSame(0, AuditLog::query()->where('action', 'booking.checked_in')->count());
    }

    public function test_check_in_requires_the_check_in_permission(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $booking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $url = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $booking->id);
        $viewer = User::factory()->create();
        $role = Role::query()->create(['slug' => 'desk-view', 'name' => 'Desk view']);
        $role->permissions()->sync([
            Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id,
        ]);
        Membership::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $viewer->id,
            'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)
            ->get($this->tenantUrl('alpha.test', '/staff/check-in'))
            ->assertForbidden();
        $this->actingAs($viewer)->get($url)->assertForbidden();

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($booking->id)->operational_status);

        $this->actingAs($alpha['user'])
            ->get($url)
            ->assertOk()
            ->assertSee('Checked in.');

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::CheckedIn, Booking::query()->findOrFail($booking->id)->operational_status);
    }

    public function test_missing_waivers_block_until_staff_record_a_reason(): void
    {
        $alpha = $this->workspace('alpha.test');
        $booking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $this->waiver($alpha['tenant'], 'Liability', 1);
        $url = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $booking->id);

        $this->actingAs($alpha['user'])
            ->get($url)
            ->assertOk()
            ->assertSee('Liability')
            ->assertSee('No price yet')
            ->assertSee('This booking still needs Liability')
            ->assertDontSee('Checked in.');

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($booking->id)->operational_status);

        $this->from($url)
            ->post($url, ['operational_status' => 'checked_in'])
            ->assertSessionHasErrors('reason');
        $this->from($url)
            ->post($url, ['reason' => 'no'])
            ->assertSessionHasErrors('reason');
        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($booking->id)->operational_status);

        $reason = '<script>alert(1)</script>';
        $this->from($url)
            ->post($url, ['reason' => $reason])
            ->assertRedirect();

        $this->get($url)
            ->assertOk()
            ->assertSee('Checked in.')
            ->assertSee('alert(1)')
            ->assertDontSee($reason, false);

        $this->useTenant($alpha['tenant']);
        $checked = Booking::query()->findOrFail($booking->id);
        $this->assertSame(OperationalStatus::CheckedIn, $checked->operational_status);
        $this->assertSame($reason, $checked->check_in_waiver_override_reason);
        $audit = AuditLog::query()->where('action', 'booking.checked_in')->firstOrFail();
        $this->assertTrue($audit->properties['waiver_override'] ?? false);
        $this->assertSame($reason, $audit->properties['reason'] ?? null);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.checked_in')->count());
    }

    public function test_each_named_person_must_sign_the_current_waiver(): void
    {
        $alpha = $this->workspace('alpha.test');
        $booking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $version = $this->waiver($alpha['tenant'], 'Liability', 1);
        $ada = $this->participant($alpha['tenant'], $booking, 'Ada North', false);
        $grace = $this->participant($alpha['tenant'], $booking, 'Grace North', false);
        $this->sign($alpha['tenant'], $booking, $version, $ada, 'Ada North', false, null);
        $url = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $booking->id);

        $this->actingAs($alpha['user'])
            ->get($url)
            ->assertOk()
            ->assertSee('Grace North still needs Liability')
            ->assertDontSee('Checked in.');

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($booking->id)->operational_status);

        $this->sign($alpha['tenant'], $booking, $version, $grace, 'Grace North', false, null);
        $this->actingAs($alpha['user'])->get($url)->assertOk()->assertSee('Checked in.');
        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::CheckedIn, Booking::query()->findOrFail($booking->id)->operational_status);
    }

    public function test_a_minor_needs_a_guardian_on_the_current_waiver(): void
    {
        $alpha = $this->workspace('alpha.test');
        $booking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $version = $this->waiver($alpha['tenant'], 'Liability', 2);
        $this->waiver($alpha['tenant'], 'Liability', 1);
        $minor = $this->participant($alpha['tenant'], $booking, 'Sam North', true);
        $signature = $this->sign($alpha['tenant'], $booking, $version, $minor, 'Sam North', true, null);
        $older = WaiverVersion::query()->where('name', 'Liability')->where('version', 1)->firstOrFail();
        $url = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $booking->id);

        $this->actingAs($alpha['user'])
            ->get($url)
            ->assertOk()
            ->assertSee('Sam North still needs Liability')
            ->assertDontSee('Checked in.');

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($booking->id)->operational_status);

        $signature->guardian_name = 'Pat Parent';
        $signature->save();
        $this->actingAs($alpha['user'])->get($url)->assertOk()->assertSee('Checked in.');
        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::CheckedIn, Booking::query()->findOrFail($booking->id)->operational_status);
        $this->assertNotSame($older->id, $signature->waiver_version_id);
    }

    public function test_an_older_waiver_version_does_not_count(): void
    {
        $alpha = $this->workspace('alpha.test');
        $booking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $older = $this->waiver($alpha['tenant'], 'Liability', 1);
        $current = $this->waiver($alpha['tenant'], 'Liability', 2);
        $this->sign($alpha['tenant'], $booking, $older, null, 'Ada North', false, null);
        $url = $this->signedUrl('staff.check-in.arrive', 'alpha.test', $booking->id);

        $this->actingAs($alpha['user'])
            ->get($url)
            ->assertOk()
            ->assertSee('This booking still needs Liability')
            ->assertDontSee('Checked in.');

        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($booking->id)->operational_status);

        $this->sign($alpha['tenant'], $booking, $current, null, 'Ada North', false, null);
        $this->actingAs($alpha['user'])->get($url)->assertOk()->assertSee('Checked in.');
        $this->useTenant($alpha['tenant']);
        $this->assertSame(OperationalStatus::CheckedIn, Booking::query()->findOrFail($booking->id)->operational_status);
    }

    public function test_todays_arrivals_follow_the_location_and_the_search(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 16:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha, 'North dock');
        $south = $this->location($alpha, 'South dock');
        $ada = $this->booking($alpha, $north, CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North', 'Sunset paddle');
        $this->booking($alpha, $south, CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Grace South', 'Evening paddle');
        $this->booking($alpha, $north, CarbonImmutable::parse('2026-06-17 15:00:00', 'UTC'), 'Tomorrow North', 'Morning paddle');

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/check-in'))
            ->assertOk()
            ->assertSee('Ada North')
            ->assertSee('Sunset paddle')
            ->assertDontSee('Grace South')
            ->assertDontSee('Tomorrow North');

        $this->get($this->tenantUrl('alpha.test', '/staff/check-in?location='.$south->id))
            ->assertOk()
            ->assertSee('Grace South')
            ->assertDontSee('Ada North');

        $this->get($this->tenantUrl('alpha.test', '/staff/check-in?location='.$north->id.'&search=Grace'))
            ->assertOk()
            ->assertSee('No arrivals at this location today.')
            ->assertDontSee('Ada North');

        $this->get($this->tenantUrl('alpha.test', '/staff/check-in?location='.$north->id.'&search=Sunset'))
            ->assertOk()
            ->assertSee('Ada North');

        $this->get($this->tenantUrl('alpha.test', '/staff/check-in?location='.$north->id.'&search='.substr((string) $ada->id, 0, 10)))
            ->assertOk()
            ->assertSee('Ada North')
            ->assertDontSee('Tomorrow North');

        $this->get($this->tenantUrl('alpha.test', '/staff/check-in?location=01ARZ3NDEKTSV4RRFFQ69G5FAV'))
            ->assertNotFound();
    }

    public function test_balance_due_uses_payments_refunds_and_the_offline_fee(): void
    {
        $alpha = $this->workspace('alpha.test');
        $location = $this->location($alpha, 'North dock');
        $deposit = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Deposit Guest');
        $this->price($alpha['tenant'], $deposit, 10_000, 300);
        $this->payment($alpha['tenant'], $deposit, 2_500, 'paid');
        $refunded = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 19:00:00', 'UTC'), 'Refund Guest');
        $this->price($alpha['tenant'], $refunded, 10_000, 0);
        $this->payment($alpha['tenant'], $refunded, 10_000, 'paid');
        $this->refund($alpha['tenant'], $refunded, 2_500);
        $offline = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 20:00:00', 'UTC'), 'Offline Guest', 'Paddle', BookingStatus::Confirmed, OperationalStatus::NotArrived, null, PaymentStatus::OfflinePaid);
        $this->price($alpha['tenant'], $offline, 10_300, 300);
        $this->payment($alpha['tenant'], $offline, 10_000, 'paid');
        $unpriced = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 21:00:00', 'UTC'), 'Unpriced Guest');

        $this->actingAs($alpha['user']);
        $this->get($this->signedUrl('staff.check-in.arrive', 'alpha.test', $deposit->id))->assertOk()->assertSee('$75.00');
        $this->get($this->signedUrl('staff.check-in.arrive', 'alpha.test', $refunded->id))->assertOk()->assertSee('$25.00');
        $this->get($this->signedUrl('staff.check-in.arrive', 'alpha.test', $offline->id))->assertOk()->assertSee('$0.00');
        $this->get($this->signedUrl('staff.check-in.arrive', 'alpha.test', $unpriced->id))->assertOk()->assertSee('No price yet');
    }

    public function test_only_an_open_confirmed_booking_can_be_checked_in(): void
    {
        $alpha = $this->workspace('alpha.test');
        $location = $this->location($alpha, 'North dock');
        $held = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Held Guest', 'Paddle', BookingStatus::Held);
        $cancelled = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 19:00:00', 'UTC'), 'Cancelled Guest', 'Paddle', BookingStatus::Cancelled);
        $started = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 20:00:00', 'UTC'), 'Started Guest', 'Paddle', BookingStatus::Confirmed, OperationalStatus::InProgress);
        $overdue = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 21:00:00', 'UTC'), 'Overdue Guest', 'Paddle', BookingStatus::Confirmed, OperationalStatus::Overdue);

        $this->actingAs($alpha['user']);
        $this->get($this->signedUrl('staff.check-in.arrive', 'alpha.test', $held->id))
            ->assertOk()
            ->assertSee('This booking cannot be checked in.');
        $this->get($this->signedUrl('staff.check-in.arrive', 'alpha.test', $cancelled->id))
            ->assertOk()
            ->assertSee('This booking cannot be checked in.');
        $this->get($this->signedUrl('staff.check-in.arrive', 'alpha.test', $started->id))
            ->assertOk()
            ->assertSee('Already checked in.');
        $this->get($this->signedUrl('staff.check-in.arrive', 'alpha.test', $overdue->id))
            ->assertOk()
            ->assertSee('Checked in.');

        $this->useTenant($alpha['tenant']);
        $this->assertSame(BookingStatus::Held, Booking::query()->findOrFail($held->id)->status);
        $this->assertSame(OperationalStatus::NotArrived, Booking::query()->findOrFail($held->id)->operational_status);
        $this->assertSame(BookingStatus::Cancelled, Booking::query()->findOrFail($cancelled->id)->status);
        $this->assertSame(OperationalStatus::InProgress, Booking::query()->findOrFail($started->id)->operational_status);
        $this->assertSame(OperationalStatus::CheckedIn, Booking::query()->findOrFail($overdue->id)->operational_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.checked_in')->count());
    }

    public function test_the_confirmation_page_shows_a_check_in_code_for_a_confirmed_booking(): void
    {
        $alpha = $this->workspace('alpha.test');
        $location = $this->location($alpha, 'North dock');
        $confirmed = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');
        $held = $this->booking($alpha, $location, CarbonImmutable::parse('2026-06-16 19:00:00', 'UTC'), 'Held Guest', 'Paddle', BookingStatus::Held);
        $this->useTenant($alpha['tenant']);
        $token = app(WidgetTokens::class)->hostedPlain($alpha['tenant']);
        $expected = app(CheckInLink::class)->url($confirmed);
        $this->assertIsString($expected);

        $page = $this->get($this->signedUrl('widget.confirmation', 'alpha.test', $confirmed->id, ['token' => $token]));
        $page->assertOk()->assertSee('qr-svg', false)->assertSee('Check-in code');
        $this->assertSame($expected, $this->decodeQr((string) $page->getContent()));

        $this->get($this->signedUrl('widget.confirmation', 'alpha.test', $held->id, ['token' => $token]))
            ->assertOk()
            ->assertDontSee('qr-svg', false);
    }

    public function test_the_confirmation_email_includes_the_code_when_mail_can_be_delivered(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $alpha = $this->workspace('alpha.test');
        $booking = $this->booking(
            $alpha,
            $this->location($alpha, 'North dock'),
            CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'),
            'Ada North',
            '<script>alert(1)</script>',
            BookingStatus::Confirmed,
            OperationalStatus::NotArrived,
            'guest@example.com',
        );
        $this->useTenant($alpha['tenant']);
        $setting = TenantNotificationSetting::query()->firstOrFail();
        $setting->reply_to = 'front@alpha.test';
        $setting->transactional_emails = ['booking.confirmed' => true];
        $setting->save();
        $expected = app(CheckInLink::class)->url($booking);
        $this->assertIsString($expected);

        app(BookingEvents::class)->confirmed($booking->fresh() ?? $booking);

        Notification::assertSentOnDemand(
            BookingConfirmedNotification::class,
            function (BookingConfirmedNotification $notification, array $channels, object $notifiable) use ($expected): bool {
                $this->assertInstanceOf(AnonymousNotifiable::class, $notifiable);
                $this->assertSame('guest@example.com', $notifiable->routes['mail'] ?? null);
                $mail = $notification->toMail($notifiable);
                $html = (string) $mail->render();
                $this->assertSame('Your booking is confirmed', $mail->subject);
                $this->assertStringContainsString('qr-svg', $html);
                $this->assertStringContainsString('/staff/check-in/', $html);
                $this->assertStringNotContainsString('<script>', $html);
                $this->assertStringContainsString('&lt;script&gt;', $html);
                $this->assertSame($expected, $this->decodeQr($html));
                $addresses = array_map(function (mixed $reply): ?string {
                    if (is_array($reply) && isset($reply[0]) && is_string($reply[0])) {
                        return $reply[0];
                    }

                    return null;
                }, $mail->replyTo);
                $this->assertContains('front@alpha.test', $addresses);

                return $channels === ['mail'];
            },
        );

        $hook = NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$booking->id)->firstOrFail();
        $this->assertSame('sent', $hook->delivery_status);

        $this->artisan('integrations:dispatch')->assertSuccessful();
        $this->useTenant($alpha['tenant']);
        $this->assertSame('sent', $hook->fresh()?->delivery_status);
    }

    public function test_a_turned_off_preference_or_the_array_mailer_does_not_send(): void
    {
        Notification::fake();
        $alpha = $this->workspace('alpha.test');
        $quiet = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Quiet Guest', 'Paddle', BookingStatus::Confirmed, OperationalStatus::NotArrived, 'quiet@example.com');
        $this->useTenant($alpha['tenant']);
        $setting = TenantNotificationSetting::query()->firstOrFail();
        $setting->transactional_emails = ['booking.confirmed' => false];
        $setting->save();
        config(['mail.default' => 'smtp']);
        app(BookingEvents::class)->confirmed($quiet->fresh() ?? $quiet);
        Notification::assertNothingSent();

        $array = $this->booking($alpha, Location::query()->firstOrFail(), CarbonImmutable::parse('2026-06-16 19:00:00', 'UTC'), 'Array Guest', 'Paddle', BookingStatus::Confirmed, OperationalStatus::NotArrived, 'array@example.com');
        TenantNotificationSetting::query()->where('tenant_id', $alpha['tenant']->id)->delete();
        config(['mail.default' => 'array']);
        app(BookingEvents::class)->confirmed($array->fresh() ?? $array);
        Notification::assertNothingSent();
        $hook = NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$array->id)->firstOrFail();
        $this->assertSame('queued', $hook->delivery_status);

        $this->artisan('integrations:dispatch')->assertSuccessful();
        $this->useTenant($alpha['tenant']);
        $hook->refresh();
        $this->assertSame('skipped', $hook->delivery_status);
        $this->assertSame('No transactional email provider is configured.', $hook->delivery_error);
        Notification::assertNothingSent();
    }

    public function test_a_missing_domain_or_the_wrong_workspace_does_not_send(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $booking = $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North', 'Paddle', BookingStatus::Confirmed, OperationalStatus::NotArrived, 'guest@example.com');
        $this->useTenant($alpha['tenant']);
        $alpha['domain']->verified_at = null;
        $alpha['domain']->save();
        app(BookingEvents::class)->confirmed($booking->fresh() ?? $booking);
        Notification::assertNothingSent();
        $this->assertSame('queued', NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$booking->id)->firstOrFail()->delivery_status);

        $alpha['domain']->verified_at = Carbon::now();
        $alpha['domain']->save();
        config(['mail.default' => 'array']);
        app(BookingEvents::class)->confirmed($booking->fresh() ?? $booking);
        config(['mail.default' => 'smtp']);
        $this->useTenant($beta['tenant']);
        app(BookingConfirmationMail::class)->send($booking->fresh() ?? $booking);
        Notification::assertNothingSent();
        $this->useTenant($alpha['tenant']);
        $this->assertSame('queued', NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$booking->id)->firstOrFail()->delivery_status);
    }

    public function test_the_arrivals_page_stays_readable_on_a_narrow_screen(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 16:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $this->booking($alpha, $this->location($alpha, 'North dock'), CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'), 'Ada North');

        $response = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/check-in'))
            ->assertOk();
        $html = (string) $response->getContent();
        $this->assertStringContainsString('booking-cards', $html);
        $this->assertStringContainsString('table-wrap responsive', $html);
        $this->assertStringContainsString('@media (max-width: 390px)', $html);
        $this->assertSame(1, preg_match('/<nav class="tab-bar"[\s\S]*?<\/nav>/', $html, $nav));
        $this->assertSame(5, substr_count($nav[0], '<a '));
        $this->assertStringNotContainsString('Check in', $nav[0]);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function location(array $workspace, string $name, string $timezone = 'America/New_York'): Location
    {
        $this->useTenant($workspace['tenant']);

        return Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function booking(
        array $workspace,
        Location $location,
        CarbonImmutable $start,
        string $customerName,
        string $serviceName = 'Paddle',
        BookingStatus $status = BookingStatus::Confirmed,
        OperationalStatus $operational = OperationalStatus::NotArrived,
        ?string $email = null,
        PaymentStatus $payment = PaymentStatus::Unpaid,
    ): Booking {
        $this->useTenant($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $serviceName,
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $customerName,
            'email' => $email ?? strtolower(str_replace(' ', '.', $customerName)).'@example.com',
        ]);
        $startUtc = $start->utc();

        return Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'payment_status' => $payment,
            'operational_status' => $operational,
            'starts_at' => $startUtc,
            'ends_at' => $startUtc->addHour(),
            'participants' => 1,
            'payload_hash' => str_repeat('ab', 32),
        ]);
    }

    private function waiver(Tenant $tenant, string $name, int $version): WaiverVersion
    {
        $this->useTenant($tenant);

        return WaiverVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'version' => $version,
            'body' => $name.' '.$version,
            'content_hash' => hash('sha256', $name.' '.$version),
        ]);
    }

    private function participant(Tenant $tenant, Booking $booking, string $name, bool $minor): Participant
    {
        $this->useTenant($tenant);

        return Participant::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'name' => $name,
            'is_minor' => $minor,
            'payload_hash' => hash('sha256', $booking->id.$name),
        ]);
    }

    private function sign(
        Tenant $tenant,
        Booking $booking,
        WaiverVersion $version,
        ?Participant $participant,
        string $name,
        bool $minor,
        ?string $guardian,
    ): WaiverSignature {
        $this->useTenant($tenant);

        return WaiverSignature::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'participant_id' => $participant?->id,
            'waiver_version_id' => $version->id,
            'participant_name' => $name,
            'is_minor' => $minor,
            'guardian_name' => $guardian,
            'content_hash' => $version->content_hash,
            'signed_at' => Carbon::parse('2026-06-16 12:00:00', 'UTC'),
        ]);
    }

    private function price(Tenant $tenant, Booking $booking, int $total, int $feeLine): void
    {
        $this->useTenant($tenant);
        PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'v1',
            'payload' => [
                'customer_total_cents' => $total,
                'customer_fee_line_cents' => $feeLine,
            ],
        ]);
    }

    private function payment(Tenant $tenant, Booking $booking, int $cents, string $status): void
    {
        $this->useTenant($tenant);
        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'method' => 'card',
            'kind' => 'balance',
            'status' => $status,
            'amount_cents' => $cents,
        ]);
    }

    private function refund(Tenant $tenant, Booking $booking, int $cents): void
    {
        $this->useTenant($tenant);
        Refund::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'reason' => 'decrease',
            'rules_version' => 'v1',
            'customer_refund_cents' => $cents,
            'customer_fee_refund_cents' => 0,
            'application_fee_refund_cents' => 0,
            'refund_subtotal_cents' => $cents,
            'refund_tax_cents' => 0,
        ]);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function signedUrl(string $name, string $host, string $bookingId, array $parameters = []): string
    {
        URL::forceRootUrl('http://'.$host);

        try {
            return URL::signedRoute($name, array_merge(['booking' => $bookingId], $parameters));
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    private function decodeQr(string $html): string
    {
        $this->assertSame(1, preg_match('/<svg\b.*?<\/svg>/s', $html, $svgMatch));
        $svg = $svgMatch[0];
        $this->assertSame(1, preg_match('/viewBox="0 0 (\d+(?:\.\d+)?) (\d+(?:\.\d+)?)"/', $svg, $box));
        $modulesWide = (int) round((float) $box[1]);
        $modulesHigh = (int) round((float) $box[2]);
        preg_match_all('/<path\b[^>]*\bfill="#000"[^>]*\bd="([^"]+)"/', $svg, $darkPaths);
        $this->assertNotEmpty($darkPaths[1]);
        $dark = [];

        foreach ($darkPaths[1] as $commands) {
            preg_match_all('/M(\d+(?:\.\d+)?) (\d+(?:\.\d+)?) h1 v1 h-1Z/', $commands, $modules, PREG_SET_ORDER);

            foreach ($modules as $module) {
                $dark[(int) round((float) $module[1]).','.(int) round((float) $module[2])] = true;
            }
        }

        $this->assertNotEmpty($dark);

        $scale = 3;
        $source = new class($modulesWide * $scale, $modulesHigh * $scale, $dark, $scale) extends LuminanceSourceAbstract
        {
            /**
             * @param  array<string, true>  $dark
             */
            public function __construct(int $width, int $height, array $dark, int $scale)
            {
                parent::__construct($width, $height);

                for ($y = 0; $y < $height; $y++) {
                    for ($x = 0; $x < $width; $x++) {
                        $on = isset($dark[intdiv($x, $scale).','.intdiv($y, $scale)]);
                        $value = $on ? 0 : 255;
                        $this->setLuminancePixel($value, $value, $value);
                    }
                }
            }

            public static function fromFile(string $path): self
            {
                throw new \RuntimeException('The check-in test decodes an SVG grid.');
            }

            public static function fromBlob(string $blob): self
            {
                throw new \RuntimeException('The check-in test decodes an SVG grid.');
            }
        };

        return (new Decoder)->decode($source)->data;
    }
}
