<?php

namespace App\Models;

use App\Enums\ActivityType;
use Database\Factories\LeadActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    /** @use HasFactory<LeadActivityFactory> */
    use HasFactory;

    protected $fillable = ['lead_id', 'user_id', 'type', 'title', 'body', 'meta', 'due_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'meta' => 'array',
            'due_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Follow-ups that are still due (have a due date in the future). */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNotNull('due_at')->where('due_at', '>=', now())->orderBy('due_at');
    }
}
