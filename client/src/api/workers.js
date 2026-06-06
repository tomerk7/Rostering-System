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

export async function deleteAllWorkers() {
  const { data } = await api.delete('/api/workers')

  return data
}

export async function getReferenceData() {
  const { data } = await api.get('/api/workers/reference-data')

  return data
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function pollImportStatus(importId, maxAttempts = 120) {
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const { data } = await api.get(`/api/workers/import/${importId}`)

    if (data.data?.status === 'completed' || data.data?.total !== undefined) {
      return data
    }

    if (data.data?.status === 'failed') {
      throw new Error(data.data?.message ?? 'Import failed.')
    }

    await sleep(1000)
  }

  throw new Error('Import timed out. Please try again.')
}

export async function importWorkers(file) {
  const form = new FormData()
  form.append('file', file)

  const { data, status } = await api.post('/api/workers/import', form)

  if (status === 202 && data.data?.import_id) {
    return pollImportStatus(data.data.import_id)
  }

  return data
}

async function pollExportStatus(exportId, maxAttempts = 120) {
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const { data } = await api.get(`/api/workers/export/${exportId}`)

    if (data.data?.status === 'completed') {
      return data
    }

    if (data.data?.status === 'failed') {
      throw new Error(data.data?.message ?? 'Export failed.')
    }

    await sleep(1000)
  }

  throw new Error('Export timed out. Please try again.')
}

function resolveExportId(responseData, status) {
  if (status === 202 && responseData.data?.export_id) {
    return responseData.data.export_id
  }

  if (responseData.data?.status === 'completed' && responseData.data?.export_id) {
    return responseData.data.export_id
  }

  return null
}

export async function exportWorkers() {
  const { data, status } = await api.post('/api/workers/export')

  let exportId = resolveExportId(data, status)

  if (exportId === null) {
    throw new Error('Export failed.')
  }

  if (status === 202) {
    const polled = await pollExportStatus(exportId)
    exportId = polled.data?.export_id ?? exportId
  }

  const { data: blob } = await api.get(`/api/workers/export/${exportId}/download`, {
    responseType: 'blob',
  })

  return blob
}
