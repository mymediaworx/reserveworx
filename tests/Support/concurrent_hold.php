<?php

use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);

try {
    $booking = app(Allocator::class)->hold(new AllocationIntent(
        $payload['tenant_id'],
        $payload['service_id'],
        CarbonImmutable::parse($payload['starts_at']),
        CarbonImmutable::parse($payload['ends_at']),
        (int) $payload['participants'],
        $payload['idempotency_key'] ?? null,
        null,
        null,
    ));
    echo json_encode(['ok' => true, 'booking_id' => $booking->id], JSON_THROW_ON_ERROR);
} catch (AllocationUnavailableException $exception) {
    fwrite(STDERR, $exception->getFile().':'.$exception->getLine().' '.$exception->getMessage()."\n");
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.' '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(3);
}
