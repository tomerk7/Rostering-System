import api from '@/lib/axios'

export interface ApiMeta {
  current_page: number
  from: number | null
  last_page: number
  per_page: number
  to: number | null
  total: number
}

export interface ApiResponse<T, TMeta = Record<string, unknown>> {
  success: boolean
  message: string
  data: T
  errors: Record<string, string[]>
  meta: TMeta
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

export interface ReferenceRole {
  id: number
  code: string
  name: string
}

export interface ReferenceData {
  roles: ReferenceRole[]
  shifts: WorkerShift[]
}

export interface WorkerPayload {
  full_name: string
  israeli_id: string
  role_id: number | null
  is_active: boolean
  contract: {
    hourly_cost: number | null
    min_monthly_hours: number | null
    max_monthly_hours: number | null
  }
  availability: {
    days: number[]
    shifts: number[]
  }
}

export async function listWorkers(params: WorkerListParams = {}): Promise<ApiResponse<Worker[], ApiMeta>> {
  const { data } = await api.get<ApiResponse<Worker[], ApiMeta>>('/api/workers', { params })

  return data
}

export async function getWorker(workerId: number): Promise<ApiResponse<Worker>> {
  const { data } = await api.get<ApiResponse<Worker>>(`/api/workers/${workerId}`)

  return data
}

export async function createWorker(payload: WorkerPayload): Promise<ApiResponse<Worker>> {
  const { data } = await api.post<ApiResponse<Worker>>('/api/workers', payload)

  return data
}

export async function updateWorker(workerId: number, payload: WorkerPayload): Promise<ApiResponse<Worker>> {
  const { data } = await api.put<ApiResponse<Worker>>(`/api/workers/${workerId}`, payload)

  return data
}

export async function deleteWorker(workerId: number): Promise<ApiResponse<null>> {
  const { data } = await api.delete<ApiResponse<null>>(`/api/workers/${workerId}`)

  return data
}

export async function getReferenceData(): Promise<ApiResponse<ReferenceData>> {
  const { data } = await api.get<ApiResponse<ReferenceData>>('/api/reference-data')

  return data
}
