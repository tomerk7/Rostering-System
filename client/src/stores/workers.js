import { defineStore } from 'pinia'
import {
  deleteAllWorkers as deleteAllWorkersApi,
  deactivateWorker as deactivateWorkerApi,
  deleteWorker as deleteWorkerApi,
  listWorkers,
  restoreAllWorkers as restoreAllWorkersApi,
  restoreWorker as restoreWorkerApi,
} from '@/api/workers'
import { runStoreRequest } from '@/stores/storeRequest'

const emptyMeta = {
  current_page: 1,
  from: null,
  last_page: 1,
  per_page: 10,
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
    status: 'active',
    view: 'directory',
    page: 1,
    loading: false,
    error: '',
    deactivatingId: null,
    deactivatingAll: false,
    deletingAll: false,
    deletingId: null,
    restoringId: null,
    restoringAll: false,
  }),

  getters: {
    /**
     * Whether the archived workers view is active.
     *
     * @param {object} state
     * @returns {boolean}
     */
    isArchivedView(state) {
      return state.view === 'archived'
    },

    /**
     * Query params for the workers list API.
     *
     * @param {object} state
     * @returns {object}
     */
    params(state) {
      const params = {
        search: state.search || undefined,
        role_code: state.roleCode || undefined,
        page: state.page,
        per_page: emptyMeta.per_page,
      }

      if (state.view === 'archived') {
        params.trashed = 'only'
      } else {
        params.is_active = statusToBoolean(state.status)
      }

      return params
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
     * Switch between directory and archived views.
     *
     * @param {'directory'|'archived'} view
     * @returns {Promise<void>}
     */
    setView(view) {
      this.view = view
      this.page = 1
      return this.fetchWorkers()
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
     * Deactivate a worker and refresh the list.
     *
     * @param {number|string} workerId
     * @returns {Promise<void>}
     */
    deactivateWorker(workerId) {
      return runStoreRequest(this, {
        loadingKey: 'deactivatingId',
        loadingValue: workerId,
        idleValue: null,
        fallback: 'Could not deactivate worker. Please try again.',
        request: async () => {
          await deactivateWorkerApi(workerId)
          await this.fetchWorkers()
        },
      })
    },

    /**
     * Soft-delete a worker and refresh the list.
     *
     * @param {number|string} workerId
     * @returns {Promise<void>}
     */
    deleteWorker(workerId) {
      return runStoreRequest(this, {
        loadingKey: 'deletingId',
        loadingValue: workerId,
        idleValue: null,
        fallback: 'Could not delete worker. Please try again.',
        request: async () => {
          await deleteWorkerApi(workerId)
          await this.fetchWorkers()
        },
      })
    },

    /**
     * Restore a soft-deleted worker and refresh the list.
     *
     * @param {number|string} workerId
     * @returns {Promise<void>}
     */
    restoreWorker(workerId) {
      return runStoreRequest(this, {
        loadingKey: 'restoringId',
        loadingValue: workerId,
        idleValue: null,
        fallback: 'Could not restore worker. Please try again.',
        request: async () => {
          await restoreWorkerApi(workerId)
          await this.fetchWorkers()
        },
      })
    },

    /**
     * Soft-delete all non-archived workers and refresh the list.
     *
     * @returns {Promise<void>}
     */
    deleteAllWorkers() {
      return runStoreRequest(this, {
        loadingKey: 'deletingAll',
        fallback: 'Could not delete all workers. Please try again.',
        request: async () => {
          await deleteAllWorkersApi()
          this.page = 1
          await this.fetchWorkers()
        },
      })
    },

    /**
     * Restore all archived workers as active and refresh the list.
     *
     * @returns {Promise<void>}
     */
    restoreAllWorkers() {
      return runStoreRequest(this, {
        loadingKey: 'restoringAll',
        fallback: 'Could not restore all workers. Please try again.',
        request: async () => {
          await restoreAllWorkersApi()
          this.page = 1
          await this.fetchWorkers()
        },
      })
    },
  },
})
