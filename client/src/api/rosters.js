import api from '@/lib/axios'

export async function listRosters() {
  const { data } = await api.get('/api/rosters')

  return data
}

export async function getRoster(rosterId) {
  const { data } = await api.get(`/api/rosters/${rosterId}`)

  return data
}

async function pollGenerationStatus(generationId, maxAttempts = 120) {
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const { data } = await api.get(`/api/rosters/generations/${generationId}`)

    if (data.data?.status === 'completed') {
      return data.data
    }

    if (data.data?.status === 'failed') {
      throw new Error(data.data?.message ?? 'Roster generation failed.')
    }

    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error('Roster generation timed out. Please try again.')
}

export async function generateRosterPreview(payload) {
  const { data } = await api.post('/api/rosters/generate', payload)

  const generationId = data.data?.generation_id

  if (!generationId) {
    throw new Error('Roster generation failed.')
  }

  if (data.data?.status === 'completed') {
    return data.data
  }

  return pollGenerationStatus(generationId)
}

export async function saveGeneration(generationId, payload = {}) {
  const { data } = await api.post(`/api/rosters/generations/${generationId}/save`, payload)

  return data
}

export async function createRoster(payload) {
  const { data } = await api.post('/api/rosters', payload)

  return data
}

export async function publishRoster(rosterId) {
  const { data } = await api.post(`/api/rosters/${rosterId}/publish`)

  return data
}

export async function deleteRoster(rosterId) {
  const { data } = await api.delete(`/api/rosters/${rosterId}`)

  return data
}
