import { defineStore } from 'pinia'
import {
  addAssignment,
  listAssignments,
  removeAssignment,
} from '@/api/rosterAssignments'
import { runStoreRequest } from '@/stores/storeRequest'

export const useRosterAssignmentsStore = defineStore('rosterAssignments', {
  state: () => ({
    assignments: [],
    assignedHoursByWorker: {},
    assignmentLoading: false,
    assignmentsLoading: false,
    error: '',
    validationErrors: {},
  }),

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
     * Clear cached assignment data.
     *
     * @returns {void}
     */
    reset() {
      this.assignments = []
      this.assignedHoursByWorker = {}
    },

    /**
     * Load assignments for an inclusive date range.
     *
     * @param {number} rosterId
     * @param {{ fromDate: string, toDate: string }} range
     * @returns {Promise<object|null>}
     */
    fetchAssignments(rosterId, range) {
      return runStoreRequest(this, {
        loadingKey: 'assignmentsLoading',
        fallback: 'Could not load assignments for this week. Please try again.',
        request: async () => {
          this.assignments = []
          this.assignedHoursByWorker = {}

          const response = await listAssignments(rosterId, range)
          this.assignments = response.data
          this.assignedHoursByWorker = response.meta?.assigned_hours_by_worker ?? {}

          return response
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
          const { useRostersStore } = await import('@/stores/rosters')
          useRostersStore().applyRosterUpdate(response.data)

          return response.data
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
          const { useRostersStore } = await import('@/stores/rosters')
          useRostersStore().applyRosterUpdate(response.data)

          return response.data
        },
      })
    },
  },
})
