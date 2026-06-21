# Rostering System — Manual End-to-End Test Plan

A hands-on checklist to walk through the **running application** before submitting the
assignment. Every functional area from the brief (HR CRUD, contracts, CSV import/export,
the rostering engine, and the two bonus features) is covered, plus edge cases, negative
tests, and the "be ready to defend" points the reviewer will probe.

> Run each case in order within a section. Tick **Result** as you go.
> `☐ Pass  ☐ Fail` — write the actual behaviour in **Notes** when it fails.

---

## How to use this document

1. **Bring the stack up fresh** so you test exactly what the reviewer will:
   ```bash
   make docker-down            # stop anything running
   make docker-init-dev        # one command: db + api + worker + nginx + client
   make db-rebuild             # fresh migrate + seed (clean DB + the login user)
   ```
2. Open the app: **http://localhost:5173** (API is **http://localhost:8000**).
3. Log in with the seeded admin: **`test@example.com` / `password`**.
4. Keep a `psql` shell handy for the DB-verification steps: `make db-psql`.
5. Sample data lives in `server-vanilla/database/data/`:
   `workers-sample.csv` (12), `realistic.csv` (45), `optimization.csv` (54).

**Legend** — `☐` not run · `P` pass · `F` fail · 🔎 verify in DB/UI · ⚠️ known limitation to be ready to explain.

**Coverage map (assignment → section)**

| Assignment section | Covered in |
| --- | --- |
| 2.1 HR Management (worker CRUD) | §2, §3 |
| 2.2 HR Contract Management | §4 |
| 2.3 CSV Import / Export | §5, §6 |
| 2.4 Rostering Engine | §7, §8, §9, §10, §11 |
| 2.5 Bonus Features (×2 mandatory) | §12, §13, §14 |
| 3. Technical Requirements | §1, §16, §17 |
| 4. Deliverables / Definition of Done | §17 |

---

## §0 — Pre-flight smoke (the "does it even boot" check)

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 0.1 | Run `make docker-init-dev` on a clean machine (no manual DB steps) | Full stack comes up with a **single command**; `docker ps` shows `db`, `server-vanilla`, `server-vanilla-worker`, `nginx`, `client` all Up | ☐ |
| 0.2 | Open `http://localhost:5173` | SPA loads, redirects to **/login** (not authenticated) | ☐ |
| 0.3 | `curl http://localhost:8000/__vanilla/health` | JSON `{"status":"ok", ... "db":"ok"}` | ☐ |
| 0.4 | Check the worker container is alive | `docker ps` shows `rostering_server_vanilla_worker` Up (jobs need it) | ☐ |

---

## §1 — Authentication & session

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 1.1 | Log in with `test@example.com` / `password` | Redirect to Home; nav shows authenticated app | ☐ |
| 1.2 | Log in with `test@example.com` / `wrong` | Rejected with an error message; **401**, no token stored | ☐ |
| 1.3 | Submit login with empty email/password | Client/`422` validation error before any auth check | ☐ |
| 1.4 | While logged out, manually visit `http://localhost:5173/workers` | Router guard redirects to **/login** | ☐ |
| 1.5 | Call a protected API without a token: `curl http://localhost:8000/api/workers` | **401 Unauthorized** | ☐ |
| 1.6 | Call with a garbage token: `curl -H "Authorization: Bearer abc" .../api/workers` | **401** (invalid JWT) | ☐ |
| 1.7 | 🔎 Single active session — log in, copy token A; log in again (token B); retry a request with token A | Token A is rejected (single-active-session per user per README) | ☐ |
| 1.8 | Log out | Returns to /login; protected routes redirect again | ☐ |

---

## §2 — HR Management: Worker CRUD (assignment 2.1)

> Israeli ID note: the system validates **format = exactly 9 digits + uniqueness**. It does
> **not** run the Israeli check-digit algorithm. Decide if that's acceptable and be ready to
> say so (see §18). Use distinct 9-digit IDs below.

