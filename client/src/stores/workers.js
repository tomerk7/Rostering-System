import { defineStore } from 'pinia'
import { deleteAllWorkers, deleteWorker, listWorkers } from '@/api/workers'
import { runStoreRequest } from '@/stores/storeRequest'

const emptyMeta = {
  current_page: 1,
  from: null,
  last_page: 1,
  per_page: 10,
  to: null,
  total: 0,
}

/**
 * Map UI status filter value to API boolean.
 *
 * @param {string} status
 * @returns {boolean|undefined}
 */
function statusToBoolean(status) {
  if (status === 'active') {
    return true
  }

  if (status === 'inactive') {
    return false
  }

  return undefined
}

export const useWorkersStore = defineStore('workers', {
  state: () => ({
    workers: [],
    meta: { ...emptyMeta },
    search: '',
    roleCode: '',
    status: '',
    page: 1,
    perPage: 10,
    loading: false,
    error: '',
    validationErrors: {},
    deletingId: null,
    deletingAll: false,
  }),

  getters: {
    /**
     * Query params for the workers list API.
     *
     * @param {object} state
     * @returns {object}
     */
    params(state) {
      return {
        search: state.search || undefined,
        role_code: state.roleCode || undefined,
        is_active: statusToBoolean(state.status),
        page: state.page,
        per_page: state.perPage,
      }
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
     * Load workers using current filters and pagination.
     *
     * @returns {Promise<void>}
     */
    fetchWorkers() {
      return runStoreRequest(this, {
        loadingKey: 'loading',
        fallback: 'Could not load workers. Please try again.',
        request: async () => {
          const response = await listWorkers(this.params)
          this.workers = response.data
          this.meta = response.meta
        },
      })
    },

    /**
     * Reset to page 1 and reload workers.
     *
     * @returns {Promise<void>}
     */
    applyFilters() {
      this.page = 1
      return this.fetchWorkers()
    },

    /**
     * Change page and reload workers.
     *
     * @param {number} page
     * @returns {Promise<void>}
     */
    setPage(page) {
      this.page = page
      return this.fetchWorkers()
    },

    /**
     * Delete a worker and refresh the list.
     *
     * @param {number} workerId
     * @returns {Promise<void>}
     */
    removeWorker(workerId) {
      return runStoreRequest(this, {
        loadingKey: 'deletingId',
        loadingValue: workerId,
        idleValue: null,
        fallback: 'Could not delete worker. Please try again.',
        request: async () => {
          await deleteWorker(workerId)
          await this.fetchWorkers()
        },
      })
    },

    /**
     * Delete all workers and refresh the list.
     *
     * @returns {Promise<void>}
     */
    removeAllWorkers() {
      return runStoreRequest(this, {
        loadingKey: 'deletingAll',
        fallback: 'Could not delete all workers. Please try again.',
        request: async () => {
          await deleteAllWorkers()
          this.page = 1
          await this.fetchWorkers()
        },
      })
    },
  },
})
