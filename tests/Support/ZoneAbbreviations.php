<?php

namespace Tests\Support;

/**
 * A zone abbreviation is its own word, such as EDT or UTC.
 *
 * assertDontSee() searches the raw document. A ULID, a CSRF token, a Livewire
 * id, or a widget token is letters and digits, so those letters can show up
 * inside it by chance. The rendered time still has to name the location zone.
 */
final class ZoneAbbreviations
{
    /**
     * Where the abbreviation stands as its own word, or null when it does not.
     *
     * @return array{offset: int, snippet: string}|null
     */
    public static function namedAt(string $html, string $abbreviation): ?array
    {
        if ($abbreviation === '') {
            return null;
        }

        $pattern = '/\b'.preg_quote($abbreviation, '/').'\b/';
        $matched = preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE);

        if ($matched === false) {
            throw new \RuntimeException('Could not search for the zone abbreviation '.$abbreviation.'.');
        }

        if ($matched !== 1) {
            return null;
        }

        $offset = (int) $match[0][1];

        return [
            'offset' => $offset,
            'snippet' => substr($html, max(0, $offset - 48), strlen($abbreviation) + 96),
        ];
    }
}
