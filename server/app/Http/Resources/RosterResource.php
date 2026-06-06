<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RosterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'year' => $this->resource->year,
            'month' => $this->resource->month,
            'status' => $this->resource->status?->value,
            'generated_at' => $this->resource->generated_at?->toISOString(),
            'published_at' => $this->resource->published_at?->toISOString(),
            'assignment_count' => $this->when(
                isset($this->resource->assignments_count),
                $this->resource->assignments_count,
            ),
            'assignments' => $this->when(
                $this->resource->relationLoaded('assignments'),
                RosterAssignmentResource::collection($this->resource->assignments),
            ),
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
