<script setup>
import { computed, onMounted, ref } from 'vue'
import { useWorkersStore } from '@/stores/workers'
import { downloadWorkersSample, exportWorkers, importWorkers } from '@/api/workers'
import { isAxiosError } from 'axios'

const workersStore = useWorkersStore()

const showImport = ref(false)
const importFile = ref(null)
const importing = ref(false)
const importError = ref('')
const importSummary = ref(null)
const importErrors = ref([])
const exporting = ref(false)
const downloadingSample = ref(false)

function openImport() {
  showImport.value = true
  importFile.value = null
  importError.value = ''
  importSummary.value = null
  importErrors.value = []
}

function closeImport() {
  showImport.value = false
}

function onFileChange(event) {
  importFile.value = event.target.files?.[0] ?? null
}

async function submitImport() {
  if (importFile.value === null) {
    importError.value = 'Please choose a CSV file to import.'
    return
  }

  importing.value = true
  importError.value = ''
  importSummary.value = null
  importErrors.value = []

  try {
    const response = await importWorkers(importFile.value)
    importSummary.value = response.data
    importErrors.value = response.errors ?? []
    await workersStore.fetchWorkers()
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 422) {
      const fileErrors = error.response.data?.errors?.file
      importError.value = fileErrors?.[0] ?? 'The uploaded file is invalid.'
    } else {
      importError.value = 'Import failed. Please try again.'
    }
  } finally {
    importing.value = false
  }
}