### Create — happy path

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 2.1 | Workers → **Create**. Fill: name `Dana Cohen`, ID `234567816`, role **Supervisor**, status **Active**, hourly cost `75`, min `120`, max `180`, tick at least one availability slot (e.g. Sun/Shift A). Save | **201**, worker appears in the list with role Supervisor, Active | ☐ |
| 2.2 | Create a **General Guard** (ID `314159260`) and a **Screener** (ID `271828188`) | Both created; all three roles representable | ☐ |
| 2.3 | 🔎 In `psql`: `SELECT israeli_id, role_id, is_active FROM workers ORDER BY israeli_id;` | Rows match what you entered; `is_active` correct | ☐ |

### Create — Israeli ID validation (edge cases)

| # | Israeli ID entered | Expected result | Result |
| --- | --- | --- | --- |
| 2.4 | `123` (too short) | **422**, field error on `israeli_id` ("must be exactly 9 digits") | ☐ |
| 2.5 | `1234567890` (10 digits) | **422** israeli_id error | ☐ |
| 2.6 | `12345678a` (letter) | **422** israeli_id error | ☐ |
| 2.7 | `234567816` again (duplicate of 2.1) | **422** "has already been taken" (unique) | ☐ |
| 2.8 | `000000000` (9 digits, fails real check-digit) | **Accepted** (⚠️ format-only validation — expected with current rules; note for reviewer) | ☐ |

### Create — required fields & ranges

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 2.9 | Submit with empty full name | 422, `full_name` required | ☐ |
| 2.10 | Submit full name > 255 chars | 422, max:255 | ☐ |
| 2.11 | Submit with **no role** selected | 422, `role_id` required | ☐ |
| 2.12 | Submit with **no availability slot** ticked | 422, `availability` required (min 1) | ☐ |
| 2.13 | Submit with no status / no contract block | 422 with the relevant field errors | ☐ |

### Read / list

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 2.14 | Open the Workers list | Shows all non-deleted workers; pagination meta present | ☐ |
| 2.15 | If search/sort controls exist, search by name and sort columns | Results filter/sort correctly | ☐ |
| 2.16 | Open a worker's detail/edit page | Shows name, ID, role, status, full contract + availability | ☐ |
| 2.17 | Open a non-existent ID via URL `/workers/999999999/edit` | **404 / not-found** handled gracefully | ☐ |

### Update

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 2.18 | Edit `Dana Cohen`: change name, role, hourly cost, toggle an availability slot. Save | **200**, changes persisted and shown | ☐ |
| 2.19 | Edit and set an **invalid** value (max < min, see §4) | 422, change rejected | ☐ |

### Status: Active / Inactive (distinct from delete)

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 2.20 | Deactivate a worker | Worker **stays in the list** but shows Inactive | ☐ |
| 2.21 | 🔎 Inactive worker is **excluded from rostering** (verify later in §7.6) | Confirmed in engine section | ☐ |
| 2.22 | Re-activate the worker (edit status → Active) | Back to Active | ☐ |

### Delete / restore (soft delete)

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 2.23 | Delete a worker | Disappears from the default list; **200** | ☐ |
| 2.24 | 🔎 `SELECT deleted_at FROM workers WHERE israeli_id='…';` | `deleted_at` is set (soft delete, row not destroyed) | ☐ |
| 2.25 | Restore that worker | Reappears in the list | ☐ |
| 2.26 | Delete an unknown worker via API | **404** | ☐ |

### Bulk operations

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 2.27 | "Delete all" workers | All soft-deleted; list empty; response reports the count | ☐ |
| 2.28 | "Restore all" | All restored; counts match | ☐ |

---

