-- cache (authored from the live schema)

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);

