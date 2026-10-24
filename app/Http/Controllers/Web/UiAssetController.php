<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the UI stylesheet through Laravel. The SiteGround document root only
 * receives index.php and a few fixed files at deploy, so a file under public/
 * would not be reachable there. Pages link it with a content hash, so it can be
 * cached for a long time.
 */
class UiAssetController extends Controller
{
    public function stylesheet(): Response
    {
        $path = resource_path('ui/app.css');
        abort_unless(is_file($path), 404);

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The embed helper operators paste on their own sites. Its URL never changes,
     * so it is cached for an hour rather than forever.
     */
    public function embed(): Response
    {
        $path = resource_path('ui/embed.js');
        abort_unless(is_file($path), 404);

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
