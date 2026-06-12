<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['year', 'month', 'generated_at', 'published_at', 'created_by'])]
final class Roster extends Model
{
    use HasFactory;

    /**
     * Get the user that created the roster.
     * 
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the assignments for the roster.
     * 
     * @return HasMany
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }

    /**
     * Scope the query to only include rosters for a specific period.
     * 
     * @param Builder $query
     * @param int $year
     * @param int $month
     * @return Builder
     */
    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
