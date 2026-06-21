# Database Schema

PostgreSQL. Structural rules live in the DB (unique roster per month, 1:1 contracts, check constraints); scheduling rules (max 2 shifts/day, min/max hours, role demand) are enforced by the engine. `users` are admins only — workers never log in.

### Lookup tables

`**roles**`


| Column | Type        | Notes                                              |
| ------ | ----------- | -------------------------------------------------- |
| `id`   | PK          | surrogate key for FKs                              |
| `code` | varchar(20) | unique — `general_guard`, `supervisor`, `screener` |
| `name` | varchar(50) | display label                                      |


`**shifts**`


| Column                    | Type        | Notes                                      |
| ------------------------- | ----------- | ------------------------------------------ |
| `id`                      | PK          | surrogate key for FKs                      |
| `code`                    | varchar(20) | unique — `A`, `B`, `C`                     |
| `start_time` / `end_time` | time        | shift window                               |
| `duration_hours`          | smallint    | `check > 0` — used for monthly hour totals |


`**shift_role_requirements**` — staffing demand (6 / 2 / 1 per shift)


| Column           | Type        | Notes                          |
| ---------------- | ----------- | ------------------------------ |
| `shift_id`       | FK → shifts |                                |
| `role_id`        | FK → roles  |                                |
| `required_count` | smallint    | unique (`shift_id`, `role_id`) |


### HR & contracts

`**workers**`


| Column       | Type         | Notes                                                                   |
| ------------ | ------------ | ----------------------------------------------------------------------- |
| `israeli_id` | char(9) PK   | natural key; CSV upsert key                                             |
| `full_name`  | varchar(255) |                                                                         |
| `role_id`    | FK → roles   | restrict on delete                                                      |
| `is_active`  | boolean      | default `true`; inactive excluded from rostering                        |
| `deleted_at` | timestamp    | nullable; soft-deleted workers archived and hidden from default queries |


`**contracts**` — one per worker


| Column                                    | Type         | Notes                           |
| ----------------------------------------- | ------------ | ------------------------------- |
| `worker_id`                               | char(9) FK   | unique (1:1), cascade on delete |
| `hourly_cost`                             | decimal(8,2) | `check >= 0`                    |
| `min_monthly_hours` / `max_monthly_hours` | smallint     | `check (max >= min)`            |


`**contract_availability**` — one row per allowed (weekday, shift) pair


| Column        | Type           | Notes                                    |
| ------------- | -------------- | ---------------------------------------- |
| `contract_id` | FK → contracts | cascade on delete                        |
| `day_of_week` | smallint       | 0–6 (0 = Sunday); unique with `shift_id` |
| `shift_id`    | FK → shifts    | restrict on delete                       |


### Rostering

`**rosters**` — one row per calendar month


| Column                          | Type       | Notes                             |
| ------------------------------- | ---------- | --------------------------------- |
| `period_start`                  | date       | first day of month; unique        |
| `status`                        | varchar    | `processing` / `ready` / `failed` |
| `generated_at`                  | timestamp  | set when generation completes     |
| `created_by`                    | FK → users | restrict on delete                |


`**roster_assignments**` — core fact table (one worker × one shift × one date)


| Column        | Type         | Notes                                                  |
| ------------- | ------------ | ------------------------------------------------------ |
| `roster_id`   | FK → rosters | cascade on delete                                      |
| `worker_id`   | char(9) FK   | restrict on delete                                     |
| `shift_id`    | FK → shifts  | restrict on delete                                     |
| `work_date`   | date         |                                                        |
| `source`      | varchar      | `check in (auto, manual)`                              |
| `hourly_cost` | decimal(8,2) | `check >= 0` — contract rate snapshotted at assignment |


Role is derived from `workers.role_id` (no `role_id` column on assignments). Unique (`roster_id`, `worker_id`, `work_date`, `shift_id`).

### Report snapshots

Refreshed after generation, regeneration, manual edits, and worker changes — read from storage, not recomputed on every page load.

`**roster_alerts**` — per-worker issues (today: hours below contract minimum)


| Column                          | Type         | Notes                                       |
| ------------------------------- | ------------ | ------------------------------------------- |
| `roster_id`                     | FK → rosters | cascade on delete                           |
| `type`                          | varchar      | `hours_shortfall`                           |
| `worker_id`                     | char(9)      |                                             |
| `worker_name`                   | varchar      | nullable; snapshot so alerts survive delete |
| `min_hours` / `scheduled_hours` | integer      | nullable                                    |


