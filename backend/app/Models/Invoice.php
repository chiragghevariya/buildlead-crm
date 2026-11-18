<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Concerns\HasReference;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasReference;

    public const VAT_RATE = 19;

    protected static string $referencePrefix = 'INV';

    protected static string $referenceColumn = 'number';

    protected $fillable = [
        'number', 'project_id', 'type', 'status', 'amount', 'vat_amount', 'total',
        'issued_at', 'due_at', 'paid_at',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'date',
            'due_at' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Net amount drives VAT + gross total (19 % German VAT).
        static::saving(function (Invoice $invoice) {
            $vat = round((float) $invoice->amount * self::VAT_RATE / 100, 2);
            $invoice->vat_amount = $vat;
            $invoice->total = round((float) $invoice->amount + $vat, 2);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Overdue->value]);
    }
}
