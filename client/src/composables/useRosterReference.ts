import { reactive } from 'vue'
import { getReferenceData, type ReferenceData, type Worker } from '@/api/workers'
import { listWorkers } from '@/api/workers'

export function useRosterReference() {
  const state = reactive({
    reference: null as ReferenceData | null,
    workersById: new Map<number, Worker>(),
    loading: false,
    error: '',
    async load() {
      state.loading = true
      state.error = ''

      try {
        const [referenceResponse, workersResponse] = await Promise.all([
          getReferenceData(),
          listWorkers({ per_page: 500, is_active: true }),
        ])

        state.reference = referenceResponse.data
        state.workersById = new Map(workersResponse.data.map((worker) => [worker.id, worker]))
      } catch {
        state.error = 'Could not load roster reference data.'
      } finally {
        state.loading = false
      }
    },
  })

  return state
}
