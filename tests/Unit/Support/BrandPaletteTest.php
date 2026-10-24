<?php

namespace Tests\Unit\Support;

use App\Support\BrandPalette;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrandPaletteTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function normalizeCases(): array
    {
        return [
            'lower six' => ['#0f5c4d', '#0F5C4D'],
            'three digit' => ['#abc', '#AABBCC'],
            'spaces' => ['  #F59E0B ', '#F59E0B'],
            'no hash' => ['0F5C4D', null],
            'css injection' => ['#000;}body{display:none', null],
            'named color' => ['red', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function test_only_hex_colors_are_accepted(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, BrandPalette::normalize(is_string($input) ? $input : null));
    }

    public function test_invalid_colors_fall_back_to_the_defaults(): void
    {
        $palette = BrandPalette::from('javascript:alert(1)', '');

        $this->assertMatchesRegularExpression('/^:root\{(--[a-z-]+:#[0-9A-F]{6};?)+\}$/', $palette->css());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function anyColor(): array
    {
        return [
            'default green' => ['#0F5C4D'],
            'pale yellow' => ['#FDE68A'],
            'white' => ['#FFFFFF'],
            'black' => ['#000000'],
            'mid grey' => ['#808080'],
            'bright cyan' => ['#22D3EE'],
            'brand red' => ['#DC2626'],
        ];
    }

    #[DataProvider('anyColor')]
    public function test_derived_colors_stay_readable_for_any_choice(string $color): void
    {
        $v = (new BrandPalette($color, $color))->variables();

        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($v['--brand-ink'], $color), 'button text on the main color');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($v['--brand-text'], '#FFFFFF'), 'links on white');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($v['--brand-soft-ink'], $v['--brand-soft']), 'active tab label on its pill');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($v['--accent-soft-ink'], $v['--accent-soft']), 'badge text');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($v['--accent-ink'], $color), 'text on the highlight color');
    }

    public function test_a_light_color_gets_dark_button_text_and_darker_links(): void
    {
        $palette = new BrandPalette('#FDE68A', '#F59E0B');

        $this->assertTrue($palette->primaryIsLight());
        $this->assertTrue($palette->primaryNeedsDarkerText());
        $this->assertSame('#111827', $palette->variables()['--brand-ink']);
    }

    public function test_the_default_green_is_used_as_is(): void
    {
        $palette = new BrandPalette('#0F5C4D', '#F59E0B');

        $this->assertFalse($palette->primaryIsLight());
        $this->assertFalse($palette->primaryNeedsDarkerText());
        $this->assertSame('#0F5C4D', $palette->variables()['--brand-text']);
        $this->assertSame('#FFFFFF', $palette->variables()['--brand-ink']);
    }
}
