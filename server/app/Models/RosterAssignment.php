<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['roster_id', 'worker_id', 'shift_id', 'work_date', 'source'])]
final class RosterAssignment extends Model
{
    use HasFactory;

    /**
     * Get the roster that the assignment belongs to.
     * 
     * @return BelongsTo
     */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }

    /**
     * Get the worker that the assignment belongs to.
     * 
     * @return BelongsTo
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id', 'israeli_id');
    }

    /**
     * Get the shift that the assignment belongs to.
     * 
     * @return BelongsTo
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Scope the query to only include auto assignments.
     * 
     * @param Builder $query
     * @return Builder
     */
    public function scopeAuto(Builder $query): Builder
    {
        return $query->where('source', AssignmentSource::Auto->value);
    }

    /**
     * Scope the query to only include manual assignments.
     * 
     * @param Builder $query
     * @return Builder
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where('source', AssignmentSource::Manual->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'source' => AssignmentSource::class,
        ];
    }
}
