-- roster_generation_jobs: the vanilla service's own async queue for roster
-- generation (store + regenerate). Kept separate from `worker_csv_jobs` and from
-- the shared `jobs` table; the vanilla worker daemon (bin/worker.php) processes it.
--
-- The generation parameters (target roster + optimizer settings) ride inline on
-- the row, so no shared volume is needed. The roster's own lifecycle status
-- (`rosters.status`: processing/ready/failed) is what the client polls via
-- `GET /api/rosters/{id}`; this table is purely the worker's work queue.

CREATE TABLE public.roster_generation_jobs (
    id uuid NOT NULL,
    roster_id bigint NOT NULL,
    optimize_cost boolean NOT NULL DEFAULT false,
    balance_weight double precision,
    state text NOT NULL DEFAULT 'queued',
    message text,
    created_at timestamptz NOT NULL DEFAULT now(),
    reserved_at timestamptz,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT roster_generation_jobs_pkey PRIMARY KEY (id),
    CONSTRAINT roster_generation_jobs_state_check CHECK (state IN ('queued', 'processing', 'completed', 'failed')),
    CONSTRAINT roster_generation_jobs_roster_id_foreign FOREIGN KEY (roster_id) REFERENCES public.rosters(id) ON DELETE CASCADE
);

-- The worker claims the oldest queued job; index the claim predicate.
CREATE INDEX roster_generation_jobs_state_created_at_index ON public.roster_generation_jobs (state, created_at);

