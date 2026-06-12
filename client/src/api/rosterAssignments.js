import api from '@/lib/axios'

/**
 * Add a manual assignment to a roster.
 *
 * @param {number|string} rosterId
 * @param {object} payload
 * @returns {Promise<object>}
 */
export async function addAssignment(rosterId, payload) {
  const { data } = await api.post(`/api/rosters/${rosterId}/assignments`, payload)

  return data
}

/**
 * Remove a manual assignment from a roster.
 *
 * @param {number|string} rosterId
 * @param {number|string} assignmentId
 * @returns {Promise<object>}
 */
export async function removeAssignment(rosterId, assignmentId) {
  const { data } = await api.delete(`/api/rosters/${rosterId}/assignments/${assignmentId}`)

  return data
}
