-- shift_role_requirements (authored from the live schema)

CREATE TABLE public.shift_role_requirements (
    id bigint NOT NULL,
    shift_id bigint NOT NULL,
    role_id bigint NOT NULL,
    required_count smallint NOT NULL,
    CONSTRAINT shift_role_requirements_required_count_non_negative CHECK ((required_count >= 0))
);

CREATE SEQUENCE public.shift_role_requirements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE public.shift_role_requirements_id_seq OWNED BY public.shift_role_requirements.id;

ALTER TABLE ONLY public.shift_role_requirements ALTER COLUMN id SET DEFAULT nextval('public.shift_role_requirements_id_seq'::regclass);

ALTER TABLE ONLY public.shift_role_requirements
    ADD CONSTRAINT shift_role_requirements_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.shift_role_requirements
    ADD CONSTRAINT shift_role_requirements_shift_id_role_id_unique UNIQUE (shift_id, role_id);

ALTER TABLE ONLY public.shift_role_requirements
    ADD CONSTRAINT shift_role_requirements_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE RESTRICT;

ALTER TABLE ONLY public.shift_role_requirements
    ADD CONSTRAINT shift_role_requirements_shift_id_foreign FOREIGN KEY (shift_id) REFERENCES public.shifts(id) ON DELETE RESTRICT;

