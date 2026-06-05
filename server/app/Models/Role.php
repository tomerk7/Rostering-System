<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name'])]
final class Role extends Model
{
    public $timestamps = false;

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    public function shiftRoleRequirements(): HasMany
    {
        return $this->hasMany(ShiftRoleRequirement::class);
    }

    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'shift_role_requirements')
            ->withPivot('required_count');
    }
}
