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
                'availability' => $contract->availability
                    ->map(static fn ($slot): array => [
                        'day_of_week' => (int) $slot->day_of_week,
                        'shift' => [
                            'id' => $slot->shift->id,
                            'code' => $slot->shift->code,
                            'label' => $slot->shift->label,
                            'start_time' => $slot->shift->start_time,
                            'end_time' => $slot->shift->end_time,
                            'duration_hours' => $slot->shift->duration_hours,
                        ],
                    ])
                    ->values(),
            ],
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
