import api from '@/lib/axios'

export async function listWorkers(params = {}) {
  const { data } = await api.get('/api/workers', { params })

  return data
}

export async function getWorker(workerId) {
  const { data } = await api.get(`/api/workers/${workerId}`)

  return data
}

export async function createWorker(payload) {
  const { data } = await api.post('/api/workers', payload)

  return data
}

export async function updateWorker(workerId, payload) {
  const { data } = await api.put(`/api/workers/${workerId}`, payload)

  return data
}

export async function deleteWorker(workerId) {
  const { data } = await api.delete(`/api/workers/${workerId}`)

  return data
}

export async function getReferenceData() {
  const { data } = await api.get('/api/workers/reference-data')

  return data
}

export async function importWorkers(file) {
  const form = new FormData()
  form.append('file', file)

  const { data } = await api.post('/api/workers/import', form)

  return data
}

export async function exportWorkers() {
  const { data } = await api.get('/api/workers/export', { responseType: 'blob' })

  return data
}
