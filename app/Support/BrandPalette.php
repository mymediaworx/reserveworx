<?php

namespace App\Support;

use App\Models\TenantBranding;

/**
 * Turns a workspace's two brand colors into the CSS custom properties the UI
 * uses, and keeps every derived color readable.
 *
 * Whatever a workspace picks, button text, links on white, and icons on tinted
 * circles meet WCAG contrast minimums (4.5:1 for text, 3:1 for icons). A light
 * color gets dark button text and a darker shade for links instead of being
 * refused.
 */
final readonly class BrandPalette
{
    public const HEX = '/^#[0-9A-F]{6}$/';

    private const WHITE = '#FFFFFF';

    private const DARK = '#111827';

    public function __construct(
        public string $primary,
        public string $accent,
    ) {}

    public static function from(?string $primary, ?string $accent): self
    {
        return new self(
            self::normalize($primary) ?? TenantBranding::DEFAULT_PRIMARY,
            self::normalize($accent) ?? TenantBranding::DEFAULT_ACCENT,
        );
    }

    /**
     * Uppercase #RRGGBB, or null for anything else. Expands #RGB.
     */
    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $hex = strtoupper(trim($value));

        if (preg_match('/^#[0-9A-F]{3}$/', $hex) === 1) {
            $hex = '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
        }

        return preg_match(self::HEX, $hex) === 1 ? $hex : null;
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        $brandSoft = self::mix($this->primary, self::WHITE, 0.84);
        $accentSoft = self::mix($this->accent, self::WHITE, 0.82);

        return [
            '--brand' => $this->primary,
            '--brand-strong' => self::mix($this->primary, '#000000', 0.18),
            '--brand-ink' => self::bestText($this->primary),
            '--brand-text' => self::readableOn($this->primary, self::WHITE, 4.5),
            '--brand-soft' => $brandSoft,
            '--brand-soft-ink' => self::readableOn($this->primary, $brandSoft, 4.5),
            '--accent' => $this->accent,
            '--accent-ink' => self::bestText($this->accent),
            '--accent-soft' => $accentSoft,
            '--accent-soft-ink' => self::readableOn($this->accent, $accentSoft, 4.5),
        ];
    }

    /**
     * A :root rule safe to print inside a <style> tag: every value is a validated hex color.
     */
    public function css(): string
    {
        $pairs = [];

        foreach ($this->variables() as $name => $value) {
            $pairs[] = $name.':'.$value;
        }

        return ':root{'.implode(';', $pairs).'}';
    }

    /**
     * Light colors need dark text on buttons; true when the primary is one.
     */
    public function primaryIsLight(): bool
    {
        return self::bestText($this->primary) !== self::WHITE;
    }

    /**
     * True when the primary had to be darkened to be readable as text on white.
     */
    public function primaryNeedsDarkerText(): bool
    {
        return self::readableOn($this->primary, self::WHITE, 4.5) !== $this->primary;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        [$light, $dark] = $la > $lb ? [$la, $lb] : [$lb, $la];

        return ($light + 0.05) / ($dark + 0.05);
    }

    /**
     * White or near-black, whichever reads better on the background.
     */
    public static function bestText(string $background): string
    {
        if (self::contrast($background, self::WHITE) >= self::contrast($background, self::DARK)) {
            return self::WHITE;
        }

        // Mid-tones can fall just short with near-black; pure black always does better.
        return self::contrast($background, self::DARK) >= 4.5 ? self::DARK : '#000000';
    }

    /**
     * The color itself if it meets the ratio on the background, otherwise the
     * first shade darker that does.
     */
    public static function readableOn(string $color, string $background, float $ratio): string
    {
        $candidate = $color;

        for ($step = 1; $step <= 20 && self::contrast($candidate, $background) < $ratio; $step++) {
            $candidate = self::mix($color, '#000000', $step * 0.05);
        }

        return $candidate;
    }

    /**
     * Linear blend: weight 0 returns $from, weight 1 returns $to.
     */
    public static function mix(string $from, string $to, float $weight): string
    {
        [$r1, $g1, $b1] = self::rgb($from);
        [$r2, $g2, $b2] = self::rgb($to);
        $w = max(0.0, min(1.0, $weight));

        return sprintf(
            '#%02X%02X%02X',
            (int) round($r1 + ($r2 - $r1) * $w),
            (int) round($g1 + ($g2 - $g1) * $w),
            (int) round($b1 + ($b2 - $b1) * $w),
        );
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $clean = ltrim($hex, '#');

        return [(int) hexdec(substr($clean, 0, 2)), (int) hexdec(substr($clean, 2, 2)), (int) hexdec(substr($clean, 4, 2))];
    }
}
