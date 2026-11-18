<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Deposit = 'deposit';
    case Progress = 'progress';
    case Final = 'final';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
