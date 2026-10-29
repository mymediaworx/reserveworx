<?php

namespace App\Http\Middleware;

use App\Http\FramePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to let another site frame a sign-in or staff page.
 * The booking page and /book/{token} stay embeddable.
 */
class DenyFraming
{
    public function __construct(private readonly FramePolicy $frames) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->frames->denies($request->getPathInfo())) {
            return $response;
        }

        $response->headers->set('X-Frame-Options', 'DENY');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        }

        return $response;
    }
}
