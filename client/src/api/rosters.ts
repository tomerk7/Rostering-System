import api from '@/lib/axios'
import type { ApiResponse } from '@/api/workers'

export type RosterStatus = 'draft' | 'published' | 'superseded'

export type AssignmentSource = 'auto' | 'manual'

export interface RosterAssignment {
  id: number | null
  worker_id: number
  worker_name: string | null
  shift_id: number
  shift_code: string | null
  role_name: string | null
  work_date: string
  source: AssignmentSource
}

export interface CoverageShortage {
  work_date: string
  shift_id: number
  shift_code: string | null
  role_id: number
  role_name: string | null
  required: number
  assigned: number
  missing: number
}

export interface HoursShortfall {
  worker_id: number
  worker_name: string | null
  min_hours: number
  scheduled_hours: number
  shortfall_hours: number
}

export interface RosterPreviewSummary {
  assignment_count: number
  coverage_shortage_count: number
  hours_shortfall_count: number
  has_coverage_shortages: boolean
  has_hours_shortfalls: boolean
}

export interface RosterPreview {
  year: number
  month: number
  assignments: RosterAssignment[]
  coverage_shortages: CoverageShortage[]
  hours_shortfalls: HoursShortfall[]
  summary: RosterPreviewSummary
}

export interface Roster {
  id: number
  year: number
  month: number
  status: RosterStatus
  generated_at: string | null
  published_at: string | null
  assignment_count?: number
  assignments?: RosterAssignment[]
  created_at: string | null
  updated_at: string | null
}

export interface PreviewRosterPayload {
  year: number
  month: number
}

export interface RosterShowParams {
  date?: string
  shift_id?: number
}

export interface CreateAssignmentPayload {
  worker_id: number
  shift_id: number
  work_date: string
}

export async function preview(payload: PreviewRosterPayload): Promise<ApiResponse<RosterPreview>> {
  const { data } = await api.post<ApiResponse<RosterPreview>>('/api/rosters/preview', payload)

  return data
}

export async function saveDraft(payload: PreviewRosterPayload): Promise<ApiResponse<Roster>> {
  const { data } = await api.post<ApiResponse<Roster>>('/api/rosters', payload)

  return data
}

export async function list(): Promise<ApiResponse<Roster[]>> {
  const { data } = await api.get<ApiResponse<Roster[]>>('/api/rosters')

  return data
}

export async function get(rosterId: number, params: RosterShowParams = {}): Promise<ApiResponse<Roster>> {
  const { data } = await api.get<ApiResponse<Roster>>(`/api/rosters/${rosterId}`, { params })

  return data
}

export async function createAssignment(
  rosterId: number,
  payload: CreateAssignmentPayload,
): Promise<ApiResponse<RosterAssignment>> {
  const { data } = await api.post<ApiResponse<RosterAssignment>>(`/api/rosters/${rosterId}/assignments`, payload)

  return data
}

export async function deleteAssignment(rosterId: number, assignmentId: number): Promise<ApiResponse<null>> {
  const { data } = await api.delete<ApiResponse<null>>(`/api/rosters/${rosterId}/assignments/${assignmentId}`)

  return data
}

export async function publishRoster(rosterId: number): Promise<ApiResponse<Roster>> {
  const { data } = await api.post<ApiResponse<Roster>>(`/api/rosters/${rosterId}/publish`)

  return data
}
