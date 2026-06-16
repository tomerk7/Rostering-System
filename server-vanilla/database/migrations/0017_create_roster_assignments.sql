-- roster_assignments (authored from the live schema)

CREATE TABLE public.roster_assignments (
    id bigint NOT NULL,
    roster_id bigint NOT NULL,
    worker_id character(9) NOT NULL,
    shift_id bigint NOT NULL,
    work_date date NOT NULL,
    source character varying(255) DEFAULT 'auto'::character varying NOT NULL,
    hourly_cost numeric(8,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT roster_assignments_hourly_cost_non_negative CHECK ((hourly_cost >= (0)::numeric)),
    CONSTRAINT roster_assignments_source_allowed CHECK (((source)::text = ANY ((ARRAY['auto'::character varying, 'manual'::character varying])::text[])))
);

CREATE SEQUENCE public.roster_assignments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.roster_assignments_id_seq OWNED BY public.roster_assignments.id;

ALTER TABLE ONLY public.roster_assignments ALTER COLUMN id SET DEFAULT nextval('public.roster_assignments_id_seq'::regclass);

ALTER TABLE ONLY public.roster_assignments
    ADD CONSTRAINT roster_assignments_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.roster_assignments
    ADD CONSTRAINT roster_assignments_roster_id_worker_id_work_date_shift_id_uniqu UNIQUE (roster_id, worker_id, work_date, shift_id);

CREATE INDEX roster_assignments_roster_id_work_date_shift_id_index ON public.roster_assignments USING btree (roster_id, work_date, shift_id);

CREATE INDEX roster_assignments_worker_id_index ON public.roster_assignments USING btree (worker_id);

ALTER TABLE ONLY public.roster_assignments
    ADD CONSTRAINT roster_assignments_roster_id_foreign FOREIGN KEY (roster_id) REFERENCES public.rosters(id) ON DELETE CASCADE;

ALTER TABLE ONLY public.roster_assignments
    ADD CONSTRAINT roster_assignments_shift_id_foreign FOREIGN KEY (shift_id) REFERENCES public.shifts(id) ON DELETE RESTRICT;

ALTER TABLE ONLY public.roster_assignments
    ADD CONSTRAINT roster_assignments_worker_id_foreign FOREIGN KEY (worker_id) REFERENCES public.workers(israeli_id) ON DELETE RESTRICT;

