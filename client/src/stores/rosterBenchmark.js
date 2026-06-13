import { defineStore } from 'pinia'
import { runBenchmark } from '@/api/rosters'
import { runStoreRequest } from '@/stores/storeRequest'

export const useRosterBenchmarkStore = defineStore('rosterBenchmark', {
  state: () => ({
    benchmark: null,
    benchmarking: false,
    error: '',
  }),

  actions: {
    /**
     * Reset the stored error message.
     *
     * @returns {void}
     */
    clearErrors() {
      this.error = ''
    },

    /**
     * Clear the last benchmark result.
     *
     * @returns {void}
     */
    reset() {
      this.benchmark = null
    },

    /**
     * Run a plain vs cost-optimized benchmark for the given month.
     *
     * @param {number} month
     * @param {string} preference distribution preference preset for the optimized run
     * @returns {Promise<object|null>}
     */
    runBenchmark(month, preference) {
      return runStoreRequest(this, {
        loadingKey: 'benchmarking',
        fallback: 'Could not run benchmark. Please try again.',
        request: async () => {
          const response = await runBenchmark({
            month: Number(month),
            distribution_preference: preference,
          })
          this.benchmark = response.data

          return response.data
        },
      })
    },
  },
})
