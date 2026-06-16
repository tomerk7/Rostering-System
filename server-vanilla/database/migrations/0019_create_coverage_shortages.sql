-- coverage_shortages (authored from the live schema)

CREATE TABLE public.coverage_shortages (
    id bigint NOT NULL,
    roster_id bigint NOT NULL,
    work_date date NOT NULL,
    shift_id bigint NOT NULL,
    role_id bigint NOT NULL,
    required_count integer NOT NULL,
    assigned_count integer NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE SEQUENCE public.coverage_shortages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.coverage_shortages_id_seq OWNED BY public.coverage_shortages.id;

ALTER TABLE ONLY public.coverage_shortages ALTER COLUMN id SET DEFAULT nextval('public.coverage_shortages_id_seq'::regclass);

ALTER TABLE ONLY public.coverage_shortages
    ADD CONSTRAINT coverage_shortages_pkey PRIMARY KEY (id);

CREATE INDEX coverage_shortages_roster_id_work_date_shift_id_index ON public.coverage_shortages USING btree (roster_id, work_date, shift_id);

ALTER TABLE ONLY public.coverage_shortages
    ADD CONSTRAINT coverage_shortages_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE RESTRICT;

ALTER TABLE ONLY public.coverage_shortages
    ADD CONSTRAINT coverage_shortages_roster_id_foreign FOREIGN KEY (roster_id) REFERENCES public.rosters(id) ON DELETE CASCADE;

ALTER TABLE ONLY public.coverage_shortages
    ADD CONSTRAINT coverage_shortages_shift_id_foreign FOREIGN KEY (shift_id) REFERENCES public.shifts(id) ON DELETE RESTRICT;

