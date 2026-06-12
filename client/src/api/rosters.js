import api from '@/lib/axios'

export async function listRosters() {
  const { data } = await api.get('/api/rosters')

  return data
}

export async function getRoster(rosterId) {
  const { data } = await api.get(`/api/rosters/${rosterId}`)

  return data
}

export async function generateRoster(payload) {
  const { data } = await api.post('/api/rosters/generate', payload)

  return data.data
}

export async function saveRoster(payload) {
  const { data } = await api.post('/api/rosters', payload)

  return data.data
}

export async function deleteRoster(rosterId) {
  const { data } = await api.delete(`/api/rosters/${rosterId}`)

  return data
}
