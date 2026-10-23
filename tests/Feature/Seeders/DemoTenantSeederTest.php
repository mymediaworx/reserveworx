<?php

namespace Tests\Feature\Seeders;

use App\Enums\FeePayer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoTenantSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_demo_tenants_use_different_fee_payers_and_passwords_stay_out_of_the_database_plaintext(): void
    {
        $path = sys_get_temp_dir().'/reserveworx-demo-logins-'.bin2hex(random_bytes(4)).'.txt';
        $this->app['config']->set('app.env', 'testing');
        putenv('DEMO_LOGIN_FILE='.$path);
        $_ENV['DEMO_LOGIN_FILE'] = $path;
        $_SERVER['DEMO_LOGIN_FILE'] = $path;

        $this->seed(DatabaseSeeder::class);

        $harbor = Tenant::query()->where('slug', 'harbor-demo')->firstOrFail();
        $inland = Tenant::query()->where('slug', 'inland-demo')->firstOrFail();

        $this->assertSame(FeePayer::CustomerPaid, $harbor->settings()->firstOrFail()->fee_payer);
        $this->assertSame(FeePayer::OperatorAbsorbed, $inland->settings()->firstOrFail()->fee_payer);
        $this->assertNotSame($harbor->settings()->firstOrFail()->fee_payer, $inland->settings()->firstOrFail()->fee_payer);
        $this->assertNotNull($harbor->domains()->where('hostname', 'harbor-demo.reserveworx.test')->firstOrFail()->verified_at);
        $this->assertNotNull($inland->domains()->where('hostname', 'inland-demo.reserveworx.test')->firstOrFail()->verified_at);

        $records = $this->records($path);
        $this->assertCount(2, $records);

        foreach ($records as $record) {
            $user = User::query()->where('email', $record['email'])->firstOrFail();
            $this->assertTrue(Hash::check($record['password'], $user->password));
            $this->assertNotSame($record['password'], $user->getRawOriginal('password'));
            $this->assertTrue($user->memberships()->where('status', 'active')->exists());
        }

        @unlink($path);
        putenv('DEMO_LOGIN_FILE');
        unset($_ENV['DEMO_LOGIN_FILE'], $_SERVER['DEMO_LOGIN_FILE']);
    }

    /**
     * @return list<array{email: string, password: string}>
     */
    private function records(string $path): array
    {
        $this->assertFileExists($path);
        $records = [];
        $current = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                if ($current !== []) {
                    $records[] = $current;
                    $current = [];
                }

                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $current[$key] = $value;
        }

        if ($current !== []) {
            $records[] = $current;
        }

        return $records;
    }
}
