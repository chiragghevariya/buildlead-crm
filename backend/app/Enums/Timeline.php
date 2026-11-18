<?php

namespace App\Enums;

enum Timeline: string
{
    case Asap = 'asap';
    case Within3Months = 'within_3_months';
    case Within6Months = 'within_6_months';
    case ThisYear = 'this_year';
    case JustResearching = 'just_researching';

    public function label(): string
    {
        return match ($this) {
            self::Asap => 'ASAP',
            self::Within3Months => 'Within 3 months',
            self::Within6Months => 'Within 6 months',
            self::ThisYear => 'This year',
            self::JustResearching => 'Just researching',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
