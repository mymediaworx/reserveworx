<?php

namespace App\Services\Operations;

use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Support\PlainPdf;
use Illuminate\Support\Carbon;

/**
 * A printable copy of one stored signature and the template text it hashed.
 * This is not an electronic-signature certificate.
 */
final class WaiverPdf
{
    public function render(WaiverSignature $signature, WaiverVersion $version): string
    {
        return (new PlainPdf)->render($this->lines($signature, $version));
    }

    /**
     * @return list<string>
     */
    public function lines(WaiverSignature $signature, WaiverVersion $version): array
    {
        $signed = $signature->signed_at;
        $lines = [
            $version->name.' (version '.$version->version.')',
            'Participant: '.$signature->participant_name,
        ];

        if ($signature->is_minor) {
            $lines[] = 'Parent or guardian: '.(is_string($signature->guardian_name) ? $signature->guardian_name : '');
        }

        $lines[] = 'Signed at (UTC): '.($signed instanceof Carbon ? $signed->timezone('UTC')->format('Y-m-d H:i:s') : '');
        $lines[] = 'IP address: '.(is_string($signature->ip_address) ? $signature->ip_address : '');
        $lines[] = 'User agent: '.(is_string($signature->user_agent) ? $signature->user_agent : '');
        $lines[] = 'Template hash: '.$signature->content_hash;

        if (hash('sha256', $version->body) !== $signature->content_hash) {
            $lines[] = 'The stored hash does not match the template text.';
        }

        $lines[] = '';
        $lines[] = 'This copy is the stored waiver text and signature record.';
        $lines[] = 'It is not an attorney-approved electronic-signature certificate.';
        $lines[] = '';

        foreach (preg_split("/\r\n|\n|\r/", $version->body) ?: [] as $paragraph) {
            $lines[] = $paragraph;
        }

        return $lines;
    }
}
