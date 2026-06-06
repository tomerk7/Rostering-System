import api from '@/lib/axios'
import type { ApiResponse, Roster } from '@/api/rosters'

export interface AddAssignmentPayload {
  worker_id: number
  shift_id: number
  work_date: string
}

export interface ChangeAssignmentPayload {
  worker_id: number
}

export async function addAssignment(
  rosterId: number,
  payload: AddAssignmentPayload,
): Promise<ApiResponse<Roster>> {
  const { data } = await api.post<ApiResponse<Roster>>(`/api/rosters/${rosterId}/assignments`, payload)

  return data
}

export async function changeAssignment(
  rosterId: number,
  assignmentId: number,
  payload: ChangeAssignmentPayload,
): Promise<ApiResponse<Roster>> {
  const { data } = await api.put<ApiResponse<Roster>>(
    `/api/rosters/${rosterId}/assignments/${assignmentId}`,
    payload,
  )

  return data
}

export async function removeAssignment(rosterId: number, assignmentId: number): Promise<ApiResponse<Roster>> {
  const { data } = await api.delete<ApiResponse<Roster>>(`/api/rosters/${rosterId}/assignments/${assignmentId}`)

  return data
}