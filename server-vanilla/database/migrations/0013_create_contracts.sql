-- contracts (authored from the live schema)

CREATE TABLE public.contracts (
    id bigint NOT NULL,
    worker_id character(9) NOT NULL,
    hourly_cost numeric(8,2) NOT NULL,
    min_monthly_hours smallint NOT NULL,
    max_monthly_hours smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT contracts_hourly_cost_non_negative CHECK ((hourly_cost >= (0)::numeric)),
    CONSTRAINT contracts_max_monthly_hours_greater_than_or_equal_to_min CHECK ((max_monthly_hours >= min_monthly_hours))
);

CREATE SEQUENCE public.contracts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.contracts_id_seq OWNED BY public.contracts.id;

ALTER TABLE ONLY public.contracts ALTER COLUMN id SET DEFAULT nextval('public.contracts_id_seq'::regclass);

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_worker_id_unique UNIQUE (worker_id);

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_worker_id_foreign FOREIGN KEY (worker_id) REFERENCES public.workers(israeli_id) ON DELETE CASCADE;

