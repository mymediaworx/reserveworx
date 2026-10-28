<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\ZoneAbbreviations;

class ZoneAbbreviationTest extends TestCase
{
    public function test_letters_inside_an_id_or_token_are_not_a_zone_label(): void
    {
        $bookingId = '01KEDT3N8H2Q4R6S8T0VWXYZAB';
        $token = 'k7CDT2p9qCST4mUTC8wEDTx';
        $html = '<dd data-when>Tue, Jun 16, 3:00 PM – 4:00 PM UTC</dd>'
            .'<p>Booking '.$bookingId.'</p>'
            .'<meta name="csrf-token" content="'.$token.'">'
            .'<a href="/book/'.$token.'/services/'.$bookingId.'">Book</a>';

        $this->assertNull(ZoneAbbreviations::namedAt($html, 'EDT'));
        $this->assertNull(ZoneAbbreviations::namedAt($html, 'CDT'));
        $this->assertNull(ZoneAbbreviations::namedAt($html, 'CST'));

        $utc = ZoneAbbreviations::namedAt($html, 'UTC');
        $this->assertNotNull($utc);
        $this->assertStringContainsString('4:00 PM UTC', $utc['snippet']);
    }

    public function test_a_time_that_names_the_zone_is_still_a_label(): void
    {
        $html = '<p>Booking 01KEDT3N8H2Q4R6S8T0VWXYZAB</p>'
            .'<dd data-when>Tue, Jun 16, 3:00 PM – 4:00 PM EDT</dd>'
            .'<p>Times are in America/Chicago.</p>';

        $edt = ZoneAbbreviations::namedAt($html, 'EDT');
        $this->assertNotNull($edt);
        $this->assertStringContainsString('4:00 PM EDT', $edt['snippet']);
        $this->assertNull(ZoneAbbreviations::namedAt($html, 'UTC'));
        $this->assertNull(ZoneAbbreviations::namedAt($html, 'CDT'));
        $this->assertNull(ZoneAbbreviations::namedAt('Times are in UTC.', 'EDT'));
        $this->assertNotNull(ZoneAbbreviations::namedAt('Times are in UTC.', 'UTC'));
    }
}
