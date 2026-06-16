-- contract_availability (authored from the live schema)

CREATE TABLE public.contract_availability (
    id bigint NOT NULL,
    contract_id bigint NOT NULL,
    day_of_week smallint NOT NULL,
    shift_id bigint NOT NULL,
    CONSTRAINT contract_availability_day_of_week_range CHECK (((day_of_week >= 0) AND (day_of_week <= 6)))
);

CREATE SEQUENCE public.contract_availability_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.contract_availability_id_seq OWNED BY public.contract_availability.id;

ALTER TABLE ONLY public.contract_availability ALTER COLUMN id SET DEFAULT nextval('public.contract_availability_id_seq'::regclass);

ALTER TABLE ONLY public.contract_availability
    ADD CONSTRAINT contract_availability_contract_id_day_of_week_shift_id_unique UNIQUE (contract_id, day_of_week, shift_id);

ALTER TABLE ONLY public.contract_availability
    ADD CONSTRAINT contract_availability_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.contract_availability
    ADD CONSTRAINT contract_availability_contract_id_foreign FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE CASCADE;

ALTER TABLE ONLY public.contract_availability
    ADD CONSTRAINT contract_availability_shift_id_foreign FOREIGN KEY (shift_id) REFERENCES public.shifts(id) ON DELETE RESTRICT;

