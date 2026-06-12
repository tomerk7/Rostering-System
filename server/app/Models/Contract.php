<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['worker_id', 'hourly_cost', 'min_monthly_hours', 'max_monthly_hours'])]
final class Contract extends Model
{
    use HasFactory;

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id', 'israeli_id');
    }

    public function availability(): HasMany
    {
        return $this->hasMany(ContractAvailability::class);
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
