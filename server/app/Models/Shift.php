<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'label', 'start_time', 'end_time', 'duration_hours'])]
final class Shift extends Model
{
    public $timestamps = false;

    public function availableContracts(): HasMany
    {
        return $this->hasMany(ContractAvailableShift::class);
    }

    public function shiftRoleRequirements(): HasMany
    {
        return $this->hasMany(ShiftRoleRequirement::class);
    }

    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_available_shifts');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'shift_role_requirements')
            ->withPivot('required_count');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i:s',
            'end_time' => 'datetime:H:i:s',
            'duration_hours' => 'integer',
        ];
    }
}
