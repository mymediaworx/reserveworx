<?php

namespace App\Support;

/**
 * Text and border colors for the public booking pages.
 *
 * Brand colors stay on BrandPalette. These values are fixed so muted copy,
 * field borders, and status messages meet WCAG 2.1 AA on the booking page
 * even when a workspace picks a pale brand color. Nothing here is taken from
 * a request, so the stylesheet can be printed as-is.
 */
final class CustomerChrome
{
    public const PAGE = '#F3F4F6';

    public const SURFACE = '#FFFFFF';

    public const INK = '#1F2937';

    public const INK_SOFT = '#374151';

    public const MUTED = '#4B5563';

    public const LINE = '#E5E7EB';

    public const LINE_STRONG = '#6B7280';

    public const DANGER = '#B91C1C';

    public const DANGER_SURFACE = '#FEE2E2';

    public const NOTICE = '#78350F';

    public const NOTICE_SURFACE = '#FEF3C7';

    public const OK = '#14532D';

    public const OK_SURFACE = '#DCFCE7';

    public const SLOT = '#F9FAFB';

    /**
     * @return array{
     *     --page: string,
     *     --surface: string,
     *     --ink: string,
     *     --ink-soft: string,
     *     --muted: string,
     *     --line: string,
     *     --line-strong: string,
     *     --danger: string,
     *     --danger-surface: string,
     *     --notice: string,
     *     --notice-surface: string,
     *     --ok: string,
     *     --ok-surface: string,
     *     --slot: string
     * }
     */
    public static function variables(): array
    {
        return [
            '--page' => self::PAGE,
            '--surface' => self::SURFACE,
            '--ink' => self::INK,
            '--ink-soft' => self::INK_SOFT,
            '--muted' => self::MUTED,
            '--line' => self::LINE,
            '--line-strong' => self::LINE_STRONG,
            '--danger' => self::DANGER,
            '--danger-surface' => self::DANGER_SURFACE,
            '--notice' => self::NOTICE,
            '--notice-surface' => self::NOTICE_SURFACE,
            '--ok' => self::OK,
            '--ok-surface' => self::OK_SURFACE,
            '--slot' => self::SLOT,
        ];
    }

    /**
     * A :root rule safe to print inside a style tag: every value is a validated hex color.
     */
    public static function css(): string
    {
        $pairs = [];

        foreach (self::variables() as $name => $value) {
            if (preg_match(BrandPalette::HEX, $value) !== 1) {
                continue;
            }

            $pairs[] = $name.':'.$value;
        }

        return ':root{'.implode(';', $pairs).'}';
    }
}
