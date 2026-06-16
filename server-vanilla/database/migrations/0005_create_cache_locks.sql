-- cache_locks (authored from the live schema)

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);

