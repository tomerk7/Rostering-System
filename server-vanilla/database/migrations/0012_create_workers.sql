-- workers (authored from the live schema)

CREATE TABLE public.workers (
    israeli_id character(9) NOT NULL,
    full_name character varying(255) NOT NULL,
    role_id bigint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);

ALTER TABLE ONLY public.workers
    ADD CONSTRAINT workers_pkey PRIMARY KEY (israeli_id);

CREATE INDEX workers_deleted_at_index ON public.workers USING btree (deleted_at);

CREATE INDEX workers_role_id_index ON public.workers USING btree (role_id);

ALTER TABLE ONLY public.workers
    ADD CONSTRAINT workers_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE RESTRICT;

