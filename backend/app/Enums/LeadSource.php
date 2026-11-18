<?php

namespace App\Enums;

enum LeadSource: string
{
    case WebsiteForm = 'website_form';
    case Email = 'email';
    case PhoneCall = 'phone_call';
    case Referral = 'referral';
    case TradeFair = 'trade_fair';
    case Portal = 'portal';

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
