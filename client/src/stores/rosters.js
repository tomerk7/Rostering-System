import { defineStore } from 'pinia'
import {
  addAssignment,
  removeAssignment,
} from '@/api/rosterAssignments'
import {
  createRoster,
  deleteRoster,
  getRoster,
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
    currentYear: null,
    selectedMonth: null,
    loading: false,
    generating: false,
    deletingId: null,
    assignmentLoading: false,
    error: '',
    validationErrors: {},
  }),

  getters: {
    /**
     * Assignments of the currently loaded roster.
     *
     * @param {object} state
     * @returns {object[]}
     */
    assignments(state) {
      return state.roster?.assignments ?? []
    },

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
          const response = await getRoster(rosterId)
          this.roster = response.data
        },
      })
    },

    /**
     * Generate a roster for the given month.
     *
     * @param {number} month
     * @returns {Promise<object|null>}
     */
    create(month) {
      return runStoreRequest(this, {
        loadingKey: 'generating',
        fallback: 'Could not generate roster. Please try again.',
        request: async () => {
          const roster = await createRoster({ month: Number(month) })
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
     * @returns {Promise<object|null>}
     */
    regenerate(rosterId) {
      return runStoreRequest(this, {
        loadingKey: 'generating',
        fallback: 'Could not regenerate roster. Please try again.',
        request: async () => {
          const roster = await regenerateRoster(rosterId)
          this.applyRosterUpdate(roster)

          return roster
        },
      })
    },

    /**
     * Add a manual assignment to a roster.
     *
     * @param {number} rosterId
     * @param {object} payload
     * @returns {Promise<object|null>}
     */
    addManualAssignment(rosterId, payload) {
      return runStoreRequest(this, {
        loadingKey: 'assignmentLoading',
        fallback: 'Could not add assignment. Please check the form and try again.',
        request: async () => {
          const response = await addAssignment(rosterId, payload)
          this.applyRosterUpdate(response.data)

          return response.data
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
          }

          this.rosters = this.rosters.filter((roster) => roster.id !== rosterId)

          return true
        },
      })
    },

    /**
     * Remove a manual assignment from a roster.
     *
     * @param {number} rosterId
     * @param {number} assignmentId
     * @returns {Promise<object|null>}
     */
    removeManualAssignment(rosterId, assignmentId) {
      return runStoreRequest(this, {
        loadingKey: 'assignmentLoading',
        fallback: 'Could not remove assignment. Please try again.',
        request: async () => {
          const response = await removeAssignment(rosterId, assignmentId)
          this.applyRosterUpdate(response.data)

          return response.data
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
            published_at: roster.published_at,
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
        this.roster = roster
      }

      this.upsertRosterInList(roster)
    },
  },
})