## §3 — Worker ↔ Contract relationship integrity

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 3.1 | 🔎 After creating a worker, confirm exactly **one** contract row exists for them | `SELECT count(*) FROM contracts WHERE worker_id='…';` → 1 (1:1 enforced) | ☐ |
| 3.2 | 🔎 Hard-delete a worker row in DB (or use cascade) and check contract/availability | Contract + availability cascade-delete with the worker | ☐ |
| 3.3 | 🔎 Availability rows are one per (day_of_week, shift) | No duplicate (day, shift) pairs for a contract (unique constraint) | ☐ |

---

## §4 — HR Contract Management (assignment 2.2)

> Terms: hourly cost (ILS), availability days, availability shifts, min & max monthly hours.

| # | What to do (on Create/Edit) | Expected result | Result |
| --- | --- | --- | --- |
| 4.1 | Set hourly cost `0` | Accepted (min:0) | ☐ |
| 4.2 | Set hourly cost `-5` | 422 (`min:0`) | ☐ |
| 4.3 | Set hourly cost `1000000` | 422 (`max:999999.99`) | ☐ |
| 4.4 | Set hourly cost `52.55` (decimal) | Accepted; 🔎 stored as `decimal(8,2)` | ☐ |
| 4.5 | Set min `120`, max `100` (max < min) | **422** — `gte:min` violation; also DB `check (max >= min)` | ☐ |
| 4.6 | Set min `120`, max `120` (equal) | Accepted | ☐ |
| 4.7 | Set min `-1` or max `745` (out of 0–744) | 422 (range 0–744) | ☐ |
| 4.8 | Availability: select specific **days** only (e.g. Sun–Thu) | Saved; only those weekdays available | ☐ |
| 4.9 | Availability: select specific **shifts** only (e.g. Shift B only) | Saved; only that shift available | ☐ |
| 4.10 | Try to save with **zero** availability slots | 422 (min:1) — a worker must be available somewhere | ☐ |
| 4.11 | 🔎 Edit availability then re-open | The availability grid reflects the saved (day, shift) pairs exactly | ☐ |

---

## §5 — CSV Import (assignment 2.3)

> Import is a **queued job**: the worker daemon processes it and the UI polls + shows a
> report. Invalid rows are **skipped and reported**, the rest still import (no all-or-nothing).
> Upsert key is `israeli_id`.

### Happy path

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 5.1 | Workers → **Import** → upload `workers-sample.csv` (12 rows) | Job runs; report: **Imported 12 of 12 (12 created, 0 updated, 0 skipped)**; workers appear | ☐ |
| 5.2 | 🔎 Spot-check one worker's availability matches its CSV day-expression (e.g. `Yossi Levi` 1-7 on all shifts) | Availability grid shows all 7 days × all 3 shifts | ☐ |
| 5.3 | Download the **sample CSV** from inside the import modal | A valid, importable file downloads | ☐ |

### Upsert / re-import behaviour

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 5.4 | Re-import the **same** `workers-sample.csv` | Report: **0 created, 12 updated, 0 skipped** (upsert by ID) | ☐ |
| 5.5 | Soft-delete one imported worker, then re-import the file | That worker is **restored** (re-import un-deletes) | ☐ |
| 5.6 | Edit a CSV row's hourly cost / availability, re-import | The existing worker's contract + availability are **replaced** with the new values | ☐ |

### Per-row validation (the important edge cases — build a small bad CSV)

Create `bad.csv` with the header row plus these deliberately broken rows, then import:

