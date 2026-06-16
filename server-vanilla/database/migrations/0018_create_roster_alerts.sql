-- roster_alerts (authored from the live schema)

CREATE TABLE public.roster_alerts (
    id bigint NOT NULL,
    roster_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    worker_id character(9) NOT NULL,
    worker_name character varying(255),
    min_hours integer,
    scheduled_hours integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT roster_alerts_type_allowed CHECK (((type)::text = 'hours_shortfall'::text))
);

CREATE SEQUENCE public.roster_alerts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.roster_alerts_id_seq OWNED BY public.roster_alerts.id;

ALTER TABLE ONLY public.roster_alerts ALTER COLUMN id SET DEFAULT nextval('public.roster_alerts_id_seq'::regclass);

ALTER TABLE ONLY public.roster_alerts
    ADD CONSTRAINT roster_alerts_pkey PRIMARY KEY (id);

CREATE INDEX roster_alerts_worker_id_index ON public.roster_alerts USING btree (worker_id);

ALTER TABLE ONLY public.roster_alerts
    ADD CONSTRAINT roster_alerts_roster_id_foreign FOREIGN KEY (roster_id) REFERENCES public.rosters(id) ON DELETE CASCADE;

