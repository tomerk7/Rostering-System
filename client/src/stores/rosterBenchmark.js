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
     * @returns {Promise<object|null>}
     */
    runBenchmark(month) {
      return runStoreRequest(this, {
        loadingKey: 'benchmarking',
        fallback: 'Could not run benchmark. Please try again.',
        request: async () => {
          const response = await runBenchmark({ month: Number(month) })
          this.benchmark = response.data

          return response.data
        },
      })
    },
  },
})
