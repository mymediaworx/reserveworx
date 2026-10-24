<?php

namespace App\Services\CheckIn;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

/**
 * Server-rendered SVG for a check-in URL. No image extension and no Node build.
 */
final class QrSvg
{
    public function render(string $url): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'drawCircularModules' => false,
        ]);
        $svg = (new QRCode($options))->render($url);

        if (! is_string($svg) || ! str_starts_with(ltrim($svg), '<svg')) {
            throw new RuntimeException('The check-in code was not an SVG.');
        }

        if (preg_match('/<script|on\w+=/i', $svg) === 1) {
            throw new RuntimeException('The check-in code was not safe to embed.');
        }

        return str_replace('<svg ', '<svg role="img" aria-label="Check-in code" ', $svg);
    }
}
