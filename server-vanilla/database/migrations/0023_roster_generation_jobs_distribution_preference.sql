-- Carry the chosen distribution preference on the generation job instead of a
-- pre-baked balance weight. The optimizer penalties (balance weight AND
-- shortfall penalty) are now derived from live workforce data by
-- OptimizerPenaltyAdvisor when the worker runs the job, so the categorical
-- preference is what the job needs to persist; the numeric weight is no longer
-- a meaningful input.

ALTER TABLE public.roster_generation_jobs
    ADD COLUMN distribution_preference text,
    DROP COLUMN balance_weight;

-- Mirror the DistributionPreference enum. NULL = no preference (the raw
-- optimize_cost path, which uses the optimizer's default penalties).
ALTER TABLE public.roster_generation_jobs
    ADD CONSTRAINT roster_generation_jobs_distribution_preference_check
    CHECK (
        distribution_preference IS NULL
        OR distribution_preference IN ('maximum_savings', 'cost_focused', 'balanced', 'distribution_focused')
    );
