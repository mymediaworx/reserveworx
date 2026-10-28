<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class PruneHostFilesTest extends TestCase
{
    private string $storage;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = $this->app->storagePath();
        $this->storage = sys_get_temp_dir().'/rw-storage-'.bin2hex(random_bytes(4));
        foreach (['framework/sessions', 'framework/views', 'framework/cache/data/ab', 'logs', 'app/public'] as $dir) {
            mkdir($this->storage.'/'.$dir, 0777, true);
        }
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->original);
        exec('rm -rf '.escapeshellarg($this->storage));

        parent::tearDown();
    }

    private function touchFile(string $relative, int $daysOld): string
    {
        $path = $this->storage.'/'.$relative;
        file_put_contents($path, 'x');
        touch($path, time() - ($daysOld * 86400) - 3600);

        return $path;
    }

    public function test_it_removes_only_stale_files_in_the_four_regenerable_trees(): void
    {
        $oldSession = $this->touchFile('framework/sessions/old', 3);
        $freshSession = $this->touchFile('framework/sessions/fresh', 1);
        $oldView = $this->touchFile('framework/views/old.php', 8);
        $freshView = $this->touchFile('framework/views/fresh.php', 6);
        $oldCache = $this->touchFile('framework/cache/data/ab/old', 8);
        $oldLog = $this->touchFile('logs/laravel-2020-01-01.log', 8);
        $freshLog = $this->touchFile('logs/laravel-2099-01-01.log', 1);
        $gitignore = $this->touchFile('logs/.gitignore', 30);
        $upload = $this->touchFile('app/public/photo.jpg', 400);

        $this->artisan('host:prune-files')->assertSuccessful();

        foreach ([$oldSession, $oldView, $oldCache, $oldLog] as $gone) {
            $this->assertFileDoesNotExist($gone);
        }
        foreach ([$freshSession, $freshView, $freshLog, $gitignore, $upload] as $kept) {
            $this->assertFileExists($kept);
        }
        $this->assertDirectoryDoesNotExist($this->storage.'/framework/cache/data/ab');
        $this->assertDirectoryExists($this->storage.'/framework/cache/data');
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $old = $this->touchFile('logs/laravel-2020-01-01.log', 30);

        $this->artisan('host:prune-files --dry-run')
            ->expectsOutputToContain('would remove 1')
            ->assertSuccessful();

        $this->assertFileExists($old);
    }

    public function test_it_is_scheduled_daily_without_overlap_and_logs_keep_seven_days(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'host:prune-files'));

        $this->assertNotNull($event);
        $this->assertSame('17 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(7, config('logging.channels.daily.days'));
    }
}
