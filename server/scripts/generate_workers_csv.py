#!/usr/bin/env python3
"""
Generate a workers CSV compatible with the Laravel WorkerCsvImporter.

Demand reference (ReferenceDataSeeder): per shift per day needs 6 guards,
2 screeners, and 1 supervisor. Three shifts run daily, so concurrent
coverage needs roughly 18 guards, 6 screeners, and 3 supervisors minimum.

Usage:
    python3 server/scripts/generate_workers_csv.py
    python3 server/scripts/generate_workers_csv.py --profile adequate --output server/database/data/workers-large.csv
    python3 server/scripts/generate_workers_csv.py --count 80 --seed 42
"""

from __future__ import annotations

import argparse
import csv
import random
from pathlib import Path

HEADERS = [
    "full_name",
    "israeli_id",
    "role",
    "status",
    "hourly_cost",
    "min_monthly_hours",
    "max_monthly_hours",
    "availability",
]

ROLES = ("General Guard", "Supervisor", "Screener")
DAY_TOKENS = ("Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat")
SHIFT_CODES = ("A", "B", "C")

FIRST_NAMES = (
    "Dana", "Yossi", "Maya", "Avi", "Noa", "Eitan", "Tamar", "Omer", "Shira", "Itai",
    "Lior", "Gal", "Roni", "Nadav", "Hila", "Yuval", "Adi", "Bar", "Chen", "Ido",
    "Keren", "Omri", "Tal", "Yael", "Ziv", "Amit", "Boaz", "Dor", "Elad", "Gili",
)

LAST_NAMES = (
    "Cohen", "Levi", "Bar", "Mizrahi", "Friedman", "Shapiro", "Azoulay", "Katz", "Golan",
    "Avraham", "Ben-David", "Peretz", "Goldberg", "Rosen", "Klein", "Weiss", "Sharon",
    "Mor", "Dahan", "Biton", "Elkayam", "Haddad", "Saban", "Ohana", "Vaknin", "Asulin",
)

ROLE_COST_RANGES: dict[str, tuple[float, float]] = {
    "General Guard": (48.0, 56.0),
    "Screener": (56.0, 65.0),
    "Supervisor": (72.0, 88.0),
}

PROFILE_COUNTS: dict[str, dict[str, int]] = {
    # Sized to cover a full month with little shortage (28 days × 27 slots/day).
    "adequate": {"General Guard": 22, "Screener": 8, "Supervisor": 5},
    # Deliberately too small — useful for shortage / shortfall testing.
    "shortage": {"General Guard": 6, "Screener": 2, "Supervisor": 1},
}


def generate_israeli_id(base_number: int) -> str:
    return f"{base_number % 1_000_000_000:09d}"


def join_tokens(tokens: list[str]) -> str:
    return "|".join(tokens)


def format_availability(days: list[str], shifts: list[str]) -> str:
    return ";".join(f"{day}:{join_tokens(shifts)}" for day in days)


def role_counts_for_count(count: int) -> dict[str, int]:
    guards = max(1, round(count * 0.62))
    screeners = max(1, round(count * 0.28))
    supervisors = max(1, count - guards - screeners)

    while guards + screeners + supervisors > count:
        if guards >= screeners and guards >= supervisors:
            guards -= 1
        elif screeners >= supervisors:
            screeners -= 1
        else:
            supervisors -= 1

    return {
        "General Guard": guards,
        "Screener": screeners,
        "Supervisor": supervisors,
    }


def build_name_pool(count: int, rng: random.Random) -> list[str]:
    return [
        f"{rng.choice(FIRST_NAMES)} {rng.choice(LAST_NAMES)}"
        for _ in range(count)
    ]


def pick_days(rng: random.Random, profile: str) -> list[str]:
    if profile in ("adequate", "shortage"):
        return list(DAY_TOKENS)

    weekday_count = rng.randint(4, 7)
    if rng.random() < 0.15:
        weekday_count = rng.randint(2, 4)

    return sorted(rng.sample(list(DAY_TOKENS), k=weekday_count), key=DAY_TOKENS.index)


