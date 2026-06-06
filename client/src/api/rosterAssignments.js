import api from '@/lib/axios'

export async function addAssignment(rosterId, payload) {
  const { data } = await api.post(`/api/rosters/${rosterId}/assignments`, payload)

  return data
}

export async function changeAssignment(rosterId, assignmentId, payload) {
  const { data } = await api.put(
    `/api/rosters/${rosterId}/assignments/${assignmentId}`,
    payload,
  )

  return data
}

export async function removeAssignment(rosterId, assignmentId) {
  const { data } = await api.delete(`/api/rosters/${rosterId}/assignments/${assignmentId}`)

  return data
}
