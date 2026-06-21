<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A worker's contract (1:1). `hourlyCost` is kept as a string to preserve the
 * fixed 2-decimal shape the decimal cast produces (e.g. "35.73").
 *
 * `availability` defaults to an empty list; repositories populate it only when
 * the caller asks for the relation.
 */
final readonly class Contract
{
    /**
     * Class constructor.
     * 
     * @param int $id
     * @param string $hourlyCost
     * @param int $minMonthlyHours
     * @param int $maxMonthlyHours
     * @param list<ContractAvailability> $availability
     */
    public function __construct(
        public int $id,
        public string $hourlyCost,
        public int $minMonthlyHours,
        public int $maxMonthlyHours,
        public array $availability = [],
    ) {}

    /**
     * @return array{id: int, hourly_cost: string, min_monthly_hours: int, max_monthly_hours: int, availability: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hourly_cost' => $this->hourlyCost,
            'min_monthly_hours' => $this->minMonthlyHours,
            'max_monthly_hours' => $this->maxMonthlyHours,
            'availability' => array_map(
                static fn (ContractAvailability $slot): array => $slot->toArray(),
                $this->availability,
            ),
        ];
    }
}
