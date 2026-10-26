<?php

namespace App\Services\Integrations;

use RuntimeException;

/**
 * A stopped GoHighLevel send cannot be queued again.
 */
final class IntegrationRetryRefused extends RuntimeException {}
