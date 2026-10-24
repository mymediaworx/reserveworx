<?php

use App\Models\Booking;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantScope;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);

try {
    $booking = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($payload['booking_id']);
    $saved = app(Allocator::class)->changePartySize($booking, (int) $payload['participants']);

    echo json_encode([
        'ok' => true,
        'participants' => $saved->participants,
    ], JSON_THROW_ON_ERROR);
} catch (AllocationUnavailableException $exception) {
    fwrite(STDERR, $exception->getFile().':'.$exception->getLine().' '.$exception->getMessage()."\n");
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
        'offers' => $exception->offers,
    ], JSON_THROW_ON_ERROR);
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.' '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(3);
}
