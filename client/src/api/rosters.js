import api from '@/lib/axios'

/**
 * Fetch all rosters for the current year.
 *
 * @returns {Promise<object>}
 */
export async function listRosters() {
  const { data } = await api.get('/api/rosters')

  return data
}

/**
 * Fetch a single roster by id.
 *
 * @param {number|string} rosterId
 * @returns {Promise<object>}
 */
export async function getRoster(rosterId) {
  const { data } = await api.get(`/api/rosters/${rosterId}`)

  return data
}

/**
 * Generate a new roster for the given month.
 *
 * @param {object} payload
 * @returns {Promise<object>}
 */
export async function createRoster(payload) {
  const { data } = await api.post('/api/rosters', payload)

  return data.data
}

/**
 * Regenerate an existing roster by id.
 *
 * @param {number|string} rosterId
 * @returns {Promise<object>}
 */
export async function regenerateRoster(rosterId) {
  const { data } = await api.post(`/api/rosters/${Number(rosterId)}/regenerate`)

  return data.data
}

/**
 * Delete a roster by id.
 *
 * @param {number|string} rosterId
 * @returns {Promise<object>}
 */
export async function deleteRoster(rosterId) {
  const { data } = await api.delete(`/api/rosters/${rosterId}`)

  return data
}
