<?php

namespace App\Http;

/**
 * Which responses another site may place in a frame.
 *
 * Spec Section 6 embeds the booking page and /book/{token} on a tenant's site.
 * Spec Section 12 and Section 14 item 14 keep sign-in and staff pages out of those frames.
 */
final class FramePolicy
{
    public function denies(string $path): bool
    {
        $path = '/'.ltrim($path, '/');

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        if ($path === '/' || str_starts_with($path, '/book/')) {
            return false;
        }

        return true;
    }
}
