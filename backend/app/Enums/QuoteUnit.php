<?php

namespace App\Enums;

enum QuoteUnit: string
{
    case Sqm = 'm²';
    case Hours = 'h';
    case Pieces = 'pcs';
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::Sqm => 'm²',
            self::Hours => 'Hours',
            self::Pieces => 'Pieces',
            self::Flat => 'Flat rate',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
