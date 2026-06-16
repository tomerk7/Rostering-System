-- rosters (authored from the live schema)

CREATE TABLE public.rosters (
    id bigint NOT NULL,
    period_start date NOT NULL,
    status character varying(255) DEFAULT 'ready'::character varying NOT NULL,
    generated_at timestamp(0) without time zone,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE SEQUENCE public.rosters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.rosters_id_seq OWNED BY public.rosters.id;

ALTER TABLE ONLY public.rosters ALTER COLUMN id SET DEFAULT nextval('public.rosters_id_seq'::regclass);

ALTER TABLE ONLY public.rosters
    ADD CONSTRAINT rosters_period_start_unique UNIQUE (period_start);

ALTER TABLE ONLY public.rosters
    ADD CONSTRAINT rosters_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.rosters
    ADD CONSTRAINT rosters_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;

