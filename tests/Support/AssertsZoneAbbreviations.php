<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

trait AssertsZoneAbbreviations
{
    protected function assertOmitsZoneAbbreviations(TestResponse $response, string ...$abbreviations): void
    {
        $html = (string) $response->getContent();

        foreach ($abbreviations as $abbreviation) {
            $found = ZoneAbbreviations::namedAt($html, $abbreviation);
            $message = $abbreviation.' is named as its own word.';

            if ($found !== null) {
                $message .= ' Near: '.$found['snippet'];
            }

            $this->assertNull($found, $message);
        }
    }
}
