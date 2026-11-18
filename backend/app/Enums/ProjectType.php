<?php

namespace App\Enums;

enum ProjectType: string
{
    case NewBuild = 'new_build';
    case Renovation = 'renovation';
    case Extension = 'extension';
    case CommercialFitOut = 'commercial_fit_out';
    case Roofing = 'roofing';
    case Interior = 'interior';
    case Repair = 'repair';

    public function label(): string
    {
        return match ($this) {
            self::NewBuild => 'New build',
            self::Renovation => 'Renovation',
            self::Extension => 'Extension',
            self::CommercialFitOut => 'Commercial fit-out',
            self::Roofing => 'Roofing',
            self::Interior => 'Interior',
            self::Repair => 'Repair',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
