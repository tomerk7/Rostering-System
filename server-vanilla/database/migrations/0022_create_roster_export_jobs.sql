-- roster_export_jobs: the vanilla service's own async queue + content store for
-- roster CSV export. Mirrors `worker_csv_jobs` (export half) but scoped to a
-- roster; the vanilla worker daemon (bin/worker.php) processes it. Kept separate
-- from the shared `jobs` table and from the other vanilla job tables.
--
-- content holds the generated CSV; filename/message carry the poll/download
-- state. The DB doubles as the queue and the payload store, so no shared volume
-- is needed; download reads-then-deletes the row (stream-then-forget).

CREATE TABLE public.roster_export_jobs (
    id uuid NOT NULL,
    roster_id bigint NOT NULL,
    state text NOT NULL DEFAULT 'queued',
    content text,
    filename text,
    message text,
    created_at timestamptz NOT NULL DEFAULT now(),
    reserved_at timestamptz,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT roster_export_jobs_pkey PRIMARY KEY (id),
    CONSTRAINT roster_export_jobs_state_check CHECK (state IN ('queued', 'processing', 'completed', 'failed')),
    CONSTRAINT roster_export_jobs_roster_id_foreign FOREIGN KEY (roster_id) REFERENCES public.rosters(id) ON DELETE CASCADE
);

-- The worker claims the oldest queued job; index the claim predicate.
CREATE INDEX roster_export_jobs_state_created_at_index ON public.roster_export_jobs (state, created_at);

