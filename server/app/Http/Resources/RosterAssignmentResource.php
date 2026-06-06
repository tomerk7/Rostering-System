<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RosterAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RosterAssignmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof RosterAssignment) {
            return $this->fromModel($this->resource);
        }

        /** @var array<string, mixed> $assignment */
        $assignment = (array) $this->resource;

        return [
            'id' => $assignment['id'] ?? null,
            'worker_id' => $assignment['worker_id'],
            'worker_name' => $assignment['worker_name'] ?? null,
            'shift_id' => $assignment['shift_id'],
            'shift_code' => $assignment['shift_code'] ?? null,
            'role_id' => $assignment['role_id'] ?? null,
            'role_name' => $assignment['role_name'] ?? null,
            'work_date' => $assignment['work_date'],
            'source' => $assignment['source'],
        ];
    }

    /**
     * Transform the model into an array.
     *
     * @param RosterAssignment $assignment
     * @return array<string, mixed>
     */
    private function fromModel(RosterAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'worker_id' => $assignment->worker_id,
            'worker_name' => $assignment->worker?->full_name,
            'shift_id' => $assignment->shift_id,
            'shift_code' => $assignment->shift?->code,
            'role_id' => $assignment->worker?->role?->id,
            'role_name' => $assignment->worker?->role?->name,
            'work_date' => $assignment->work_date?->toDateString(),
            'source' => $assignment->source?->value,
        ];
    }
}
