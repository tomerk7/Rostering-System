<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
            'created_by' => $this->resource->created_by,
            'creator' => $this->when(
                $this->resource->relationLoaded('creator'),
                fn (): ?array => $this->resource->creator === null ? null : [
                    'id' => $this->resource->creator->id,
                    'email' => $this->resource->creator->email,
                ],
            ),
            'assignments_count' => $this->when(
                isset($this->resource->assignments_count),
                $this->resource->assignments_count,
            ),
            'assignments' => $this->when(
                $this->resource->relationLoaded('assignments'),
                fn (): AnonymousResourceCollection => RosterAssignmentResource::collection($this->resource->assignments),
            ),
            'reports' => $this->when(
                $this->resource->offsetExists('reports'),
                fn (): mixed => $this->resource->reports,
            ),
            'summary' => $this->when(
                $this->resource->offsetExists('summary'),
                fn (): mixed => $this->resource->summary,
            ),
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
