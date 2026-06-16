-- shifts (authored from the live schema)

CREATE TABLE public.shifts (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    start_time time(0) without time zone NOT NULL,
    end_time time(0) without time zone NOT NULL,
    duration_hours smallint NOT NULL,
    CONSTRAINT shifts_duration_hours_positive CHECK ((duration_hours > 0))
);

CREATE SEQUENCE public.shifts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.shifts_id_seq OWNED BY public.shifts.id;

ALTER TABLE ONLY public.shifts ALTER COLUMN id SET DEFAULT nextval('public.shifts_id_seq'::regclass);

ALTER TABLE ONLY public.shifts
    ADD CONSTRAINT shifts_code_unique UNIQUE (code);

ALTER TABLE ONLY public.shifts
    ADD CONSTRAINT shifts_pkey PRIMARY KEY (id);

