import { defineStore } from 'pinia'
import {
  createRoster,
  deleteRoster,
  getRoster,
  getRosterStats,
  listRosters,
  regenerateRoster,
} from '@/api/rosters'
import { runStoreRequest } from '@/stores/storeRequest'

const emptyReports = {
  coverage_shortages: [],
  hours_shortfalls: [],
}

export const useRostersStore = defineStore('rosters', {
  state: () => ({
    rosters: [],
    roster: null,
    stats: null,
    currentYear: null,
    selectedMonth: null,
    loading: false,
    statsLoading: false,
    generating: false,
    deletingId: null,
    error: '',
    validationErrors: {},
  }),

  getters: {
    /**
     * Coverage and hours reports of the currently loaded roster.
     *
     * @param {object} state
     * @returns {{ coverage_shortages: object[], hours_shortfalls: object[] }}
     */
    reports(state) {
      return state.roster?.reports ?? emptyReports
    },

    /**
     * Summary of the currently loaded roster.
     *
     * @param {object} state
     * @returns {object|null}
     */
    summary(state) {
      return state.roster?.summary ?? null
    },
  },

  actions: {
    /**
     * Reset the stored error and validation messages.
     *
     * @returns {void}
     */
    clearErrors() {
      this.error = ''
      this.validationErrors = {}
    },

    /**
     * Load the list of rosters and the current year.
     *
     * @returns {Promise<void>}
     */
    fetchRosters() {
      return runStoreRequest(this, {
        loadingKey: 'loading',
        fallback: 'Could not load rosters. Please try again.',
        request: async () => {
          const response = await listRosters()
          this.rosters = response.data
          this.currentYear = response.meta?.current_year ?? null
        },
      })
    },

    /**
     * Load a single roster by id.
     *
     * @param {number} rosterId
     * @returns {Promise<void>}
     */
    fetchRoster(rosterId) {
      return runStoreRequest(this, {
        loadingKey: 'loading',
        fallback: 'Could not load roster. Please try again.',
        request: async () => {
          const response = await getRoster(rosterId, { include_assignments: false })
          this.roster = response.data
          const { useRosterAssignmentsStore } = await import('@/stores/rosterAssignments')
          useRosterAssignmentsStore().reset()
        },
      })
    },

    /**
     * Load per-worker statistics for a roster.
     *
     * @param {number} rosterId
     * @returns {Promise<object|null>}
     */
    fetchStats(rosterId) {
      return runStoreRequest(this, {
        loadingKey: 'statsLoading',
        fallback: 'Could not load roster stats. Please try again.',
        request: async () => {
          if (this.roster?.id === rosterId) {
            this.stats = null
          }

          const response = await getRosterStats(rosterId)

          if (this.roster?.id === rosterId) {
            this.stats = response.data
          }

          return response.data
        },
      })
    },

    /**
     * Generate a roster for the given month.
     *
     * @param {number} month
     * @param {string} [preference] distribution preference preset
     * @returns {Promise<object|null>}
     */
    create(month, preference = undefined) {
      return runStoreRequest(this, {
        loadingKey: 'generating',
        fallback: 'Could not generate roster. Please try again.',
        request: async () => {
          const roster = await createRoster({
            month: Number(month),
            ...(typeof preference === 'string' && preference !== ''
              ? { distribution_preference: preference }
              : {}),
          })
          this.roster = roster
          this.currentYear = roster.year
          this.selectedMonth = roster.month
          this.upsertRosterInList(roster)

          return roster
        },
      })
    },

    /**
     * Regenerate an existing roster by id.
     *
     * @param {number} rosterId
     * @param {boolean|string} [optimizeCostOrPreference=false] optimize_cost flag or distribution preference preset
     * @returns {Promise<object|null>}
     */
    regenerate(rosterId, optimizeCostOrPreference = false) {
      return runStoreRequest(this, {
        loadingKey: 'generating',
        fallback: 'Could not regenerate roster. Please try again.',
        request: async () => {
          const payload = typeof optimizeCostOrPreference === 'string' && optimizeCostOrPreference !== ''
            ? { distribution_preference: optimizeCostOrPreference }
            : { optimize_cost: Boolean(optimizeCostOrPreference) }

          const roster = await regenerateRoster(rosterId, payload)
          this.applyRosterUpdate(roster)

          return roster
        },
      })
    },

    /**
     * Delete a roster by id and remove it from the list.
     *
     * @param {number} rosterId
     * @returns {Promise<boolean>}
     */
    removeRoster(rosterId) {
      return runStoreRequest(this, {
        loadingKey: 'deletingId',
        loadingValue: rosterId,
        idleValue: null,
        failureValue: false,
        fallback: 'Could not delete roster. Please try again.',
        request: async () => {
          await deleteRoster(rosterId)

          if (this.roster?.id === rosterId) {
            this.roster = null
            const { useRosterAssignmentsStore } = await import('@/stores/rosterAssignments')
            useRosterAssignmentsStore().reset()
          }

          this.rosters = this.rosters.filter((roster) => roster.id !== rosterId)

          return true
        },
      })
    },

    /**
     * Insert or update a roster entry in the cached list.
     *
     * @param {object} roster
     * @returns {void}
     */
    upsertRosterInList(roster) {
      const index = this.rosters.findIndex((entry) => entry.id === roster.id)

      if (index === -1) {
        this.rosters = [
          {
            id: roster.id,
            year: roster.year,
            month: roster.month,
            generated_at: roster.generated_at,
            assignments_count: roster.assignments_count ?? roster.assignments?.length ?? 0,
          },
          ...this.rosters.filter(
            (entry) => !(entry.year === roster.year && entry.month === roster.month),
          ),
        ]
        return
      }

      this.rosters[index] = {
        ...this.rosters[index],
        ...roster,
      }
    },

    /**
     * Apply a fresh roster to the current view and the cached list.
     *
     * @param {object} roster
     * @returns {void}
     */
    applyRosterUpdate(roster) {
      if (this.roster?.id === roster.id) {
        const metadata = { ...roster }
        delete metadata.assignments
        this.roster = metadata
      }

      this.upsertRosterInList(roster)
    },
  },
})
