<?php

namespace App\Http\Middleware;

use App\Http\StrictTransport;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An HTTPS response names this host for a year. An HTTP response is left alone
 * so a local request is not told to upgrade. Spec Section 14 item 14.
 */
class SendStrictTransportSecurity
{
    public function __construct(private readonly StrictTransport $transport) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->transport->applies($request)) {
            $response->headers->set(StrictTransport::HEADER, StrictTransport::VALUE);
        }

        return $response;
    }
}
