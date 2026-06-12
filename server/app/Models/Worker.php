<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['full_name', 'israeli_id', 'role_id', 'is_active'])]
final class Worker extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $primaryKey = 'israeli_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class, 'worker_id', 'israeli_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }
}
