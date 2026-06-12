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
 * @param {object} [params]
 * @returns {Promise<object>}
 */
export async function getRoster(rosterId, params = {}) {
  const { data } = await api.get(`/api/rosters/${rosterId}`, { params })

  return data
}

/**
 * Poll roster status until completion.
 *
 * @param {number|string} rosterId
 * @param {number} [maxAttempts=1800]
 * @returns {Promise<object>}
 */
async function pollRosterStatus(rosterId, maxAttempts = 1800) {
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const { data } = await api.get(`/api/rosters/${rosterId}`)

    if (data.data?.status === 'ready') {
      return data.data
    }

    if (data.data?.status === 'failed') {
      throw new Error('Roster generation failed.')
    }

    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error('Roster generation timed out. Please try again.')
}

/**
 * Resolve an immediate or queued roster generation response.
 *
 * @param {object} response
 * @returns {Promise<object>}
 */
async function resolveGenerationResponse(response) {
  if (response.status === 202 && response.data.data?.id) {
    return pollRosterStatus(response.data.data.id)
  }

  return response.data.data
}

/**
 * Generate a new roster for the given month.
 *
 * @param {object} payload
 * @returns {Promise<object>}
 */
export async function createRoster(payload) {
  const response = await api.post('/api/rosters', payload)

  return resolveGenerationResponse(response)
}

/**
 * Regenerate an existing roster by id.
 *
 * @param {number|string} rosterId
 * @returns {Promise<object>}
 */
export async function regenerateRoster(rosterId) {
  const response = await api.post(`/api/rosters/${Number(rosterId)}/regenerate`)

  return resolveGenerationResponse(response)
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
