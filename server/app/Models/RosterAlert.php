<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RosterAlertType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'roster_id',
    'type',
    'worker_id',
    'min_hours',
    'scheduled_hours',
])]
final class RosterAlert extends Model
{
    use HasFactory;

    /**
     * Get the roster that the alert belongs to.
     *
     * @return BelongsTo
     */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }

    /**
     * Get the worker the alert belongs to.
     *
     * @return BelongsTo
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id', 'israeli_id');
    }

    /**
     * Scope the query to only include hours-shortfall alerts.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeHoursShortfall(Builder $query): Builder
    {
        return $query->where('type', RosterAlertType::HoursShortfall->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RosterAlertType::class,
            'min_hours' => 'integer',
            'scheduled_hours' => 'integer',
        ];
    }
}
