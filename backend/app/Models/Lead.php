<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProjectType;
use App\Enums\Timeline;
use App\Models\Concerns\HasReference;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, HasReference, SoftDeletes;

    protected static string $referencePrefix = 'BL';

    protected static string $referenceColumn = 'reference';

    protected $fillable = [
        'reference', 'contact_name', 'company', 'email', 'phone', 'source',
        'project_type', 'description', 'budget_min', 'budget_max', 'timeline',
        'street', 'postcode', 'city', 'area_sqm',
        'score', 'rule_score', 'ai_score', 'temperature', 'score_breakdown',
        'ai_summary', 'ai_next_action', 'scored_at',
        'status', 'assigned_to', 'assigned_at', 'first_contacted_at',
        'estimated_value', 'lost_reason', 'position',
        'consent_given', 'consent_at', 'raw_payload',
    ];

    protected $attributes = [
        'status' => 'new',
        'position' => 0,
        'consent_given' => false,
    ];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'project_type' => ProjectType::class,
            'timeline' => Timeline::class,
            'status' => LeadStatus::class,
            'temperature' => LeadTemperature::class,
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'estimated_value' => 'decimal:2',
            'area_sqm' => 'integer',
            'score' => 'integer',
            'rule_score' => 'integer',
            'ai_score' => 'integer',
            'position' => 'integer',
            'score_breakdown' => 'array',
            'raw_payload' => 'array',
            'consent_given' => 'boolean',
            'scored_at' => 'datetime',
            'assigned_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    // ---- Relations -------------------------------------------------------

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest();
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function latestQuote(): HasOne
    {
        return $this->hasOne(Quote::class)->latestOfMany();
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    // ---- Scopes ----------------------------------------------------------

    public function scopeHot(Builder $query): Builder
    {
        return $query->where('temperature', LeadTemperature::Hot->value);
    }

    public function scopeTemperature(Builder $query, LeadTemperature|string $temperature): Builder
    {
        return $query->where('temperature', $temperature instanceof LeadTemperature ? $temperature->value : $temperature);
    }

    /** Not yet won or lost. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn (LeadStatus $s) => $s->value, LeadStatus::open()));
    }

    /** Admin/manager: everything. Sales: only leads assigned to them. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canSeeAllLeads()) {
            return $query;
        }

        return $query->where('assigned_to', $user->id);
    }

    /** Case-insensitive search across reference, contact, company, email, phone, city, postcode. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            foreach (['reference', 'contact_name', 'company', 'email', 'phone', 'city', 'postcode'] as $column) {
                $q->orWhereRaw("LOWER({$column}) LIKE ?", [$like]);
            }
        });
    }

    // ---- Helpers ---------------------------------------------------------

    public function isHot(): bool
    {
        return $this->temperature === LeadTemperature::Hot;
    }

    public function budgetMidpoint(): ?float
    {
        $min = $this->budget_min !== null ? (float) $this->budget_min : null;
        $max = $this->budget_max !== null ? (float) $this->budget_max : null;

        return match (true) {
            $min !== null && $max !== null => ($min + $max) / 2,
            default => $max ?? $min,
        };
    }

    public function logActivity(string $type, string $title, array $attributes = []): LeadActivity
    {
        return $this->activities()->create(array_merge([
            'type' => $type,
            'title' => $title,
            'user_id' => auth()->id(),
        ], $attributes));
    }
}