def pick_shifts(role: str, rng: random.Random, profile: str) -> list[str]:
    if profile in ("adequate", "shortage"):
        return list(SHIFT_CODES)

    if role == "Supervisor":
        count = rng.randint(2, 3)
    elif role == "Screener":
        count = rng.randint(1, 3)
    else:
        count = rng.randint(1, 3)

    return sorted(rng.sample(list(SHIFT_CODES), k=count), key=SHIFT_CODES.index)


def pick_contract(role: str, rng: random.Random, profile: str, is_active: bool) -> tuple[str, str, str]:
    low, high = ROLE_COST_RANGES[role]
    hourly_cost = f"{rng.uniform(low, high):.2f}"

    if not is_active:
        return hourly_cost, "0", str(rng.randint(80, 180))

    if profile == "shortage":
        min_hours = rng.choice((120, 140, 160, 180))
    elif profile == "adequate":
        min_hours = rng.choice((100, 120, 140, 160))
    else:
        min_hours = rng.choice((60, 80, 100, 120, 140, 160))

    max_hours = min(744, min_hours + rng.choice((20, 40, 60, 80, 100)))

    return hourly_cost, str(min_hours), str(max_hours)


def build_workers(count: int, profile: str, seed: int) -> list[dict[str, str]]:
    rng = random.Random(seed)

    if profile in PROFILE_COUNTS:
        role_counts = PROFILE_COUNTS[profile].copy()
        total = sum(role_counts.values())
        names = build_name_pool(total, rng)
    else:
        role_counts = role_counts_for_count(count)
        names = build_name_pool(count, rng)
        total = count

    workers: list[dict[str, str]] = []
    name_index = 0
    id_base = 10_000_000 + seed

    for role, role_count in role_counts.items():
        for _ in range(role_count):
            is_active = True
            if profile == "balanced" and rng.random() < 0.08:
                is_active = False
            elif profile not in ("adequate", "shortage") and rng.random() < 0.05:
                is_active = False

            hourly_cost, min_hours, max_hours = pick_contract(role, rng, profile, is_active)
            days = pick_days(rng, profile)
            shifts = pick_shifts(role, rng, profile)

            workers.append(
                {
                    "full_name": names[name_index],
                    "israeli_id": generate_israeli_id(id_base + name_index),
                    "role": role,
                    "status": "Active" if is_active else "Inactive",
                    "hourly_cost": hourly_cost,
                    "min_monthly_hours": min_hours,
                    "max_monthly_hours": max_hours,
                    "availability": format_availability(days, shifts),
                }
            )
            name_index += 1

    rng.shuffle(workers)
    return workers


def write_csv(path: Path, workers: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)

    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=HEADERS)
        writer.writeheader()
        writer.writerows(workers)


def parse_args() -> argparse.Namespace:
    default_output = Path(__file__).resolve().parent.parent / "database" / "data" / "workers-generated.csv"

    parser = argparse.ArgumentParser(description="Generate workers CSV for roster engine testing.")
    parser.add_argument(
        "--count",
        type=int,
        default=50,
        help="Number of workers for the balanced profile (default: 50).",
    )
    parser.add_argument(
        "--profile",
        choices=("balanced", "adequate", "shortage"),
        default="balanced",
        help="balanced=realistic mix; adequate=full-month coverage; shortage=stress test.",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=default_output,
        help="Output CSV path.",
    )
    parser.add_argument(
        "--seed",
        type=int,
        default=2026,
        help="Random seed for reproducible output.",
    )

    return parser.parse_args()


def main() -> None:
    args = parse_args()
    workers = build_workers(args.count, args.profile, args.seed)
    write_csv(args.output, workers)

    role_totals: dict[str, int] = {role: 0 for role in ROLES}
    active_count = 0

    for worker in workers:
        role_totals[worker["role"]] += 1
        if worker["status"] == "Active":
            active_count += 1

    print(f"Wrote {len(workers)} workers to {args.output}")
    print(f"Profile: {args.profile} (seed={args.seed})")
    print(
        "Roles: "
        + ", ".join(f"{role}={role_totals[role]}" for role in ROLES)
        + f"; active={active_count}"
    )


if __name__ == "__main__":
    main()
