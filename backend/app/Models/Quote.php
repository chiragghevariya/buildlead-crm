<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Models\Concerns\HasReference;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory, HasReference;

    protected static string $referencePrefix = 'Q';

    protected static string $referenceColumn = 'number';

    protected $fillable = [
        'number', 'lead_id', 'created_by', 'status', 'subtotal', 'vat_rate',
        'vat_amount', 'total', 'valid_until', 'sent_at', 'accepted_at', 'notes',
    ];

    protected $attributes = [
        'status' => 'draft',
        'vat_rate' => 19,
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'subtotal' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    /** Recompute subtotal / VAT / total from the line items and persist. */
    public function recalculate(): self
    {
        $subtotal = round((float) $this->items()->sum('line_total'), 2);
        $vat = round($subtotal * (float) $this->vat_rate / 100, 2);

        $this->forceFill([
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => round($subtotal + $vat, 2),
        ])->save();

        return $this;
    }
}
