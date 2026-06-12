#!/usr/bin/env python3
"""
Generate a workers CSV compatible with the Laravel WorkerCsvImporter.

Demand reference (ReferenceDataSeeder): per shift per day needs 6 guards,
2 screeners, and 1 supervisor. Three shifts run daily, so concurrent
coverage needs roughly 18 guards, 6 screeners, and 3 supervisors minimum.

The "realistic" profile sizes a sustainable workforce: it multiplies the
per-shift demand (6/2/1) by a coverage factor (default 5.0) so a single
round-the-clock position is backed by enough people to absorb days off,
vacations, and sick leave. With the default factor this yields 30 guards,
10 screeners, and 5 supervisors (45 total). Workers are spread across shifts
(round-robin) and only available ~5-6 days/week, so the engine has to make
real scheduling choices instead of placing everyone everywhere.

Usage:
    python3 server/scripts/generate_workers_csv.py
    python3 server/scripts/generate_workers_csv.py --profile adequate --output server/database/data/workers-large.csv
    python3 server/scripts/generate_workers_csv.py --profile realistic --output server/database/data/workers-realistic.csv
    python3 server/scripts/generate_workers_csv.py --profile realistic --coverage-factor 4.8
    python3 server/scripts/generate_workers_csv.py --count 80 --seed 42
"""

from __future__ import annotations

import argparse
import csv
import math
import random
from pathlib import Path

FIXED_HEADERS = [
    "full_name",
    "israeli_id",
    "role",
    "status",
    "hourly_cost",
    "min_monthly_hours",
    "max_monthly_hours",
]

SHIFT_COLUMNS = {
    "A": "Shift_A",
    "B": "Shift_B",
    "C": "Shift_C",
}

HEADERS = FIXED_HEADERS + [SHIFT_COLUMNS[code] for code in ("A", "B", "C")]

DAY_TOKENS = ("Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat")

ROLES = ("General Guard", "Supervisor", "Screener")
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

# Per-shift staffing demand from ReferenceDataSeeder (6 guards, 2 screeners,
# 1 supervisor on every shift). The "realistic" profile multiplies this by a
# coverage factor to size a workforce that can sustain 24/7 coverage while
# individual workers still take regular days off.
PER_SHIFT_DEMAND: dict[str, int] = {
    "General Guard": 6,
    "Screener": 2,
    "Supervisor": 1,
}

# Default headcount multiplier per round-the-clock position. 5.0 -> 30/10/5
# (45 total); 4.8 is a leaner target (~29/10/5).
DEFAULT_COVERAGE_FACTOR = 5.0


def generate_israeli_id(base_number: int) -> str:
    return f"{base_number % 1_000_000_000:09d}"


def day_token_to_number(token: str) -> int:
    return DAY_TOKENS.index(token)


def compress_days(day_numbers: list[int]) -> str:
    if not day_numbers:
        return ""

    days = sorted(set(day_numbers))

    if days == list(range(7)):
        return "0-6"

    parts: list[str] = []
    start = days[0]
    previous = days[0]

    for day in days[1:]:
        if day == previous + 1:
            previous = day
            continue

        parts.append(str(start) if start == previous else f"{start}-{previous}")
        start = day
        previous = day

    parts.append(str(start) if start == previous else f"{start}-{previous}")

    return "|".join(parts)


def format_shift_availability(days: list[str], shifts: list[str]) -> dict[str, str]:
    day_numbers = [day_token_to_number(day) for day in days]
    availability: dict[str, str] = {}

    for shift_code in SHIFT_CODES:
        availability[SHIFT_COLUMNS[shift_code]] = (
            compress_days(day_numbers) if shift_code in shifts else ""
        )

    return availability


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


def realistic_role_counts(coverage_factor: float) -> dict[str, int]:
    return {
        role: max(1, math.ceil(demand * coverage_factor))
        for role, demand in PER_SHIFT_DEMAND.items()
    }


def build_name_pool(count: int, rng: random.Random) -> list[str]:
    return [
        f"{rng.choice(FIRST_NAMES)} {rng.choice(LAST_NAMES)}"
        for _ in range(count)
    ]


