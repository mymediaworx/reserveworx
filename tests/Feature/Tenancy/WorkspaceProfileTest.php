<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TransactionalEmail;
use App\Models\TenantBranding;
use App\Models\TenantNotificationSetting;
use App\Policies\TenantBrandingPolicy;
use App\Policies\TenantNotificationSettingPolicy;
use App\Services\Branding\CurrentWorkspaceBranding;
use App\Services\Branding\WorkspaceBranding;
use App\Services\Notifications\TransactionalEmailPreferences;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class WorkspaceProfileTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_owner_and_manager_can_update_branding_and_staff_can_only_read_it(): void
    {
        $owner = $this->workspace('owner.test', tenantAttributes: ['name' => 'Owner Co']);
        $this->actingAsWorkspace($owner);

        $this->getJson($this->tenantUrl('owner.test', '/api/v1/branding'))
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Owner Co')
            ->assertJsonPath('data.primary_color', '#0E7C86')
            ->assertJsonPath('data.accent_color', '#F26B3A')
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.support_email', null)
            ->assertJsonPath('data.tenant_id', $owner['tenant']->id);

        $this->patchJson($this->tenantUrl('owner.test', '/api/v1/branding'), [
            'display_name' => 'Harbor Kayaks',
            'primary_color' => '#112233',
            'accent_color' => '#abcdef',
            'support_email' => 'Hi@Harbor.test',
            'support_phone' => '+1 (555) 010-1234',
        ])->assertOk()
            ->assertJsonPath('data.display_name', 'Harbor Kayaks')
            ->assertJsonPath('data.primary_color', '#112233')
            ->assertJsonPath('data.accent_color', '#ABCDEF')
            ->assertJsonPath('data.support_email', 'hi@harbor.test')
            ->assertJsonPath('data.support_phone', '+1 (555) 010-1234');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'branding.updated',
            'tenant_id' => $owner['tenant']->id,
            'actor_user_id' => $owner['user']->id,
        ]);

        $manager = $this->workspace('manager.test', 'manager', ['name' => 'Manager Co']);
        $this->actingAsWorkspace($manager);

        $this->patchJson($this->tenantUrl('manager.test', '/api/v1/branding'), [
            'display_name' => 'Manager Harbor',
        ])->assertOk()->assertJsonPath('data.display_name', 'Manager Harbor');

        $staff = $this->workspace('staff.test', 'staff', ['name' => 'Staff Co']);
        $this->actingAsWorkspace($staff);

        $this->getJson($this->tenantUrl('staff.test', '/api/v1/branding'))
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Staff Co');

        $this->patchJson($this->tenantUrl('staff.test', '/api/v1/branding'), [
            'display_name' => 'Nope',
            'primary_color' => 'red',
        ])->assertForbidden();

        $this->assertSame('Staff Co', TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $staff['tenant']->id)
            ->value('display_name'));
    }

    public function test_branding_validation_rejects_bad_colors_logos_and_contact_details(): void
    {
        $owner = $this->workspace('owner.test', tenantAttributes: ['name' => 'Owner Co']);
        $this->actingAsWorkspace($owner);
        $url = $this->tenantUrl('owner.test', '/api/v1/branding');

        $this->patchJson($url, ['primary_color' => '#GGG'])->assertUnprocessable()->assertJsonValidationErrors('primary_color');
        $this->patchJson($url, ['display_name' => ' '])->assertUnprocessable()->assertJsonValidationErrors('display_name');
        $this->patchJson($url, ['support_email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('support_email');
        $this->patchJson($url, ['support_phone' => 'call me'])->assertUnprocessable()->assertJsonValidationErrors('support_phone');
        $this->patchJson($url, ['support_phone' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('support_phone');
        $this->patchJson($url, ['logo_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('logo_url');
        $this->patchJson($url, ['logo_url' => 'https://user:secret@cdn.example.test/logo.png'])->assertUnprocessable()->assertJsonValidationErrors('logo_url');

        $this->call('PATCH', $url, [
            'logo_url' => 'https://cdn.example.test/logo.png',
        ], [], [
            'logo' => UploadedFile::fake()->image('logo.png', 8, 8),
        ], ['HTTP_ACCEPT' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');

        $this->call('PATCH', $url, [], [], [
            'logo' => UploadedFile::fake()->create('logo.svg', 20, 'image/svg+xml'),
        ], ['HTTP_ACCEPT' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');

        $this->assertSame('Owner Co', TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $owner['tenant']->id)
            ->value('display_name'));
    }

    public function test_an_uploaded_logo_stays_on_that_workspace_and_is_not_served_for_another(): void
    {
        $alpha = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Co']);
        $beta = $this->workspace('beta.test', tenantAttributes: ['name' => 'Beta Co']);
        $this->actingAsWorkspace($alpha);

        $file = UploadedFile::fake()->image('logo.png', 16, 16);
        $encoded = base64_encode((string) file_get_contents($file->getPathname()));

        $uploaded = $this->call('PATCH', $this->tenantUrl('alpha.test', '/api/v1/branding'), [
            'display_name' => 'Alpha Harbor',
        ], [], [
            'logo' => $file,
        ], ['HTTP_ACCEPT' => 'application/json'])->assertOk();

        $logoUrl = $uploaded->json('data.logo_url');
        $this->assertIsString($logoUrl);
        $this->assertStringContainsString('http://alpha.test/api/v1/branding/logo?v=', $logoUrl);

        $stored = TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->firstOrFail();
        $this->assertNotNull($stored->logo_path);
        $this->assertStringStartsWith('branding/'.$alpha['tenant']->id.'/', (string) $stored->logo_path);
        $this->assertNull($stored->logo_url);
        Storage::disk('local')->assertExists((string) $stored->logo_path);

        $this->get($logoUrl)
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->get($this->tenantUrl('beta.test', '/api/v1/branding/logo'))->assertNotFound();

        $betaRow = TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->firstOrFail();
        $betaRow->forceFill([
            'logo_path' => $stored->logo_path,
            'logo_mime' => 'image/png',
        ])->save();

        $this->get($this->tenantUrl('beta.test', '/api/v1/branding/logo'))->assertNotFound();
        Storage::disk('local')->assertExists((string) $stored->logo_path);

        $this->actingAsWorkspace($beta);
        $this->getJson($this->tenantUrl('beta.test', '/api/v1/branding'))
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Beta Co')
            ->assertJsonPath('data.logo_url', null);

        $this->actingAsWorkspace($alpha);
        $replaced = $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/branding'), [
            'logo_base64' => $encoded,
        ])->assertOk();
        $this->assertStringContainsString('http://alpha.test/api/v1/branding/logo?v=', (string) $replaced->json('data.logo_url'));

        $external = $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/branding'), [
            'logo_url' => 'https://cdn.example.test/logo.png',
        ])->assertOk();
        $this->assertSame('https://cdn.example.test/logo.png', $external->json('data.logo_url'));
        $this->get($this->tenantUrl('alpha.test', '/api/v1/branding/logo'))->assertNotFound();

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/branding'), [
            'remove_logo' => true,
        ])->assertOk()->assertJsonPath('data.logo_url', null);

        $cleared = TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->firstOrFail();
        $this->assertNull($cleared->logo_path);
        $this->assertNull($cleared->logo_url);

        app(TenantManager::class)->set($alpha['tenant']);
        $foreign = TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->firstOrFail();
        $this->assertFalse(app(TenantBrandingPolicy::class)->view($alpha['user'], $foreign));
        $this->assertFalse(app(TenantBrandingPolicy::class)->update($alpha['user'], $foreign));
    }

    public function test_notification_settings_are_per_workspace_and_limited_to_owner_and_manager(): void
    {
        $alpha = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Co']);
        $beta = $this->workspace('beta.test', tenantAttributes: ['name' => 'Beta Co']);
        $this->actingAsWorkspace($alpha);

        $shown = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/notification-settings'))->assertOk();
        $emails = $shown->json('data.transactional_emails');
        $this->assertIsArray($emails);
        $this->assertEquals(TransactionalEmail::defaults(), $emails);
        $this->assertSame([], $shown->json('data.staff_alert_recipients'));
        $this->assertNull($shown->json('data.reply_to'));

        $updated = $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/notification-settings'), [
            'reply_to' => 'Desk@Alpha.test',
            'staff_alert_recipients' => ['Owner@Alpha.test', 'desk@alpha.test'],
            'transactional_emails' => ['booking.confirmed' => false],
        ])->assertOk()
            ->assertJsonPath('data.reply_to', 'desk@alpha.test')
            ->assertJsonPath('data.staff_alert_recipients', ['owner@alpha.test', 'desk@alpha.test']);
        $updatedEmails = $updated->json('data.transactional_emails');
        $this->assertIsArray($updatedEmails);
        $this->assertFalse($updatedEmails['booking.confirmed']);
        $this->assertTrue($updatedEmails['booking.cancelled']);
        $this->assertTrue($updatedEmails['refund.issued']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'notification_settings.updated',
            'tenant_id' => $alpha['tenant']->id,
            'actor_user_id' => $alpha['user']->id,
        ]);

        $preferences = app(TransactionalEmailPreferences::class);
        $this->assertFalse($preferences->enabled($alpha['tenant']->id, TransactionalEmail::BookingConfirmed->value));
        $this->assertTrue($preferences->enabled($beta['tenant']->id, TransactionalEmail::BookingConfirmed->value));
        $this->assertFalse($preferences->enabled($alpha['tenant']->id, 'spam.blast'));

        $this->actingAsWorkspace($beta);
        $betaShown = $this->getJson($this->tenantUrl('beta.test', '/api/v1/notification-settings'))
            ->assertOk()
            ->assertJsonPath('data.reply_to', null);
        $betaEmails = $betaShown->json('data.transactional_emails');
        $this->assertIsArray($betaEmails);
        $this->assertTrue($betaEmails['booking.confirmed']);

        $this->patchJson($this->tenantUrl('beta.test', '/api/v1/notification-settings'), [
            'transactional_emails' => ['spam.blast' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('transactional_emails');

        $this->patchJson($this->tenantUrl('beta.test', '/api/v1/notification-settings'), [
            'staff_alert_recipients' => ['Owner@Beta.test', 'owner@beta.test'],
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_alert_recipients.1');

        $tooMany = [];
        foreach (range(1, 21) as $index) {
            $tooMany[] = 'person'.$index.'@beta.test';
        }
        $this->patchJson($this->tenantUrl('beta.test', '/api/v1/notification-settings'), [
            'staff_alert_recipients' => $tooMany,
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_alert_recipients');

        $this->patchJson($this->tenantUrl('beta.test', '/api/v1/notification-settings'), [
            'reply_to' => 'not-an-email',
        ])->assertUnprocessable()->assertJsonValidationErrors('reply_to');

        $staff = $this->workspace('staff.test', 'staff');
        $this->actingAsWorkspace($staff);
        $this->getJson($this->tenantUrl('staff.test', '/api/v1/notification-settings'))->assertOk();
        $this->patchJson($this->tenantUrl('staff.test', '/api/v1/notification-settings'), [
            'reply_to' => 'staff@example.test',
        ])->assertForbidden();
        $this->assertNull(TenantNotificationSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $staff['tenant']->id)
            ->value('reply_to'));

        $manager = $this->workspace('manager.test', 'manager');
        $this->actingAsWorkspace($manager);
        $this->patchJson($this->tenantUrl('manager.test', '/api/v1/notification-settings'), [
            'reply_to' => 'manager@example.test',
        ])->assertOk()->assertJsonPath('data.reply_to', 'manager@example.test');

        app(TenantManager::class)->set($alpha['tenant']);
        $foreign = TenantNotificationSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->firstOrFail();
        $this->assertFalse(app(TenantNotificationSettingPolicy::class)->view($alpha['user'], $foreign));
        $this->assertFalse(app(TenantNotificationSettingPolicy::class)->update($alpha['user'], $foreign));
    }

    public function test_existing_views_receive_only_the_current_workspaces_branding(): void
    {
        $alpha = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Co']);
        $beta = $this->workspace('beta.test', tenantAttributes: ['name' => 'Beta Co']);

        TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->update([
                'display_name' => 'Alpha Harbor',
                'primary_color' => '#112233',
                'support_email' => 'alpha@example.com',
            ]);
        TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->update([
                'display_name' => 'Beta Dock',
                'support_email' => 'beta@example.com',
            ]);

        $captured = null;
        View::composer('*', function ($view) use (&$captured): void {
            if ($view->name() === 'staff.auth.login') {
                $captured = $view->getData()['workspaceBranding'] ?? null;
            }
        });

        app(TenantManager::class)->set($alpha['tenant']);
        $welcome = view('welcome');
        $welcome->render();
        $branding = $welcome->getData()['workspaceBranding'] ?? null;

        $this->assertInstanceOf(WorkspaceBranding::class, $branding);
        $this->assertSame($alpha['tenant']->id, $branding->tenantId);
        $this->assertSame('Alpha Harbor', $branding->displayName);
        $this->assertSame('#112233', $branding->primaryColor);
        $this->assertSame('alpha@example.com', $branding->supportEmail);

        app(TenantManager::class)->forget();
        request()->attributes->set('tenant', $beta['tenant']);
        $resolved = app(CurrentWorkspaceBranding::class)->resolve();
        $this->assertSame('Beta Dock', $resolved->displayName);
        $this->assertSame('beta@example.com', $resolved->supportEmail);
        $this->assertNotSame('alpha@example.com', $resolved->supportEmail);

        $this->get($this->tenantUrl('alpha.test', '/staff/login'))->assertOk()->assertSee('Team sign in');
        $this->assertInstanceOf(WorkspaceBranding::class, $captured);
        $this->assertSame('Alpha Harbor', $captured->displayName);
        $this->assertSame('alpha@example.com', $captured->supportEmail);

        $this->get($this->tenantUrl('alpha.test', '/'))
            ->assertOk()
            ->assertSee('<h1>Alpha Co</h1>', false)
            ->assertDontSee('Beta Dock');
        $this->get($this->tenantUrl('alpha.test', '/staff/login'))
            ->assertOk()
            ->assertSee('Team sign in');
        $this->get($this->tenantUrl('beta.test', '/'))
            ->assertOk()
            ->assertSee('<h1>Beta Co</h1>', false)
            ->assertDontSee('Alpha Harbor');
    }
}