```
full_name,israeli_id,role,status,hourly_cost,min_monthly_hours,max_monthly_hours,00:00-08:00,08:00-16:00,16:00-00:00
Good Worker,222222220,General Guard,Active,50,80,160,1-5,1-5,
Bad ID,123,Screener,Active,50,80,160,1-5,,
Unknown Role,333333330,Wizard,Active,50,80,160,1-5,,
Missing Name,,444444440,Supervisor,Active,50,80,160,1-5
Max Below Min,555555550,General Guard,Active,50,160,80,1-5,,
No Availability,666666660,Screener,Active,50,80,160,,,
Dup In File,222222220,General Guard,Active,50,80,160,,1-5,
Bad Day Expr,777777770,Screener,Active,50,80,160,1-9,,
```

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 5.7 | Import `bad.csv` | Job completes (does **not** abort). Report shows some imported + the rest **skipped** with structured errors | ☐ |
| 5.8 | Row "Bad ID" (`123`) | Reported: `{ line, field: israeli_id, message }` — skipped | ☐ |
| 5.9 | Row "Unknown Role" (`Wizard`) | Reported: invalid role — skipped | ☐ |
| 5.10 | Row "Max Below Min" (160 > 80) | Reported: max < min — skipped | ☐ |
| 5.11 | Row "No Availability" (all shift cells empty) | Reported: at least one shift column required — skipped | ☐ |
| 5.12 | Row "Dup In File" (duplicate `222222220`) | Reported: duplicate israeli_id within the file — skipped | ☐ |
| 5.13 | Row "Bad Day Expr" (`1-9`, out of 1–7 range) | Reported: bad day expression — skipped | ☐ |
| 5.14 | The single **valid** row ("Good Worker") | **Imported** despite the broken rows around it | ☐ |

### Status & role parsing

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 5.15 | A row with a **blank** status cell | Defaults to **Active** | ☐ |
| 5.16 | A row with role `general guard` / `SCREENER` (mixed case) | Accepted (case-insensitive role match) | ☐ |
| 5.17 | A row with status `Inactive` | Imported as inactive | ☐ |

### File-level errors

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 5.18 | Upload a **non-CSV** file (e.g. a `.png`) | **422** file error, friendly message | ☐ |
| 5.19 | Upload an empty file / header-only file | Handled gracefully (0 imported, clear message) | ☐ |
| 5.20 | Upload a file with the **wrong headers / column order** | Clear error rather than silent mis-import | ☐ |
| 5.21 | Submit import with no file chosen | "Please choose a CSV file" message, no request | ☐ |

---

