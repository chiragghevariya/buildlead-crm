<?php

namespace App\Support;

class Money
{
    /** German format without cents: "€ 125.000". */
    public static function format(int|float|string|null $amount): string
    {
        return '€ '.number_format((float) $amount, 0, ',', '.');
    }

    /** German format with cents for documents: "€ 1.234,56". */
    public static function cents(int|float|string|null $amount): string
    {
        return '€ '.number_format((float) $amount, 2, ',', '.');
    }

    /** "1.234,5" quantities without trailing zeros. */
    public static function quantity(int|float|string|null $value): string
    {
        $value = (float) $value;

        return floor($value) == $value
            ? number_format($value, 0, ',', '.')
            : rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
