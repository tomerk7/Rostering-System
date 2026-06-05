import { defineStore } from 'pinia'
import { deleteWorker, listWorkers, type ApiMeta, type Worker, type WorkerListParams } from '@/api/workers'

type StatusFilter = '' | 'active' | 'inactive'

const emptyMeta: ApiMeta = {
  current_page: 1,
  from: null,
  last_page: 1,
  per_page: 10,
  to: null,
  total: 0,
}

function statusToBoolean(status: StatusFilter): boolean | undefined {
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
    workers: [] as Worker[],
    meta: { ...emptyMeta },
    search: '',
    roleCode: '',
    status: '' as StatusFilter,
    page: 1,
    perPage: 10,
    loading: false,
    error: '',
    deletingId: null as number | null,
  }),

  getters: {
    params(state): WorkerListParams {
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
    async fetchWorkers() {
      this.loading = true
      this.error = ''

      try {
        const response = await listWorkers(this.params)
        this.workers = response.data
        this.meta = response.meta
      } catch {
        this.error = 'Could not load workers. Please try again.'
      } finally {
        this.loading = false
      }
    },

    async applyFilters() {
      this.page = 1
      await this.fetchWorkers()
    },

    async setPage(page: number) {
      this.page = page
      await this.fetchWorkers()
    },

    async removeWorker(workerId: number) {
      this.deletingId = workerId
      this.error = ''

      try {
        await deleteWorker(workerId)
        await this.fetchWorkers()
      } catch {
        this.error = 'Could not delete worker. Please try again.'
      } finally {
        this.deletingId = null
      }
    },
  },
})
