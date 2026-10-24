<?php

namespace Tests\Feature\Feedback;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Enums\TenantStatus;
use App\Livewire\Staff\Feedback;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerDataExport;
use App\Models\Domain;
use App\Models\FeedbackRequest;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Notifications\FeedbackRequestNotification;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;
use ZipArchive;

class BookingFeedbackTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['mail.default' => 'array']);
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_return_and_completed_queue_one_request_per_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $assets] = $this->rental('dock.test');
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->confirmed($service, '13:00', '15:00', 'feedback-return', 'return@example.com');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout'))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-16 13:30:00', 'UTC'));
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/return'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'ok'],
            ],
        ])->assertOk();
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId), [
            'operational_status' => 'completed',
        ])->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/return'), [
            'conditions' => [
                ['asset_id' => $assets[0]->id, 'condition' => 'ok'],
            ],
        ])->assertStatus(409);

        $direct = $this->booking($workspace, 'direct@example.com', 'Direct Guest');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$direct->id), [
            'operational_status' => 'completed',
        ])->assertOk();
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$direct->id), [
            'operational_status' => 'returned',
        ])->assertOk();

        $held = $this->booking($workspace, 'held@example.com', 'Held Guest', BookingStatus::Held);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$held->id), [
            'operational_status' => 'completed',
        ])->assertOk();

        $cancelled = $this->booking($workspace, 'cancelled@example.com', 'Cancelled Guest', BookingStatus::Cancelled);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$cancelled->id), [
            'operational_status' => 'completed',
        ])->assertOk();

        $this->assertTrue($this->feedbackFor($bookingId)->send_at->equalTo(Carbon::parse('2026-06-16 15:30:00', 'UTC')));
        $this->assertTrue($this->feedbackFor($direct->id)->send_at->equalTo(Carbon::parse('2026-06-16 15:30:00', 'UTC')));
        $this->assertNull($this->feedbackFor($held->id, false));
        $this->assertNull($this->feedbackFor($cancelled->id, false));
        $this->assertSame(2, FeedbackRequest::withoutGlobalScope(TenantScope::class)->count());

        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/notification-settings'), [
            'feedback_delay_minutes' => 45,
        ])->assertOk();
        $later = $this->booking($workspace, 'later@example.com', 'Later Guest');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$later->id), [
            'operational_status' => 'completed',
        ])->assertOk();

        $this->assertTrue($this->feedbackFor($bookingId)->send_at->equalTo(Carbon::parse('2026-06-16 15:30:00', 'UTC')));
        $this->assertTrue($this->feedbackFor($later->id)->send_at->equalTo(Carbon::parse('2026-06-16 14:15:00', 'UTC')));

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(0, FeedbackRequest::query()->count());
        $this->assertNull(FeedbackRequest::query()->where('booking_id', $bookingId)->first());
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($beta);
        $this->getJson($this->tenantUrl('beta.test', '/api/v1/bookings/'.$bookingId))->assertNotFound();
    }

    public function test_the_email_waits_for_the_delay_and_is_sent_once(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $dock = $this->workspace('dock.test', tenantAttributes: ['name' => 'Harbor']);
        TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $dock['tenant']->id)
            ->update(['display_name' => 'Harbor Desk']);
        $this->actingAsWorkspace($dock);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/notification-settings'), [
            'reply_to' => 'desk@dock.test',
        ])->assertOk();

        $booking = $this->booking($dock, 'guest@example.com', 'Guest', BookingStatus::Confirmed, 'Hourly boat');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id), [
            'operational_status' => 'completed',
        ])->assertOk();

        $this->artisan('feedback:dispatch')->assertSuccessful();
        Notification::assertNothingSent();
        $pending = $this->feedbackFor($booking->id);
        $this->assertNull($pending->sent_at);
        $this->assertNull($pending->skipped_at);

        Carbon::setTestNow($now->addHours(2));
        $this->artisan('feedback:dispatch')->assertSuccessful();
        $this->artisan('feedback:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(FeedbackRequestNotification::class, 1);
        Notification::assertSentOnDemand(FeedbackRequestNotification::class, function (FeedbackRequestNotification $notification) use ($booking): bool {
            $mail = $notification->toMail(Notification::route('mail', 'guest@example.com'));

            return $notification->businessName === 'Harbor Desk'
                && $notification->serviceName === 'Hourly boat'
                && $notification->replyTo === 'desk@dock.test'
                && str_contains($notification->rateUrl, 'http://dock.test/feedback/'.$booking->id)
                && str_contains($notification->rateUrl, 'signature=')
                && str_contains($notification->optOutUrl, '/feedback/'.$booking->id.'/opt-out')
                && $mail->subject === 'How was your time with Harbor Desk?';
        });

        $sent = $this->feedbackFor($booking->id);
        $this->assertNotNull($sent->sent_at);
        $hook = NotificationHook::withoutGlobalScope(TenantScope::class)
            ->where('booking_id', $booking->id)
            ->where('kind', 'booking.feedback')
            ->firstOrFail();
        $this->assertSame('sent', $hook->delivery_status);
        $this->assertSame($booking->id, $hook->payload['booking_id']);
        $this->assertSame($sent->id, $hook->payload['feedback_request_id']);
        $this->assertEqualsCanonicalizing(['booking_id', 'feedback_request_id'], array_keys($hook->payload));
        $this->assertSame(1, NotificationHook::withoutGlobalScope(TenantScope::class)->where('kind', 'booking.feedback')->count());

        $soon = $this->workspace('soon.test', tenantAttributes: ['status' => TenantStatus::Onboarding, 'name' => 'Soon Co']);
        $this->actingAsWorkspace($soon);
        $soonBooking = $this->booking($soon, 'soon@example.com', 'Soon Guest');
        $this->patchJson($this->tenantUrl('soon.test', '/api/v1/bookings/'.$soonBooking->id), [
            'operational_status' => 'returned',
        ])->assertOk();
        $this->artisan('feedback:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(FeedbackRequestNotification::class, 1);
        $this->assertNull($this->feedbackFor($soonBooking->id)->sent_at);

        Carbon::setTestNow($now->addHours(4));
        $this->artisan('feedback:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(FeedbackRequestNotification::class, 2);
        $this->assertNotNull($this->feedbackFor($soonBooking->id)->sent_at);

        $slip = $this->workspace('slip.test');
        $this->actingAsWorkspace($slip);
        $slipBooking = $this->booking($slip, 'slip@example.com', 'Slip Guest');
        $this->patchJson($this->tenantUrl('slip.test', '/api/v1/bookings/'.$slipBooking->id), [
            'operational_status' => 'completed',
        ])->assertOk();
        $slip['tenant']->status = TenantStatus::Suspended;
        $slip['tenant']->save();
        Carbon::setTestNow($now->addHours(6));
        $this->artisan('feedback:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(FeedbackRequestNotification::class, 2);
        $waiting = $this->feedbackFor($slipBooking->id);
        $this->assertNull($waiting->sent_at);
        $this->assertNull($waiting->skipped_at);
    }

    public function test_opt_out_toggle_and_mailer_skip_the_email(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', tenantAttributes: ['name' => 'Harbor']);
        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/notification-settings'), [
            'feedback_delay_minutes' => 0,
            'reply_to' => 'desk@dock.test',
        ])->assertOk();

        $arrayBooking = $this->booking($workspace, 'array@example.com', 'Array Guest');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$arrayBooking->id), [
            'operational_status' => 'completed',
        ])->assertOk();
        $this->artisan('feedback:dispatch')->assertSuccessful();
        Notification::assertNothingSent();
        $this->assertSame('The array mailer does not send feedback email.', $this->feedbackFor($arrayBooking->id)->skip_reason);

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/notification-settings'), [
            'transactional_emails' => ['booking.feedback' => false],
        ])->assertOk();
        $off = $this->booking($workspace, 'off@example.com', 'Off Guest');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$off->id), [
            'operational_status' => 'completed',
        ])->assertOk();
        $this->artisan('feedback:dispatch')->assertSuccessful();
        Notification::assertNothingSent();
        $this->assertSame('The workspace turned off feedback email.', $this->feedbackFor($off->id)->skip_reason);
        $this->assertSame('skipped', NotificationHook::withoutGlobalScope(TenantScope::class)
            ->where('booking_id', $off->id)
            ->where('kind', 'booking.feedback')
            ->firstOrFail()
            ->delivery_status);

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/notification-settings'), [
            'transactional_emails' => ['booking.feedback' => true],
        ])->assertOk();
        config(['mail.default' => 'smtp']);
        $silent = $this->booking($workspace, null, 'Silent Guest');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$silent->id), [
            'operational_status' => 'completed',
        ])->assertOk();
        $this->artisan('feedback:dispatch')->assertSuccessful();
        $this->assertSame('The booking has no customer email.', $this->feedbackFor($silent->id)->skip_reason);

        $opt = $this->booking($workspace, 'opt@example.com', 'Opt Guest');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$opt->id), [
            'operational_status' => 'completed',
        ])->assertOk();
        $optOut = $this->signedUrl('feedback.opt-out', 'dock.test', $opt->id);
        $this->get($optOut)->assertOk()->assertSee('Stop feedback emails');
        $customer = Customer::withoutGlobalScope(TenantScope::class)->where('email', 'opt@example.com')->firstOrFail();
        $this->assertNull($customer->feedback_opted_out_at);

        $this->post($optOut, ['_token' => session()->token()])->assertRedirect();
        $customer->refresh();
        $this->assertNotNull($customer->feedback_opted_out_at);
        $stamp = $customer->feedback_opted_out_at->toIso8601String();
        $this->post($optOut, ['_token' => session()->token()])->assertRedirect();
        $this->assertSame($stamp, $customer->fresh()?->feedback_opted_out_at?->toIso8601String());
        $this->get($optOut)->assertOk()->assertSee("You're opted out", false);
        $this->assertTrue(AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'feedback.opted_out')->exists());

        $this->artisan('feedback:dispatch')->assertSuccessful();
        $this->assertSame('The customer opted out of feedback email.', $this->feedbackFor($opt->id)->skip_reason);

        $again = $this->booking($workspace, 'opt@example.com', 'Opt Guest', BookingStatus::Confirmed, 'Hourly boat', $customer);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$again->id), [
            'operational_status' => 'completed',
        ])->assertOk();
        $this->artisan('feedback:dispatch')->assertSuccessful();
        $this->assertSame('The customer opted out of feedback email.', $this->feedbackFor($again->id)->skip_reason);

        $other = $this->booking($workspace, 'other@example.com', 'Other Guest');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$other->id), [
            'operational_status' => 'completed',
        ])->assertOk();
        $this->artisan('feedback:dispatch')->assertSuccessful();
        $this->artisan('feedback:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(FeedbackRequestNotification::class, 1);
        Notification::assertSentOnDemand(FeedbackRequestNotification::class, function (FeedbackRequestNotification $notification) use ($other): bool {
            return str_contains($notification->rateUrl, $other->id) && $notification->replyTo === 'desk@dock.test';
        });
        $this->assertNotNull($this->feedbackFor($other->id)->sent_at);
    }

    public function test_public_rating_page_is_signed_and_tenant_scoped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', tenantAttributes: ['name' => 'Harbor']);
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/notification-settings'), [
            'review_url' => 'https://g.page/harbor/review',
        ])->assertOk();

        $open = $this->booking($workspace, 'open@example.com', 'Open Guest', BookingStatus::Confirmed, 'Hourly boat');
        $four = $this->booking($workspace, 'four@example.com', 'Four Guest', BookingStatus::Confirmed, 'Hourly boat');
        $three = $this->booking($workspace, 'three@example.com', 'Three Guest', BookingStatus::Confirmed, 'Hourly boat');
        $five = $this->booking($workspace, 'five@example.com', 'Five Guest', BookingStatus::Confirmed, 'Hourly boat');
        $plain = $this->booking($workspace, 'plain@example.com', 'Plain Guest');

        foreach ([$open, $four, $three, $five] as $booking) {
            $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id), [
                'operational_status' => 'completed',
            ])->assertOk();
        }

        $unsigned = $this->tenantUrl('dock.test', '/feedback/'.$open->id);
        $this->get($unsigned)->assertForbidden();
        $signed = $this->signedUrl('feedback.show', 'dock.test', $open->id);
        $this->get(str_replace('signature=', 'signature=deadbeef', $signed))->assertForbidden();
        $this->get(str_replace('http://dock.test', 'http://beta.test', $signed))->assertNotFound();
        $this->get($this->signedUrl('feedback.show', 'dock.test', $plain->id))->assertNotFound();

        $this->get($signed)
            ->assertOk()
            ->assertSee('How was Hourly boat?', false)
            ->assertSee('Send rating')
            ->assertSee("Don't ask me for feedback again", false);

        $this->post($signed, [
            '_token' => session()->token(),
            'rating' => 6,
            'comment' => 'Too high',
        ])->assertSessionHasErrors('rating');
        $this->post($signed, [
            '_token' => session()->token(),
            'rating' => 5,
            'comment' => str_repeat('a', 2001),
        ])->assertSessionHasErrors('comment');
        $this->assertNull($this->feedbackFor($open->id)->rating);

        $this->followingRedirects()->post($signed, [
            '_token' => session()->token(),
            'rating' => 5,
            'comment' => '  <script>alert(1)</script>  ',
        ])->assertOk()
            ->assertSee('Thanks for rating your visit')
            ->assertSee('Share a public review')
            ->assertSee('https://g.page/harbor/review', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $stored = $this->feedbackFor($open->id);
        $this->assertSame(5, $stored->rating);
        $this->assertSame('<script>alert(1)</script>', $stored->comment);
        $audit = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'feedback.rated')->firstOrFail();
        $this->assertSame(5, $audit->properties['rating']);
        $this->assertArrayNotHasKey('comment', $audit->properties);

        $this->followingRedirects()->post($signed, [
            '_token' => session()->token(),
            'rating' => 1,
            'comment' => 'Changed my mind',
        ])->assertOk()->assertSee('<script>alert(1)</script>');
        $stored->refresh();
        $this->assertSame(5, $stored->rating);
        $this->assertSame('<script>alert(1)</script>', $stored->comment);

        $fourUrl = $this->signedUrl('feedback.show', 'dock.test', $four->id);
        $this->get($fourUrl)->assertOk();
        $this->followingRedirects()->post($fourUrl, [
            '_token' => session()->token(),
            'rating' => 4,
            'comment' => 'Good trip',
        ])->assertOk()->assertSee('Share a public review')->assertDontSee('<script>alert(1)</script>', false);

        $threeUrl = $this->signedUrl('feedback.show', 'dock.test', $three->id);
        $this->get($threeUrl)->assertOk();
        $this->followingRedirects()->post($threeUrl, [
            '_token' => session()->token(),
            'rating' => 3,
        ])->assertOk()->assertDontSee('Share a public review')->assertDontSee('https://g.page/harbor/review', false);

        TenantNotificationSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['review_url' => 'http://evil.test/review']);
        $fiveUrl = $this->signedUrl('feedback.show', 'dock.test', $five->id);
        $this->get($fiveUrl)->assertOk();
        $this->followingRedirects()->post($fiveUrl, [
            '_token' => session()->token(),
            'rating' => 5,
            'comment' => 'Loved it',
        ])->assertOk()
            ->assertDontSee('Share a public review')
            ->assertDontSee('http://evil.test/review', false);

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(0, FeedbackRequest::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_staff_feedback_page_lists_averages_and_filters(): void
    {
        $alpha = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Co']);
        $beta = $this->workspace('beta.test', tenantAttributes: ['name' => 'Beta Co']);
        $staff = $this->workspace('staff.test', 'staff');
        app(TenantManager::class)->set($alpha['tenant']);
        $location = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $kayak = Service::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak hour',
            'kind' => ServiceKind::Rental,
        ]);
        $canoe = Service::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Canoe hour',
            'kind' => ServiceKind::Rental,
        ]);
        $ada = Customer::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
        ]);
        $bea = Customer::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Bea Canoe',
            'email' => 'bea@example.com',
        ]);
        $kayakBooking = $this->storedBooking($alpha['tenant']->id, $location->id, $kayak->id, $ada->id);
        $canoeBooking = $this->storedBooking($alpha['tenant']->id, $location->id, $canoe->id, $bea->id);
        FeedbackRequest::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $kayakBooking->id,
            'customer_id' => $ada->id,
            'rating' => 5,
            'comment' => 'Loved the kayak',
        ]);
        FeedbackRequest::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $canoeBooking->id,
            'customer_id' => $bea->id,
            'rating' => 2,
            'comment' => null,
        ]);
        FeedbackRequest::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'rating' => null,
            'comment' => null,
            'sent_at' => null,
            'rated_at' => null,
            'send_at' => now()->addHour(),
        ]);
        app(TenantManager::class)->forget();

        FeedbackRequest::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'rating' => 1,
            'comment' => 'Beta secret',
        ]);

        $this->get($this->tenantUrl('alpha.test', '/staff/feedback'))->assertRedirect('/staff/login');

        $this->actingAs($staff['user'])
            ->get($this->tenantUrl('staff.test', '/staff/feedback'))
            ->assertOk()
            ->assertSee('No ratings yet.')
            ->assertDontSee('Send after');

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/feedback'))
            ->assertOk()
            ->assertSee('3.5 average from 2 ratings.')
            ->assertSee('Ada Kayak')
            ->assertSee('Bea Canoe')
            ->assertSee('Loved the kayak')
            ->assertSee('1 feedback request is waiting to send.')
            ->assertSee('Send after')
            ->assertDontSee('Beta secret');

        $this->get($this->tenantUrl('alpha.test', '/staff/feedback?stars=5'))
            ->assertOk()
            ->assertSee('5.0 average from 1 rating.')
            ->assertSee('Ada Kayak')
            ->assertDontSee('Bea Canoe');

        $this->get($this->tenantUrl('alpha.test', '/staff/feedback?service='.$kayak->id))
            ->assertOk()
            ->assertSee('Ada Kayak')
            ->assertDontSee('Bea Canoe');

        $this->get($this->tenantUrl('alpha.test', '/staff/feedback?commented=yes'))
            ->assertOk()
            ->assertSee('Loved the kayak')
            ->assertDontSee('Bea Canoe');

        $this->get($this->tenantUrl('alpha.test', '/staff/feedback?commented=no'))
            ->assertOk()
            ->assertSee('Bea Canoe')
            ->assertDontSee('Loved the kayak');

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('Feedback');
    }

    public function test_settings_are_per_workspace(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($alpha);

        $shown = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/notification-settings'))
            ->assertOk()
            ->assertJsonPath('data.feedback_delay_minutes', 120)
            ->assertJsonPath('data.review_url', null);
        $emails = $shown->json('data.transactional_emails');
        $this->assertIsArray($emails);
        $this->assertTrue($emails['booking.feedback']);

        $updated = $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/notification-settings'), [
            'feedback_delay_minutes' => 45,
            'review_url' => 'https://g.page/harbor/review',
        ])->assertOk()
            ->assertJsonPath('data.feedback_delay_minutes', 45)
            ->assertJsonPath('data.review_url', 'https://g.page/harbor/review');
        $updatedEmails = $updated->json('data.transactional_emails');
        $this->assertIsArray($updatedEmails);
        $this->assertTrue($updatedEmails['booking.feedback']);

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/notification-settings'), [
            'review_url' => 'http://example.com/review',
        ])->assertUnprocessable()->assertJsonValidationErrors('review_url');

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/notification-settings'), [
            'review_url' => 'https://user:pass@g.page/harbor/review',
        ])->assertUnprocessable()->assertJsonValidationErrors('review_url');

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/notification-settings'), [
            'feedback_delay_minutes' => 10081,
        ])->assertUnprocessable()->assertJsonValidationErrors('feedback_delay_minutes');

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/notification-settings'), [
            'feedback_delay_minutes' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors('feedback_delay_minutes');

        $staff = $this->workspace('staff.test', 'staff');
        $this->actingAsWorkspace($staff);
        $this->patchJson($this->tenantUrl('staff.test', '/api/v1/notification-settings'), [
            'feedback_delay_minutes' => 10,
        ])->assertForbidden();

        app(TenantManager::class)->set($staff['tenant']);
        $this->actingAs($staff['user']);
        Livewire::test(Feedback::class)->call('saveSettings')->assertForbidden();
        app(TenantManager::class)->forget();

        $manager = $this->workspace('manager.test', 'manager');
        $this->actingAsWorkspace($manager);
        $this->patchJson($this->tenantUrl('manager.test', '/api/v1/notification-settings'), [
            'feedback_delay_minutes' => 30,
            'review_url' => 'https://g.page/manager/review',
        ])->assertOk()
            ->assertJsonPath('data.feedback_delay_minutes', 30)
            ->assertJsonPath('data.review_url', 'https://g.page/manager/review');

        $this->actingAsWorkspace($beta);
        $this->getJson($this->tenantUrl('beta.test', '/api/v1/notification-settings'))
            ->assertOk()
            ->assertJsonPath('data.feedback_delay_minutes', 120)
            ->assertJsonPath('data.review_url', null);

        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);
        Livewire::test(Feedback::class)
            ->set('reviewUrl', 'http://example.com/review')
            ->call('saveSettings')
            ->assertHasErrors('reviewUrl');
        $this->assertSame('https://g.page/harbor/review', TenantNotificationSetting::query()->firstOrFail()->review_url);

        Livewire::test(Feedback::class)
            ->set('delayMinutes', 15)
            ->set('reviewUrl', 'https://g.page/alpha/review')
            ->set('feedbackEnabled', false)
            ->call('saveSettings')
            ->assertHasNoErrors();
        $saved = TenantNotificationSetting::query()->firstOrFail();
        $this->assertSame(15, $saved->feedbackDelayMinutes());
        $this->assertSame('https://g.page/alpha/review', $saved->review_url);
        $this->assertFalse($saved->allows('booking.feedback'));
        $this->assertTrue(AuditLog::query()->where('action', 'notification_settings.updated')->exists());
        app(TenantManager::class)->forget();

        $this->assertSame(120, TenantNotificationSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->firstOrFail()
            ->feedbackDelayMinutes());
    }

    public function test_export_and_deletion_include_feedback(): void
    {
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('other.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
            'feedback_opted_out_at' => Carbon::parse('2026-06-16 12:00:00', 'UTC'),
        ]);
        $neighbor = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Bea Paddle',
            'email' => 'bea@example.com',
        ]);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak hour',
            'kind' => ServiceKind::Rental,
        ]);
        $booking = $this->storedBooking($workspace['tenant']->id, $location->id, $service->id, $customer->id);
        $neighborBooking = $this->storedBooking($workspace['tenant']->id, $location->id, $service->id, $neighbor->id);
        FeedbackRequest::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'rating' => 4,
            'comment' => 'Secret paddle note',
            'skip_reason' => 'ada@example.com left a note',
        ]);
        FeedbackRequest::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $neighborBooking->id,
            'customer_id' => $neighbor->id,
            'rating' => 2,
            'comment' => 'Neighbor paddle',
        ]);
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($other['tenant']);
        $foreignCustomer = Customer::query()->create([
            'tenant_id' => $other['tenant']->id,
            'name' => 'Cara Other',
            'email' => 'cara@example.com',
        ]);
        $foreignLocation = Location::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $foreignService = Service::query()->create([
            'tenant_id' => $other['tenant']->id,
            'location_id' => $foreignLocation->id,
            'name' => 'Other hour',
            'kind' => ServiceKind::Rental,
        ]);
        $foreignBooking = $this->storedBooking($other['tenant']->id, $foreignLocation->id, $foreignService->id, $foreignCustomer->id);
        FeedbackRequest::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'booking_id' => $foreignBooking->id,
            'customer_id' => $foreignCustomer->id,
            'comment' => 'Foreign paddle',
        ]);
        app(TenantManager::class)->forget();

        Storage::fake('local');
        $this->actingAsWorkspace($workspace);
        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/exports'))
            ->assertStatus(202)
            ->assertJsonPath('data.row_counts.feedback_requests', 1)
            ->assertJsonPath('data.status', 'completed');
        $exportId = $created->json('data.id');
        $this->assertIsString($exportId);

        $path = CustomerDataExport::withoutGlobalScope(TenantScope::class)->findOrFail($exportId)->path;
        $this->assertIsString($path);
        $entries = $this->zipEntries($path);
        $this->assertSame('Secret paddle note', $entries['feedback_requests.json'][0]['comment']);
        $this->assertSame(4, $entries['feedback_requests.json'][0]['rating']);
        $this->assertNotNull($entries['customer.json']['feedback_opted_out_at']);
        $blob = json_encode($entries, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Neighbor paddle', $blob);
        $this->assertStringNotContainsString('Foreign paddle', $blob);
        $this->assertStringNotContainsString('bea@example.com', $blob);

        $erased = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/deletion'))
            ->assertOk()
            ->assertJsonPath('data.idempotent', false);
        $deletionId = $erased->json('data.id');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/deletion'))
            ->assertOk()
            ->assertJsonPath('data.id', $deletionId)
            ->assertJsonPath('data.idempotent', true);

        $fresh = Customer::withoutGlobalScope(TenantScope::class)->findOrFail($customer->id);
        $this->assertSame('Removed customer', $fresh->name);
        $this->assertNull($fresh->email);
        $request = FeedbackRequest::withoutGlobalScope(TenantScope::class)->where('booking_id', $booking->id)->firstOrFail();
        $this->assertNull($request->comment);
        $this->assertSame(4, $request->rating);
        $this->assertStringNotContainsString('ada@example.com', (string) $request->skip_reason);
        $neighborRequest = FeedbackRequest::withoutGlobalScope(TenantScope::class)->where('booking_id', $neighborBooking->id)->firstOrFail();
        $this->assertSame('Neighbor paddle', $neighborRequest->comment);

        $after = $this->zipEntries($path);
        $this->assertSame('Secret paddle note', $after['feedback_requests.json'][0]['comment']);
    }

    public function test_feedback_dispatch_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'feedback:dispatch'),
        );

        $this->assertInstanceOf(ScheduledEvent::class, $event);
        $this->assertSame('*/5 * * * *', $event->expression);

        Carbon::setTestNow(Carbon::parse('2026-06-16 12:03:00', 'UTC'));
        $this->assertFalse($event->isDue(app()));

        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $this->assertTrue($event->isDue(app()));
        $this->artisan('schedule:run')
            ->expectsOutputToContain('feedback:dispatch')
            ->assertSuccessful();
    }

    private function signedUrl(string $name, string $host, string $bookingId): string
    {
        URL::forceRootUrl('http://'.$host);

        try {
            return URL::signedRoute($name, ['booking' => $bookingId]);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function feedbackFor(string $bookingId, bool $required = true): ?FeedbackRequest
    {
        $request = FeedbackRequest::withoutGlobalScope(TenantScope::class)->where('booking_id', $bookingId)->first();

        if ($required) {
            $this->assertInstanceOf(FeedbackRequest::class, $request);
        }

        return $request instanceof FeedbackRequest ? $request : null;
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function booking(
        array $workspace,
        ?string $email,
        string $name,
        BookingStatus $status = BookingStatus::Confirmed,
        string $serviceName = 'Hourly boat',
        ?Customer $customer = null,
    ): Booking {
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);

        try {
            $service = Service::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'location_id' => $location->id,
                'name' => $serviceName,
                'kind' => ServiceKind::Rental,
            ]);
            $customerId = $customer?->id;

            if ($customerId === null && $email !== null) {
                $customerId = Customer::query()->create([
                    'tenant_id' => $workspace['tenant']->id,
                    'name' => $name,
                    'email' => $email,
                ])->id;
            }

            if ($customerId === null && $email === null) {
                $customerId = Customer::query()->create([
                    'tenant_id' => $workspace['tenant']->id,
                    'name' => $name,
                    'email' => null,
                ])->id;
            }

            $start = CarbonImmutable::now('UTC')->subHour();

            return Booking::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'location_id' => $location->id,
                'service_id' => $service->id,
                'customer_id' => $customerId,
                'status' => $status,
                'payment_status' => PaymentStatus::Paid,
                'operational_status' => OperationalStatus::NotArrived,
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
                'participants' => 1,
                'payload_hash' => hash('sha256', $workspace['tenant']->id.$name.$serviceName.($email ?? '').$status->value.spl_object_id($location)),
            ]);
        } finally {
            app(TenantManager::class)->forget();
        }
    }

    private function storedBooking(string $tenantId, string $locationId, string $serviceId, string $customerId): Booking
    {
        $start = CarbonImmutable::parse('2026-06-16 15:00:00', 'UTC');

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'service_id' => $serviceId,
            'customer_id' => $customerId,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'participants' => 1,
            'payload_hash' => hash('sha256', $tenantId.$customerId.$serviceId.uniqid('', true)),
        ]);
    }

    private function confirmed(Service $service, string $start, string $end, string $key, string $email): string
    {
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T'.$start.':00Z',
            'ends_at' => '2026-06-16T'.$end.':00Z',
            'participants' => 1,
            'customer_name' => 'Return Guest',
            'customer_email' => $email,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($bookingId);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: list<Asset>}
     */
    private function rental(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => ServiceKind::Rental,
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service, [$asset]];
    }

    /**
     * @return array<string, mixed>
     */
    private function zipEntries(string $relative): array
    {
        $archive = new ZipArchive;
        $opened = $archive->open(Storage::disk('local')->path($relative));
        $this->assertSame(true, $opened);

        $entries = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            $contents = $name === false ? false : $archive->getFromIndex($index);
            $this->assertIsString($name);
            $this->assertIsString($contents);
            $entries[$name] = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        }

        $archive->close();

        return $entries;
    }
}