def pick_days(rng: random.Random, profile: str) -> list[str]:
    if profile in ("adequate", "shortage"):
        return list(DAY_TOKENS)

    if profile == "realistic":
        # Everyone takes 1-2 days off each week (the headroom the coverage
        # factor is sized for), so no one is available all 7 days.
        keep = rng.randint(5, 6)
        return sorted(rng.sample(list(DAY_TOKENS), k=keep), key=DAY_TOKENS.index)

    weekday_count = rng.randint(4, 7)
    if rng.random() < 0.15:
        weekday_count = rng.randint(2, 4)

    return sorted(rng.sample(list(DAY_TOKENS), k=weekday_count), key=DAY_TOKENS.index)


def pick_shifts(role: str, rng: random.Random, profile: str, position: int = 0) -> list[str]:
    if profile in ("adequate", "shortage"):
        return list(SHIFT_CODES)

    if profile == "realistic":
        # Round-robin the primary shift across each role's pool so demand is
        # balanced over A/B/C; ~half also cover an adjacent shift for slack.
        primary = SHIFT_CODES[position % len(SHIFT_CODES)]
        shifts = {primary}
        if rng.random() < 0.5:
            shifts.add(SHIFT_CODES[(position + 1) % len(SHIFT_CODES)])
        return sorted(shifts, key=SHIFT_CODES.index)

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
    elif profile == "realistic":
        # ~144 h/month (18 shifts) is the per-worker average needed to meet
        # demand at this headcount; keep minimums just below that target.
        min_hours = rng.choice((120, 128, 136, 144))
    else:
        min_hours = rng.choice((60, 80, 100, 120, 140, 160))

    max_extra = rng.choice((24, 32, 40, 48)) if profile == "realistic" else rng.choice((20, 40, 60, 80, 100))
    max_hours = min(744, min_hours + max_extra)

    return hourly_cost, str(min_hours), str(max_hours)


def build_workers(
    count: int,
    profile: str,
    seed: int,
    coverage_factor: float = DEFAULT_COVERAGE_FACTOR,
) -> list[dict[str, str]]:
    rng = random.Random(seed)

    if profile == "realistic":
        role_counts = realistic_role_counts(coverage_factor)
        total = sum(role_counts.values())
        names = build_name_pool(total, rng)
    elif profile in PROFILE_COUNTS:
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
        for position in range(role_count):
            is_active = True
            if profile == "balanced" and rng.random() < 0.08:
                is_active = False
            elif profile not in ("adequate", "shortage", "realistic") and rng.random() < 0.05:
                is_active = False

            hourly_cost, min_hours, max_hours = pick_contract(role, rng, profile, is_active)
            days = pick_days(rng, profile)
            shifts = pick_shifts(role, rng, profile, position)
            shift_availability = format_shift_availability(days, shifts)

            workers.append(
                {
                    "full_name": names[name_index],
                    "israeli_id": generate_israeli_id(id_base + name_index),
                    "role": role,
                    "status": "Active" if is_active else "Inactive",
                    "hourly_cost": hourly_cost,
                    "min_monthly_hours": min_hours,
                    "max_monthly_hours": max_hours,
                    **shift_availability,
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
        choices=("balanced", "adequate", "shortage", "realistic"),
        default="balanced",
        help=(
            "balanced=random mix; adequate=full-month coverage; shortage=stress test; "
            "realistic=coverage-factor sized workforce (default 30/10/5) with "
            "days-off and shift spread for genuine engine testing."
        ),
    )
    parser.add_argument(
        "--coverage-factor",
        type=float,
        default=DEFAULT_COVERAGE_FACTOR,
        help=(
            "realistic profile only: headcount multiplier per round-the-clock "
            f"position (default {DEFAULT_COVERAGE_FACTOR}; 5.0 -> 45 workers, 4.8 leaner)."
        ),
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

    if args.coverage_factor <= 0:
        raise SystemExit("--coverage-factor must be greater than 0.")

    workers = build_workers(args.count, args.profile, args.seed, args.coverage_factor)
    write_csv(args.output, workers)

    role_totals: dict[str, int] = {role: 0 for role in ROLES}
    active_count = 0

    for worker in workers:
        role_totals[worker["role"]] += 1
        if worker["status"] == "Active":
            active_count += 1

    print(f"Wrote {len(workers)} workers to {args.output}")
    if args.profile == "realistic":
        print(f"Profile: {args.profile} (seed={args.seed}, coverage_factor={args.coverage_factor})")
    else:
        print(f"Profile: {args.profile} (seed={args.seed})")
    print(
        "Roles: "
        + ", ".join(f"{role}={role_totals[role]}" for role in ROLES)
        + f"; active={active_count}"
    )


if __name__ == "__main__":
    main()
