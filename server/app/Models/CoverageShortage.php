<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'roster_id',
    'work_date',
    'shift_id',
    'role_id',
    'required_count',
    'assigned_count',
])]
final class CoverageShortage extends Model
{
    use HasFactory;

    /**
     * Get the roster that the coverage shortage belongs to.
     *
     * @return BelongsTo
     */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }

    /**
     * Get the shift that is understaffed.
     *
     * @return BelongsTo
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the role that is understaffed.
     *
     * @return BelongsTo
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
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
            'required_count' => 'integer',
            'assigned_count' => 'integer',
        ];
    }
}
