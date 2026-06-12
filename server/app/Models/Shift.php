<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'start_time', 'end_time', 'duration_hours'])]
final class Shift extends Model
{
    public $timestamps = false;

    public function contractAvailability(): HasMany
    {
        return $this->hasMany(ContractAvailability::class);
    }

    public function shiftRoleRequirements(): HasMany
    {
        return $this->hasMany(ShiftRoleRequirement::class);
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
