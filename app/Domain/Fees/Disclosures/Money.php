<?php

namespace App\Domain\Fees\Disclosures;

/**
 * Display formatting for integer cents. Does not depend on the intl extension,
 * which is not verified on the host.
 */
final class Money
{
    public static function format(int $cents, string $currency = 'USD'): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        $amount = number_format(intdiv($absolute, 100), 0, '.', ',').'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return strtoupper($currency) === 'USD'
            ? $sign.'$'.$amount
            : $sign.strtoupper($currency).' '.$amount;
    }
}
