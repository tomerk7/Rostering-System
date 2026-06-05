<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_id', 'role_id', 'required_count'])]
final class ShiftRoleRequirement extends Model
{
    public $timestamps = false;

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

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
            'required_count' => 'integer',
        ];
    }
}
