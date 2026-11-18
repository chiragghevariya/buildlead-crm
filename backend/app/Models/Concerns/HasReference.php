<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Auto-generates yearly sequential references like BL-2026-0001.
 *
 * Using models define:
 *   protected static string $referencePrefix = 'BL';
 *   protected static string $referenceColumn = 'reference';
 */
trait HasReference
{
    public static function bootHasReference(): void
    {
        static::creating(function ($model) {
            $column = static::$referenceColumn;

            if (blank($model->{$column})) {
                $year = ($model->created_at ?? now())->format('Y');
                $model->{$column} = static::nextReference((int) $year);
            }
        });
    }

    public static function nextReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = static::$referencePrefix.'-'.$year.'-';
        $column = static::$referenceColumn;

        $query = static::query();
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withTrashed();
        }

        // Fixed-width numbers sort correctly as strings up to 9999/year; beyond that
        // the width grows, so compare on length first.
        $last = $query->where($column, 'like', $prefix.'%')
            ->orderByRaw('LENGTH('.$column.') DESC')
            ->orderByDesc($column)
            ->value($column);

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
