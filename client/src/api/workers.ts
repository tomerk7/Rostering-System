import api from '@/lib/axios'

export interface ApiMeta {
  current_page: number
  from: number | null
  last_page: number
  per_page: number
  to: number | null
  total: number
}

export interface ApiResponse<T> {
  success: boolean
  message: string
  data: T
  errors: Record<string, string[]>
  meta: ApiMeta
}

export interface WorkerRole {
  id: number | null
  code: string | null
  name: string | null
}

export interface WorkerShift {
  id: number
  code: string
  label: string
  start_time: string
  end_time: string
  duration_hours: number
}

export interface WorkerContract {
  id: number
  hourly_cost: string | number
  min_monthly_hours: number
  max_monthly_hours: number
  availability: {
    days: number[]
    shifts: WorkerShift[]
  }
}

export interface Worker {
  id: number
  full_name: string
  israeli_id: string
  is_active: boolean
  role: WorkerRole
  contract: WorkerContract | null
  created_at: string | null
  updated_at: string | null
}

export interface WorkerListParams {
  search?: string
  role_code?: string
  is_active?: boolean
  page?: number
  per_page?: number
}

export async function listWorkers(params: WorkerListParams = {}): Promise<ApiResponse<Worker[]>> {
  const { data } = await api.get<ApiResponse<Worker[]>>('/api/workers', { params })

  return data
}

export async function deleteWorker(workerId: number): Promise<ApiResponse<null>> {
  const { data } = await api.delete<ApiResponse<null>>(`/api/workers/${workerId}`)

  return data
}
