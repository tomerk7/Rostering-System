<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'year',
    'month',
    'status',
    'assignments',
    'coverage_shortages',
    'hours_shortfalls',
    'summary',
    'error_message',
    'requested_by',
    'roster_id',
    'started_at',
    'completed_at',
])]
final class RosterGeneration extends Model
{
    use HasFactory;

    /**
     * Use the public uuid for route-model binding instead of the numeric key.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the user that requested the generation.
     *
     * @return BelongsTo
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the draft roster saved from this generation, if any.
     *
     * @return BelongsTo
     */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class, 'roster_id');
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
            'assignments' => 'array',
            'coverage_shortages' => 'array',
            'hours_shortfalls' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