`**coverage_shortages**` — understaffed slots (no worker — keyed by date/shift/role)


| Column                              | Type         | Notes                 |
| ----------------------------------- | ------------ | --------------------- |
| `roster_id`                         | FK → rosters | cascade on delete     |
| `work_date`                         | date         |                       |
| `shift_id` / `role_id`              | FK           | restrict on delete    |
| `required_count` / `assigned_count` | integer      | demand vs actual fill |


### Async job queue

Three database-backed queues drained by the worker daemon (`bin/worker.php`); each row is both the work item and its payload/result store, so no shared volume is needed. Shared shape: `uuid` PK, a `state` machine (`queued` → `processing` → `completed` / `failed`), `reserved_at` (stamped when the worker claims the row), `created_at` / `updated_at`, and a `(state, created_at)` index serving the claim (oldest queued first). Rows are retained — there is no automatic purge today.

`**worker_csv_jobs**` — CSV import/export queue + status store


| Column              | Type    | Notes                                            |
| ------------------- | ------- | ------------------------------------------------ |
| `id`                | uuid PK |                                                  |
| `type`              | text    | `check in (import, export)`                      |
| `state`             | text    | `queued` / `processing` / `completed` / `failed` |
| `payload`           | text    | uploaded CSV (import input)                      |
| `result` / `errors` | jsonb   | status + per-row errors the client polls         |
| `content`           | text    | generated CSV (export output)                    |
| `message`           | text    | failure message                                  |
| `reserved_at`       | timestamptz | set when the worker claims the job           |


`**roster_generation_jobs**` — roster generate/regenerate queue


| Column                    | Type         | Notes                                                                                       |
| ------------------------- | ------------ | ------------------------------------------------------------------------------------------- |
| `id`                      | uuid PK      |                                                                                             |
| `roster_id`               | FK → rosters | cascade on delete                                                                           |
| `optimize_cost`           | boolean      | run the cost-optimizer pass                                                                  |
| `distribution_preference` | text         | nullable; `maximum_savings` / `cost_focused` / `balanced` / `distribution_focused` (NULL = none) |
| `state`                   | text         | `queued` / `processing` / `completed` / `failed`                                            |
| `message`                 | text         | failure message                                                                             |
| `reserved_at`             | timestamptz  | set when claimed                                                                            |


The client polls the roster's own `rosters.status`, not this table — the row is purely the worker's work queue.

`**roster_export_jobs**` — roster CSV export queue + content store


| Column        | Type         | Notes                                            |
| ------------- | ------------ | ------------------------------------------------ |
| `id`          | uuid PK      |                                                  |
| `roster_id`   | FK → rosters | cascade on delete                                |
| `state`       | text         | `queued` / `processing` / `completed` / `failed` |
| `content`     | text         | generated CSV                                    |
| `filename`    | text         | download filename                                |
| `message`     | text         | failure message                                  |
| `reserved_at` | timestamptz  | set when claimed                                 |


Download reads-then-deletes the row (stream-then-forget).

Plus baseline Laravel tables carried over from the original schema: `sessions`, `cache`, `jobs`, `failed_jobs`, `personal_access_tokens` (the vanilla async queue uses the dedicated tables above, not `jobs`).

### Key indexes


| Index                                                                     | Serves                                   |
| ------------------------------------------------------------------------- | ---------------------------------------- |
| `roster_assignments (roster_id, work_date, shift_id)`                     | Calendar grid reads                      |
| `roster_assignments` unique `(roster_id, worker_id, work_date, shift_id)` | Slot integrity + manual assign checks    |
| `roster_assignments (worker_id)`                                          | Per-worker hours, export, worker cleanup |
| `workers (role_id)`                                                       | Eligible-worker filtering by role        |
| `coverage_shortages (roster_id, work_date, shift_id)`                     | Shortage report load                     |
| `worker_csv_jobs` / `roster_generation_jobs` / `roster_export_jobs` `(state, created_at)` | Worker claim — oldest queued job first   |


Assignments are bulk-inserted in chunks, so write overhead stays low even for full months.
