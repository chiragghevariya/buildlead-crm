<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case SiteVisit = 'site_visit';
    case QuoteSent = 'quote_sent';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::SiteVisit => 'Site visit',
            self::QuoteSent => 'Quote sent',
            self::Negotiation => 'Negotiation',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /**
     * Ordered pipeline columns (Kanban board order).
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return [
            self::New,
            self::Contacted,
            self::SiteVisit,
            self::QuoteSent,
            self::Negotiation,
            self::Won,
            self::Lost,
        ];
    }

    /**
     * Statuses that still count as an open lead (rep capacity, "open" scope).
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::New, self::Contacted, self::SiteVisit, self::QuoteSent, self::Negotiation];
    }

    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
