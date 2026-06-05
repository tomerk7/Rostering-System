<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['worker_id', 'hourly_cost', 'min_monthly_hours', 'max_monthly_hours'])]
final class Contract extends Model
{
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function availableDays(): HasMany
    {
        return $this->hasMany(ContractAvailableDay::class);
    }

    public function availableShiftRows(): HasMany
    {
        return $this->hasMany(ContractAvailableShift::class);
    }

    public function availableShifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'contract_available_shifts');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hourly_cost' => 'decimal:2',
            'min_monthly_hours' => 'integer',
            'max_monthly_hours' => 'integer',
        ];
    }
}
