<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contract = $this->resource->contract;

        return [
            'id' => $this->resource->id,
            'full_name' => $this->resource->full_name,
            'israeli_id' => $this->resource->israeli_id,
            'is_active' => $this->resource->is_active,
            'role' => [
                'id' => $this->resource->role?->id,
                'code' => $this->resource->role?->code,
                'name' => $this->resource->role?->name,
            ],
            'contract' => $contract === null ? null : [
                'id' => $contract->id,
                'hourly_cost' => $contract->hourly_cost,
                'min_monthly_hours' => $contract->min_monthly_hours,
                'max_monthly_hours' => $contract->max_monthly_hours,
                'availability' => [
                    'days' => $contract->availableDays
                        ->pluck('day_of_week')
                        ->map(static fn (mixed $dayOfWeek): int => (int) $dayOfWeek)
                        ->values(),
                    'shifts' => $contract->availableShifts
                        ->map(static fn ($shift): array => [
                            'id' => $shift->id,
                            'code' => $shift->code,
                            'label' => $shift->label,
                            'start_time' => $shift->start_time,
                            'end_time' => $shift->end_time,
                            'duration_hours' => $shift->duration_hours,
                        ])
                        ->values(),
                ],
            ],
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
