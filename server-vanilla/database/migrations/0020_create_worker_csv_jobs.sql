-- worker_csv_jobs: the vanilla service's own async queue + status store for CSV
-- import/export.
--
-- payload holds the uploaded CSV (import); content holds the generated CSV
-- (export); result/errors hold the status payload the client polls for. The DB
-- doubles as the queue and the payload store, so no shared volume is needed.

CREATE TABLE public.worker_csv_jobs (
    id uuid NOT NULL,
    type text NOT NULL,
    state text NOT NULL DEFAULT 'queued',
    payload text,
    result jsonb,
    errors jsonb,
    content text,
    message text,
    created_at timestamptz NOT NULL DEFAULT now(),
    reserved_at timestamptz,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT worker_csv_jobs_pkey PRIMARY KEY (id),
    CONSTRAINT worker_csv_jobs_type_check CHECK (type IN ('import', 'export')),
    CONSTRAINT worker_csv_jobs_state_check CHECK (state IN ('queued', 'processing', 'completed', 'failed'))
);

-- The worker claims the oldest queued job; index the claim predicate.
CREATE INDEX worker_csv_jobs_state_created_at_index ON public.worker_csv_jobs (state, created_at);

