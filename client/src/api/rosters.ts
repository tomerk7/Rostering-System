import api from '@/lib/axios'
import type { ApiMeta, ApiResponse } from '@/api/workers'

export type { ApiMeta, ApiResponse }

export type RosterStatus = 'draft' | 'published' | 'superseded'
export type AssignmentSource = 'auto' | 'manual'

export interface RosterCreator {
  id: number | null
  email: string | null
}

export interface RosterAssignment {
  id: number
  worker_id: number
  worker_name: string | null
  shift_id: number
  shift_code: string | null
  role_id: number | null
  role_name: string | null
  work_date: string
  source: AssignmentSource
}

export interface RosterPreviewAssignment {
  id: number | null
  worker_id: number
  worker_name: string | null
  shift_id: number
  shift_code: string | null
  role_id: number | null
  role_name: string | null
  work_date: string
  source: AssignmentSource
}

export interface CoverageShortage {
  work_date: string
  shift_id: number
  role_id: number
  required: number
  assigned: number
}

export interface HoursShortfall {
  worker_id: number
  min_hours: number
  scheduled_hours: number
}

export interface RosterReports {
  coverage_shortages: CoverageShortage[]
  hours_shortfalls: HoursShortfall[]
}

export interface RosterSummary {
  assignment_count: number
  coverage_shortage_count: number
  hours_shortfall_count: number
}

export interface Roster {
  id: number
  year: number
  month: number
  status: RosterStatus
  generated_at: string | null
  published_at: string | null
  created_by: number | null
  creator: RosterCreator | null
  assignments_count?: number
  created_at: string | null
  updated_at: string | null
  assignments?: RosterAssignment[]
  reports?: RosterReports
  summary?: RosterSummary
}

export interface RosterPreview {
  year: number
  month: number
  assignments: RosterPreviewAssignment[]
  reports: RosterReports
  summary: RosterSummary
}

export interface RosterMonthPayload {
  year: number
  month: number
}

export interface StoreRosterPayload extends RosterMonthPayload {
  publish?: boolean
}

export async function listRosters(): Promise<ApiResponse<Roster[]>> {
  const { data } = await api.get<ApiResponse<Roster[]>>('/api/rosters')

  return data
}

export async function getRoster(rosterId: number): Promise<ApiResponse<Roster>> {
  const { data } = await api.get<ApiResponse<Roster>>(`/api/rosters/${rosterId}`)

  return data
}

export async function previewRoster(payload: RosterMonthPayload): Promise<ApiResponse<RosterPreview>> {
  const { data } = await api.post<ApiResponse<RosterPreview>>('/api/rosters/preview', payload)

  return data
}

export async function createRoster(payload: StoreRosterPayload): Promise<ApiResponse<Roster>> {
  const { data } = await api.post<ApiResponse<Roster>>('/api/rosters', payload)

  return data
}

export async function publishRoster(rosterId: number): Promise<ApiResponse<Roster>> {
  const { data } = await api.post<ApiResponse<Roster>>(`/api/rosters/${rosterId}/publish`)

  return data
}

export async function deleteRoster(rosterId: number): Promise<ApiResponse<null>> {
  const { data } = await api.delete<ApiResponse<null>>(`/api/rosters/${rosterId}`)

  return data
}