## §6 — CSV Export & round-trip (assignment 2.3)

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 6.1 | Import `realistic.csv`, then Workers → **Export** | Downloads `workers-YYYY-MM-DD.csv` | ☐ |
| 6.2 | Open the export; check the header & columns | Exactly the documented schema (10 columns, shift headers `00:00-08:00` etc.) | ☐ |
| 6.3 | Confirm exported availability uses the day-expression syntax (`1-7`, `2\|4\|6`) | Matches the import format | ☐ |
| 6.4 | **Round-trip**: re-import the exported file unchanged | **0 created, N updated, 0 skipped** — re-importable without modification (the assignment's explicit requirement) | ☐ |
| 6.5 | Soft-delete a worker, then Export | Archived/soft-deleted workers are **excluded** from the export | ☐ |
| 6.6 | Export while there are inactive workers | Inactive (but not deleted) workers **are** included with `status=Inactive` | ☐ |

---

## §7 — Rostering Engine: generation (assignment 2.4)

> Prep: a workforce that can actually staff a month. Easiest: **import `realistic.csv`**
> (45 workers, designed to fully staff) — or `make seed-workers args="realistic --fresh"`.

### Generate

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 7.1 | Rosters → **Generate**, pick a month, objective **Standard scheduling**, Generate | Status goes `processing` → **ready**; redirects to the roster grid | ☐ |
| 7.2 | 🔎 Each shift on each day demands **6 General Guards, 2 Screeners, 1 Supervisor** | Grid cells show those counts where staffed (see §8) | ☐ |
| 7.3 | 🔎 Verify the daily/role demand in DB for one date: see SQL below | Per (date, shift, role) assigned ≤ required; full where workforce allows | ☐ |
| 7.4 | Generate for a month with **0 active workers** (delete-all first) | Roster still created but **coverage shortage alerts** list every unfillable slot (§10) — no crash | ☐ |
| 7.5 | Out-of-range month (`13`) via API: `POST /api/rosters {month:13}` | **422** validation error | ☐ |

### Hard constraints (the heart of the assignment)

| # | Constraint to verify | How | Expected | Result |
| --- | --- | --- | --- | --- |
| 7.6 | **Inactive workers never scheduled** | Deactivate a worker, regenerate, search the grid/DB for their ID | Zero assignments for that worker | ☐ |
| 7.7 | **Max 2 shifts/day (no 3-shift)** | SQL below counts shifts per worker per day | **No worker has 3 rows on the same `work_date`** | ☐ |
| 7.8 | **Availability — day** | Pick a worker available only Sun–Thu; check Fri/Sat | No assignments on disallowed weekdays | ☐ |
| 7.9 | **Availability — shift** | Pick a worker available only Shift B; check A/C | No assignments to disallowed shifts | ☐ |
| 7.10 | **Max monthly hours** | SQL sums hours per worker | No worker exceeds their `max_monthly_hours` | ☐ |
| 7.11 | **Role match** | A Supervisor is never placed in a Guard/Screener slot | Role of every assignment = worker's role demand | ☐ |
| 7.12 | **Determinism** | Regenerate the same month twice with the same workforce | Identical roster both times (tie-break = lowest israeli_id) | ☐ |

**Verification SQL** (run in `make db-psql`, replace `:rid` with the roster id):

```sql
-- 7.7 No worker assigned 3 shifts on the same day (MUST return 0 rows):
SELECT worker_id, work_date, count(*)
FROM roster_assignments WHERE roster_id = :rid
GROUP BY worker_id, work_date HAVING count(*) > 2;

-- 7.10 No worker over their max monthly hours (MUST return 0 rows):
SELECT a.worker_id, sum(s.duration_hours) AS hrs, c.max_monthly_hours
FROM roster_assignments a
JOIN shifts s ON s.id = a.shift_id
JOIN contracts c ON c.worker_id = a.worker_id
WHERE a.roster_id = :rid
GROUP BY a.worker_id, c.max_monthly_hours
HAVING sum(s.duration_hours) > c.max_monthly_hours;

-- 7.2/7.3 Per (date, shift, role) coverage vs demand:
SELECT a.work_date, a.shift_id, w.role_id, count(*) AS assigned
FROM roster_assignments a JOIN workers w ON w.israeli_id = a.worker_id
WHERE a.roster_id = :rid
GROUP BY a.work_date, a.shift_id, w.role_id
ORDER BY a.work_date, a.shift_id, w.role_id LIMIT 30;
```

---

## §8 — Calendar / grid view (assignment 2.4)

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 8.1 | Open a generated roster | A clear **monthly calendar/grid**: days × shifts, with workers per slot | ☐ |
| 8.2 | Inspect one fully-staffed shift cell | Shows 6 guards + 2 screeners + 1 supervisor (9 names) | ☐ |
| 8.3 | Inspect an understaffed cell (use a thin workforce) | Visibly flagged as short / highlighted | ☐ |
| 8.4 | Navigate across weeks/the whole month | All days render; no broken/empty layout | ☐ |
| 8.5 | Check a 31-day month, a 30-day month, and **February** | Correct number of days each; no off-by-one | ☐ |

---

## §9 — Manual assignment edits (assignment 2.4: "manual moving, adding, etc.")

> Open a generated roster, open a slot, use the assignment modal. The modal should only
> offer **eligible** workers. The API enforces the rules server-side too — test both.

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 9.1 | Manually **add** a worker to an open slot of their role | Assignment added (`source = manual`); cell count increases | ☐ |
| 9.2 | Manually **remove** an assignment | Removed; cell count decreases | ☐ |
| 9.3 | Manual **move** = remove from slot X, add to slot Y | Both reflected; reports refresh | ☐ |
| 9.4 | Try to add a worker to a **3rd shift** on a day they already have 2 | **Rejected** — "exceeds daily shift limit" (max-2 rule enforced manually too) | ☐ |
| 9.5 | Try to add a worker on a **day/shift they're not available** | Rejected — unavailable | ☐ |
| 9.6 | Try to add to a slot whose role is **already full** (e.g. 7th guard) | Rejected — role at capacity | ☐ |
| 9.7 | Try to add the **same worker twice** to the same (date, shift) | Rejected — duplicate slot | ☐ |
| 9.8 | Try to add a worker who'd exceed **max monthly hours** | Rejected — over max hours | ☐ |
| 9.9 | Try to add an **inactive** worker | Rejected — inactive | ☐ |
| 9.10 | Add an assignment with a date **outside the roster's month** (via API) | Rejected — date outside roster month | ☐ |
| 9.11 | 🔎 After a manual add that fills a previously-short cell | The matching **coverage shortage clears** (report recomputed, not stale) | ☐ |
| 9.12 | 🔎 After a manual add that lifts a worker to their min hours | Their **hours-shortfall alert clears**; removing it brings the alert back | ☐ |

---

## §10 — Alerts: coverage shortage & hours shortfall (assignment 2.4)

> Prep an **undersized** workforce to force alerts: `make seed-workers args="shortage --fresh"`
> (6 guards / 2 screeners / 1 supervisor — cannot staff a 24/7 month).

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 10.1 | Generate a roster on the shortage workforce | Generation completes **and raises a clear alert** before the roster is relied upon | ☐ |
| 10.2 | Read the **coverage shortage** alert | Lists exactly **which shifts/roles/dates cannot be filled** (assigned < required) | ☐ |
| 10.3 | 🔎 `SELECT count(*) FROM coverage_shortages WHERE roster_id=:rid;` | Non-zero, matches what the UI shows | ☐ |
| 10.4 | Read the **per-worker hours shortfall** alerts | Each under-min worker listed with `min_hours` vs `scheduled_hours` (the shortfall) | ☐ |
| 10.5 | 🔎 `SELECT * FROM roster_alerts WHERE roster_id=:rid AND type='hours_shortfall';` | Rows match the UI | ☐ |
| 10.6 | Generate on the **realistic** workforce instead | Few/no coverage shortages (fully staffs) — confirms alerts are real, not always-on | ☐ |
| 10.7 | After generation, **edit a worker** (e.g. lower max hours) then re-open the roster reports | Reports **recompute automatically** (not stale) | ☐ |
| 10.8 | **Delete a worker** who had assignments, re-open reports | Coverage/shortfall recomputed; alert keeps the **worker name snapshot** even after delete | ☐ |

---

## §11 — Roster lifecycle: regenerate, uniqueness, delete, status

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 11.1 | Generate for a month that already has a roster | UI warns "a roster already exists… generating will replace it"; regenerates | ☐ |
| 11.2 | 🔎 `SELECT count(*) FROM rosters WHERE period_start='YYYY-MM-01';` | Exactly **1** roster per month (unique `period_start`) | ☐ |
| 11.3 | Regenerate an existing roster | New assignments replace old; status returns to ready | ☐ |
| 11.4 | ⚠️ After a manual edit, **regenerate** | Manual assignments are **wiped** by regenerate (known limitation — be ready to explain, see §18) | ☐ |
| 11.5 | Delete a roster | Removed from the list; **200**. Assignments/alerts cascade-delete | ☐ |
| 11.6 | Delete / fetch an unknown roster id | **404** | ☐ |
| 11.7 | Watch the status during generation | `processing` → `ready` (or `failed` on error), surfaced in the UI | ☐ |
| 11.8 | List rosters | Shows current-year rosters with correct months/status | ☐ |

---

## §12 — BONUS 1: Cost optimizer + objective selection (assignment 2.5)

> Best shown with `optimization.csv` (cheap/expensive pools + a bench).

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 12.1 | Import `optimization.csv`. Generate with **Standard scheduling** | Roster ready; note total cost (from stats §14) | ☐ |
| 12.2 | Regenerate the same month with **Maximum savings** | Total payroll cost **lower** than Standard; coverage still satisfied | ☐ |
| 12.3 | Try **Cost focused**, **Balanced**, **Spread hours evenly** in turn | Cost vs even-hours trade-off shifts predictably across presets | ☐ |
| 12.4 | 🔎 All hard constraints still hold after optimization | Re-run the §7 SQL checks — still 0 violations | ☐ |
| 12.5 | Confirm coverage is **not** sacrificed for cost | Coverage shortages no worse than the Standard run | ☐ |

---

## §13 — BONUS 2: Generation benchmark (assignment 2.5)

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 13.1 | Rosters → **Benchmark**, pick a month + an objective, run | Reports baseline vs objective: **cost, hours, shortfall, workload-spread deltas** | ☐ |
| 13.2 | Read the per-worker changes | Shows who moved between the two runs | ☐ |
| 13.3 | Run benchmark with **no workforce** (delete-all) | Graceful **422** ("nothing to schedule"), not a crash | ☐ |
| 13.4 | Run benchmark **without** picking an objective | **422** — `distribution_preference` required | ☐ |
| 13.5 | Confirm the benchmark **does not save** a roster | It's a comparison only; rosters list unchanged | ☐ |

---

## §14 — Roster statistics grid + roster CSV export (assignment 2.5 / other)

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 14.1 | Open a saved roster → **Stats** | Per-worker grid: scheduled hours vs contractual **min/max**, utilisation %, projected cost | ☐ |
| 14.2 | Check the **leaderboards** | Highest-paid and most/least-scheduled workers shown | ☐ |
| 14.3 | 🔎 Cross-check one worker's projected cost = `scheduled_hours × hourly_cost` | Matches | ☐ |
| 14.4 | Export the **roster** to CSV (if exposed) | Async export job completes; file downloads | ☐ |
| 14.5 | Stats for a roster with shortfalls | Under-min workers clearly flagged | ☐ |

---

## §15 — Async jobs & progress (cross-cutting, scalability)

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 15.1 | Start a large import (`optimization.csv`) and watch the UI | Returns immediately (202), client **polls progress**, then shows the report | ☐ |
| 15.2 | Stop the worker container, start an import | Job stays queued (`processing`), no error; resumes when worker restarts | ☐ |
| 15.3 | 🔎 Job tables: `SELECT status, count(*) FROM worker_csv_jobs GROUP BY status;` | Jobs transition queued → done | ☐ |
| 15.4 | Generation of a large workforce | HTTP returns fast; generation runs on the queue (not in the request) | ☐ |

---

## §16 — Scalability spot checks (assignment §3 "growing dataset")

| # | What to do | Expected result | Result |
| --- | --- | --- | --- |
| 16.1 | Seed a big workforce: `make seed-workers args="realistic --coverage-factor=20 --fresh"` | Hundreds/thousands of workers seed without timeout | ☐ |
| 16.2 | Load the Workers list | Paginated, responsive (no full-table dump) | ☐ |
| 16.3 | Generate a roster on the big workforce | Completes via the queue; UI stays responsive | ☐ |
| 16.4 | 🔎 `EXPLAIN` a key query (e.g. workers list / assignments by roster) | Uses indexes (be ready to defend your indexing strategy) | ☐ |
| 16.5 | 🔎 Import the 10k CSV in `prompts/workers-10k.csv` (if testing limits) | Imports in chunks, completes; report counts add up | ☐ |

---

## §17 — Technical requirements & deliverables (assignment §3 & §4)

| # | Requirement | How to check | Result |
| --- | --- | --- | --- |
| 17.1 | **Relational SQL only** | PostgreSQL; `make db-psql` → `\dt` shows the schema | ☐ |
| 17.2 | **Single-command stack** | `make docker-init-dev` alone brings up app + DB; no manual DB setup | ☐ |
| 17.3 | **docker-compose present & working** | `docker-compose.dev.yml` / `.prod.yml` exist and run | ☐ |
| 17.4 | **README completeness** | Has architecture, DB schema, setup/run, **CSV schema**, and **bonus-feature rationale** | ☐ |
| 17.5 | **Sample data ≥ 10 workers** | `workers-sample.csv` (12), `realistic.csv` (45), `optimization.csv` (54) in repo | ☐ |
| 17.6 | **Not deployed publicly** | Local Docker only — confirm no public deployment | ☐ |
| 17.7 | **Git history / branches** | `git log` shows meaningful, incremental commits | ☐ |
| 17.8 | **Prod mode** | `make docker-init-prod` builds the client and serves it | ☐ |

---

## §18 — Known limitations — be ready to defend (interview prep, not bugs to "pass")

These are documented in the README's *"What I'd improve with more time"* and surface naturally
in the test cases above. Have a one-line answer for each — reviewers reward awareness.

| # | Topic | Talking point | Ready? |
| --- | --- | --- | --- |
| 18.1 | **Israeli ID = format only** | Validates 9 digits + uniqueness, not the check-digit algorithm. Easy to add; trade-off was scope. (§2.8) | ☐ |
| 18.2 | **Contract edits rewrite past rosters** | min/max are read from the *current* contract; cost is snapshotted but hours aren't. Would snapshot targets on the roster. (§10.7) | ☐ |
| 18.3 | **Regenerate wipes manual edits** | Regeneration deletes all assignments incl. `manual`; would pin manual rows. (§11.4) | ☐ |
| 18.4 | **No concurrent-edit protection** | Two admins editing one roster can overwrite silently; would add optimistic locking. | ☐ |
| 18.5 | **Flat authorization** | Every admin can do everything; would add viewer/editor policy + login rate-limiting. | ☐ |
| 18.6 | **No audit trail** | No record of who changed what/when. | ☐ |
| 18.7 | **Job-table retention** | Completed job rows aren't purged; would add a TTL/cleanup. | ☐ |
| 18.8 | **Engine algorithm** | Greedy most-constrained-first + simulated-annealing cost pass; deterministic. Be ready to justify vs. ILP/CP-SAT. | ☐ |

---

## §19 — 10-minute pre-submission smoke run (do this last, on a clean stack)

A fast end-to-end pass that touches every required area. If all green, you're submission-ready.

1. `make docker-down && make docker-init-dev && make db-rebuild` → app loads, login works. (§0, §1)
2. Create one worker via the UI; edit it; deactivate; delete; restore. (§2)
3. Import `workers-sample.csv` → 12 created; re-import → 12 updated. (§5)
4. Import `bad.csv` → valid rows in, bad rows skipped+reported. (§5.7–5.14)
5. Export → re-import the export unchanged → all updated, 0 skipped. (§6.4)
6. Import `realistic.csv`; generate a roster (Standard) → ready; grid shows 6/2/1 per shift. (§7, §8)
7. Run §7 SQL: 3-shift check & max-hours check both return **0 rows**. (§7.7, §7.10)
8. Manually add → blocked on a 3rd-shift attempt; remove → coverage/shortfall recompute. (§9)
9. `seed-workers args="shortage --fresh"`; generate → coverage + hours-shortfall alerts shown. (§10)
10. Generate `optimization.csv` Standard vs Maximum savings → cost drops; run **Benchmark**; open **Stats** + leaderboards. (§12, §13, §14)
11. Skim the README for the 5 required sections. (§17.4)

> ✅ When every box above is ticked, capture a couple of screenshots (roster grid, alerts,
> benchmark, stats) for your walkthrough and you're ready to submit.
