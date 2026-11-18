<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\HasReference;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasReference;

    protected static string $referencePrefix = 'P';

    protected static string $referenceColumn = 'code';

    protected $fillable = [
        'code', 'lead_id', 'quote_id', 'manager_id', 'name', 'site_address', 'status',
        'progress', 'budget', 'spent', 'start_date', 'due_date',
    ];

    protected $attributes = [
        'status' => 'planning',
        'progress' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'progress' => 'integer',
            'budget' => 'decimal:2',
            'spent' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [ProjectStatus::Planning->value, ProjectStatus::InProgress->value]);
    }

    public function budgetUsedPercent(): int
    {
        return (float) $this->budget > 0 ? (int) round((float) $this->spent / (float) $this->budget * 100) : 0;
    }
}
