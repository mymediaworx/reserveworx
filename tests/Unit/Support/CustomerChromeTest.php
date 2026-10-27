<?php

namespace Tests\Unit\Support;

use App\Support\BrandPalette;
use App\Support\CustomerChrome;
use PHPUnit\Framework\TestCase;

class CustomerChromeTest extends TestCase
{
    public function test_text_and_borders_meet_wcag_aa(): void
    {
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::MUTED, CustomerChrome::PAGE), 'muted text on the page');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::MUTED, CustomerChrome::SURFACE), 'muted text on white');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::MUTED, CustomerChrome::SLOT), 'booked-slot note');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::INK, CustomerChrome::PAGE));
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::INK, CustomerChrome::SURFACE));
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::INK_SOFT, CustomerChrome::PAGE));
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::INK_SOFT, CustomerChrome::SURFACE));
        $this->assertGreaterThanOrEqual(3.0, BrandPalette::contrast(CustomerChrome::LINE_STRONG, CustomerChrome::SURFACE), 'field border on white');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::DANGER, CustomerChrome::DANGER_SURFACE));
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::NOTICE, CustomerChrome::NOTICE_SURFACE));
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast(CustomerChrome::OK, CustomerChrome::OK_SURFACE));
    }

    public function test_css_is_only_validated_hex(): void
    {
        $css = CustomerChrome::css();

        $this->assertMatchesRegularExpression('/^:root\{(--[a-z-]+:#[0-9A-F]{6};?)+\}$/', $css);
        $this->assertStringContainsString('--muted:'.CustomerChrome::MUTED, $css);
        $this->assertStringContainsString('--line-strong:'.CustomerChrome::LINE_STRONG, $css);
        $this->assertStringNotContainsString('#D1D5DB', $css);
    }
}
