import api from '@/lib/axios'

/**
 * Fetch a paginated, filterable list of workers.
 *
 * @param {object} [params={}]
 * @returns {Promise<object>}
 */
export async function listWorkers(params = {}) {
  const { data } = await api.get('/api/workers', { params })

  return data
}

/**
 * Fetch a single worker by id.
 *
 * @param {number|string} workerId
 * @returns {Promise<object>}
 */
export async function getWorker(workerId) {
  const { data } = await api.get(`/api/workers/${workerId}`)

  return data
}

/**
 * Create a new worker.
 *
 * @param {object} payload
 * @returns {Promise<object>}
 */
export async function createWorker(payload) {
  const { data } = await api.post('/api/workers', payload)

  return data
}

/**
 * Update an existing worker.
 *
 * @param {number|string} workerId
 * @param {object} payload
 * @returns {Promise<object>}
 */
export async function updateWorker(workerId, payload) {
  const { data } = await api.put(`/api/workers/${workerId}`, payload)

  return data
}

/**
 * Delete a worker by id.
 *
 * @param {number|string} workerId
 * @returns {Promise<object>}
 */
export async function deleteWorker(workerId) {
  const { data } = await api.delete(`/api/workers/${workerId}`)

  return data
}

/**
 * Delete all workers.
 *
 * @returns {Promise<object>}
 */
export async function deleteAllWorkers() {
  const { data } = await api.delete('/api/workers')

  return data
}

/**
 * Fetch roles, shifts, and other reference data for worker forms.
 *
 * @returns {Promise<object>}
 */
export async function getReferenceData() {
  const { data } = await api.get('/api/workers/reference-data')

  return data
}

/**
 * Poll import status until completion or failure.
 *
 * @param {string} importId
 * @param {number} [maxAttempts=120]
 * @returns {Promise<object>}
 */
async function pollImportStatus(importId, maxAttempts = 120) {
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const { data } = await api.get(`/api/workers/import/${importId}`)

    if (data.data?.status === 'completed' || data.data?.total !== undefined) {
      return data
    }

    if (data.data?.status === 'failed') {
      throw new Error(data.data?.message ?? 'Import failed.')
    }

    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error('Import timed out. Please try again.')
}

/**
 * Upload a workers CSV file and wait for import completion.
 *
 * @param {File} file
 * @returns {Promise<object>}
 */
export async function importWorkers(file) {
  const form = new FormData()
  form.append('file', file)

  const { data, status } = await api.post('/api/workers/import', form)

  if (status === 202 && data.data?.import_id) {
    return pollImportStatus(data.data.import_id)
  }

  return data
}

/**
 * Download the sample CSV template for worker imports.
 *
 * @returns {Promise<Blob>}
 */
export async function downloadWorkersSample() {
  const { data } = await api.get('/api/workers/import/sample', {
    responseType: 'blob',
  })

  return data
}

/**
 * Poll export status until completion or failure.
 *
 * @param {string} exportId
 * @param {number} [maxAttempts=120]
 * @returns {Promise<object>}
 */
async function pollExportStatus(exportId, maxAttempts = 120) {
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const { data } = await api.get(`/api/workers/export/${exportId}`)

    if (data.data?.status === 'completed') {
      return data
    }

    if (data.data?.status === 'failed') {
      throw new Error(data.data?.message ?? 'Export failed.')
    }

    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error('Export timed out. Please try again.')
}

/**
 * Resolve an export id from an immediate or async export response.
 *
 * @param {object} responseData
 * @param {number} status
 * @returns {string|null}
 */
function resolveExportId(responseData, status) {
  if (status === 202 && responseData.data?.export_id) {
    return responseData.data.export_id
  }

  if (responseData.data?.status === 'completed' && responseData.data?.export_id) {
    return responseData.data.export_id
  }

  return null
}

/**
 * Export all workers and return the resulting file blob.
 *
 * @returns {Promise<Blob>}
 */
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
