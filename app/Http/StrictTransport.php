<?php

namespace App\Http;

use App\Http\Middleware\SendStrictTransportSecurity;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Throwable;

/**
 * HTTPS responses tell the browser to keep using HTTPS for this host.
 * Spec Section 14 item 14 and Spec Section 12. The value is not a secret.
 * A sibling host, including another workspace, is not included.
 */
class StrictTransport
{
    public const HEADER = 'Strict-Transport-Security';

    public const VALUE = 'max-age=31536000';

    public function applies(Request $request): bool
    {
        return $request->isSecure();
    }

    public function registered(): bool
    {
        try {
            foreach (app(Kernel::class)->getGlobalMiddleware() as $middleware) {
                if ($middleware === SendStrictTransportSecurity::class) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