async function downloadExport() {
  exporting.value = true

  try {
    const blob = await exportWorkers()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `workers-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch {
    workersStore.error = 'Could not export workers. Please try again.'
  } finally {
    exporting.value = false
  }
}

async function downloadSample() {
  downloadingSample.value = true
  importError.value = ''

  try {
    const blob = await downloadWorkersSample()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'workers-sample.csv'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch {
    importError.value = 'Could not download sample CSV. Please try again.'
  } finally {
    downloadingSample.value = false
  }
}

const roleOptions = [
  { value: '', label: 'All roles' },
  { value: 'general_guard', label: 'General Guard' },
  { value: 'supervisor', label: 'Supervisor' },
  { value: 'screener', label: 'Screener' },
]

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
]

const canGoBack = computed(() => workersStore.meta.current_page > 1)
const canGoForward = computed(() => workersStore.meta.current_page < workersStore.meta.last_page)

onMounted(async () => {
  await workersStore.fetchWorkers()
})

async function resetFilters() {
  workersStore.search = ''
  workersStore.roleCode = ''
  workersStore.status = ''
  await workersStore.applyFilters()
}

async function deleteWorker(worker) {
  if (!window.confirm(`Delete ${worker.full_name}?`)) {
    return
  }

  await workersStore.removeWorker(worker.israeli_id)
}

async function deleteAllWorkers() {
  const total = workersStore.meta.total

  if (total === 0) {
    return
  }

  if (!window.confirm(`Delete all ${total} workers? This cannot be undone.`)) {
    return
  }

  await workersStore.removeAllWorkers()
}

function formatCurrency(value) {
  if (value === undefined) {
    return '-'
  }

  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'ILS',
    maximumFractionDigits: 2,
  }).format(Number(value))
}

function availabilitySummary(worker) {
  const contract = worker.contract

  if (contract === null) {
    return '-'
  }

  const slots = contract.availability ?? []
  const dayCount = new Set(slots.map((slot) => slot.day_of_week)).size
  const shiftCount = new Set(slots.map((slot) => slot.shift.code)).size

  return `${dayCount} days / ${shiftCount} shifts (${slots.length} slots)`
}
</script>

<template>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">
          Workers
        </p>
        <h1 class="page__title">
          Worker Directory
        </h1>
        <p class="page__description">
          Search, filter, and manage worker profiles for scheduling.
        </p>
      </div>
      <div class="page__actions">
        <button
          type="button"
          class="button"
          :disabled="exporting"
          @click="downloadExport"
        >
          {{ exporting ? 'Exporting...' : 'Export CSV' }}
        </button>
        <button
          type="button"
          class="button"
          @click="openImport"
        >
          Import CSV
        </button>
        <button
          type="button"
          class="button button--danger"
          :disabled="workersStore.meta.total === 0 || workersStore.deletingAll"
          @click="deleteAllWorkers"
        >
          {{ workersStore.deletingAll ? 'Deleting...' : 'Delete all' }}
        </button>
        <RouterLink
          class="button button--primary"
          :to="{ name: 'workers.create' }"
        >
          Add worker
        </RouterLink>
      </div>
    </header>

    <section class="panel">
      <form
        class="toolbar"
        @submit.prevent="workersStore.applyFilters"
      >
        <label class="field toolbar__search">
          <span class="field__label">Search</span>
          <input
            v-model="workersStore.search"
            class="input"
            type="search"
            placeholder="Name or Israeli ID"
          >
        </label>

        <label class="field">
          <span class="field__label">Role</span>
          <select
            v-model="workersStore.roleCode"
            class="input"
          >
            <option
              v-for="role in roleOptions"
              :key="role.value"
              :value="role.value"
            >
              {{ role.label }}
            </option>
          </select>
        </label>

        <label class="field">
          <span class="field__label">Status</span>
          <select
            v-model="workersStore.status"
            class="input"
          >
            <option
              v-for="status in statusOptions"
              :key="status.value"
              :value="status.value"
            >
              {{ status.label }}
            </option>
          </select>
        </label>

        <div class="toolbar__actions">
          <button
            type="submit"
            class="button button--primary"
            :disabled="workersStore.loading"
          >
            Search
          </button>
          <button
            type="button"
            class="button"
            :disabled="workersStore.loading"
            @click="resetFilters"
          >
            Reset
          </button>
        </div>
      </form>

      <div
        v-if="workersStore.error"
        class="alert"
        role="alert"
      >
        {{ workersStore.error }}
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Israeli ID</th>
              <th>Role</th>
              <th>Status</th>
              <th>Contract</th>
              <th>Availability</th>
              <th class="table__actions">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="workersStore.loading">
              <td
                colspan="7"
                class="table__empty"
              >
                Loading workers...
              </td>
            </tr>
            <tr v-else-if="workersStore.workers.length === 0">
              <td
                colspan="7"
                class="table__empty"
              >
                No workers found.
              </td>
            </tr>
            <template v-else>
              <tr
                v-for="worker in workersStore.workers"
                :key="worker.israeli_id"
              >
                <td>
                  <strong>{{ worker.full_name }}</strong>
                </td>
                <td>{{ worker.israeli_id }}</td>
                <td>{{ worker.role.name ?? '-' }}</td>
                <td>
                  <span
                    class="badge"
                    :class="worker.is_active ? 'badge--success' : 'badge--muted'"
                  >
                    {{ worker.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td>
                  <span v-if="worker.contract">
                    {{ formatCurrency(worker.contract.hourly_cost) }} /
                    {{ worker.contract.min_monthly_hours }}-{{ worker.contract.max_monthly_hours }}h
                  </span>
                  <span v-else>-</span>
                </td>
                <td>{{ availabilitySummary(worker) }}</td>
                <td class="table__actions">
                  <RouterLink
                    class="button"
                    :to="{ name: 'workers.edit', params: { id: worker.israeli_id } }"
                  >
                    Edit
                  </RouterLink>
                  <button
                    type="button"
                    class="button button--danger"
                    :disabled="workersStore.deletingId === worker.israeli_id"
                    @click="deleteWorker(worker)"
                  >
                    {{ workersStore.deletingId === worker.israeli_id ? 'Deleting...' : 'Delete' }}
                  </button>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <footer class="pagination">
        <p>
          Showing {{ workersStore.meta.from ?? 0 }}-{{ workersStore.meta.to ?? 0 }} of
          {{ workersStore.meta.total }}
        </p>
        <div class="pagination__actions">
          <button
            type="button"
            class="button"
            :disabled="!canGoBack || workersStore.loading"
            @click="workersStore.setPage(workersStore.meta.current_page - 1)"
          >
            Previous
          </button>
          <span>Page {{ workersStore.meta.current_page }} of {{ workersStore.meta.last_page }}</span>
          <button
            type="button"
            class="button"
            :disabled="!canGoForward || workersStore.loading"
            @click="workersStore.setPage(workersStore.meta.current_page + 1)"
          >
            Next
          </button>
        </div>
      </footer>
    </section>

    <div
      v-if="showImport"
      class="modal"
      role="dialog"
      aria-modal="true"
      @click.self="closeImport"
    >
      <div class="modal__card">
        <header class="modal__header">
          <h2 class="modal__title">
            Import workers from CSV
          </h2>
          <button
            type="button"
            class="button"
            @click="closeImport"
          >
            Close
          </button>
        </header>

        <p class="modal__hint">
          One row per worker. Columns are read by position: full_name, israeli_id, role, status,
          hourly_cost, min_monthly_hours, max_monthly_hours, availability (e.g. Sun:C;Mon:A|B).
        </p>

        <label class="field">
          <span class="field__label">CSV file</span>
          <input
            class="input"
            type="file"
            accept=".csv,text/csv"
            @change="onFileChange"
          >
        </label>

        <div>
          <button
            type="button"
            class="button"
            :disabled="downloadingSample || importing"
            @click="downloadSample"
          >
            {{ downloadingSample ? 'Downloading...' : 'Download sample' }}
          </button>
        </div>

        <div
          v-if="importError"
          class="alert"
          role="alert"
        >
          {{ importError }}
        </div>

        <div
          v-if="importSummary"
          class="alert alert--success"
          role="status"
        >
          Imported {{ importSummary.imported }} of {{ importSummary.total }} rows
          ({{ importSummary.created }} created, {{ importSummary.updated }} updated,
          {{ importSummary.skipped }} skipped).
        </div>

        <div
          v-if="importErrors.length"
          class="import-errors"
        >
          <h3 class="import-errors__title">
            Row errors ({{ importErrors.length }})
          </h3>
          <div class="table-wrap">
            <table class="table import-errors__table">
              <thead>
                <tr>
                  <th>Line</th>
                  <th>Field</th>
                  <th>Message</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(rowError, index) in importErrors"
                  :key="`${rowError.line}-${index}`"
                >
                  <td>{{ rowError.line }}</td>
                  <td>{{ rowError.field }}</td>
                  <td>{{ rowError.message }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <footer class="modal__footer">
          <button
            type="button"
            class="button"
            :disabled="importing"
            @click="closeImport"
          >
            Done
          </button>
          <button
            type="button"
            class="button button--primary"
            :disabled="importing || importFile === null"
            @click="submitImport"
          >
            {{ importing ? 'Importing...' : 'Import' }}
          </button>
        </footer>
      </div>
    </div>
  </main>
</template>
