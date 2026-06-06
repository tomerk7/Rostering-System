import api from '@/lib/axios'

export async function listRosters() {
  const { data } = await api.get('/api/rosters')

  return data
}

export async function getRoster(rosterId) {
  const { data } = await api.get(`/api/rosters/${rosterId}`)

  return data
}

export async function previewRoster(payload) {
  const { data } = await api.post('/api/rosters/preview', payload)

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
