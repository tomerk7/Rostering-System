-- sessions (authored from the live schema)

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);

