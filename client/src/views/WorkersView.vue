<script setup>
import { computed, onMounted, ref } from 'vue'
import { useWorkersStore } from '@/stores/workers'
import { exportWorkers } from '@/api/workers'
import WorkerImportModal from '@/components/workers/WorkerImportModal.vue'

const workersStore = useWorkersStore()

const showImport = ref(false)
const exporting = ref(false)

function openImport() {
  showImport.value = true
}

function closeImport() {
  showImport.value = false
}

async function onWorkersImported() {
  await workersStore.fetchWorkers()
}

async function downloadExport() {
  if (workersStore.meta.total === 0) {
    return
  }

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
  workersStore.status = 'active'
  await workersStore.applyFilters()
}

async function showDirectory() {
  if (workersStore.view === 'directory') {
    return
  }

  workersStore.status = 'active'
  await workersStore.setView('directory')
}

async function showArchived() {
  if (workersStore.view === 'archived') {
    return
  }

  await workersStore.setView('archived')
}

async function deactivateWorker(worker) {
  if (!window.confirm(
    `Deactivate ${worker.full_name}? They will be removed from current and future rosters but remain in this list as inactive.`,
  )) {
    return
  }

  await workersStore.deactivateWorker(worker.israeli_id)
}

async function deleteWorker(worker) {
  if (!window.confirm(
    `Delete ${worker.full_name}? They will be archived and hidden from this list. Past roster history is kept.`,
  )) {
    return
  }

  await workersStore.deleteWorker(worker.israeli_id)
}

async function restoreWorker(worker) {
  if (!window.confirm(
    `Restore ${worker.full_name}? They will return to the directory as active.`,
  )) {
    return
  }

  await workersStore.restoreWorker(worker.israeli_id)
}

async function restoreAllWorkers() {
  const total = workersStore.meta.total

  if (total === 0) {
    return
  }

  if (!window.confirm(
    `Restore all ${total} archived workers? They will return to the directory as active.`,
  )) {
    return
  }

  await workersStore.restoreAllWorkers()
}

async function deleteAllWorkers() {
  const total = workersStore.meta.total

  if (total === 0) {
    return
  }

  if (!window.confirm(
    `Delete all ${total} workers shown? They will be archived and hidden from the directory. Past roster history is kept.`,
  )) {
    return
  }

  await workersStore.deleteAllWorkers()
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
          :disabled="exporting || workersStore.meta.total === 0 || workersStore.isArchivedView"
          :title="workersStore.meta.total === 0 ? 'Export is available only when workers exist.' : ''"
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
          v-if="!workersStore.isArchivedView"
          type="button"
          class="button button--danger"
          :disabled="workersStore.meta.total === 0 || workersStore.deletingAll"
          @click="deleteAllWorkers"
        >
          {{ workersStore.deletingAll ? 'Deleting...' : 'Delete all' }}
        </button>
        <button
          v-if="workersStore.isArchivedView"
          type="button"
          class="button button--primary"
          :disabled="workersStore.meta.total === 0 || workersStore.restoringAll"
          @click="restoreAllWorkers"
        >
          {{ workersStore.restoringAll ? 'Restoring...' : 'Restore all' }}
        </button>
        <RouterLink
          v-if="!workersStore.isArchivedView"
          class="button button--primary"
          :to="{ name: 'workers.create' }"
        >
          Add worker
        </RouterLink>
      </div>
    </header>

    <div class="view-toggle">
      <button
        type="button"
        class="button"
        :class="{ 'button--primary': !workersStore.isArchivedView }"
        @click="showDirectory"
      >
        Directory
      </button>
      <button
        type="button"
        class="button"
        :class="{ 'button--primary': workersStore.isArchivedView }"
        @click="showArchived"
      >
        Archived
      </button>
    </div>

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

        <label
          v-if="!workersStore.isArchivedView"
          class="field"
        >
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
                    v-if="workersStore.isArchivedView"
                    class="badge badge--muted"
                  >
                    Archived
                  </span>
                  <span
                    v-else
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
                  <template v-if="workersStore.isArchivedView">
                    <button
                      type="button"
                      class="button button--primary"
                      :disabled="workersStore.restoringId === worker.israeli_id"
                      @click="restoreWorker(worker)"
                    >
                      {{ workersStore.restoringId === worker.israeli_id ? 'Restoring...' : 'Restore' }}
                    </button>
                  </template>
                  <template v-else>
                    <RouterLink
                      class="button"
                      :to="{ name: 'workers.edit', params: { id: worker.israeli_id } }"
                    >
                      Edit
                    </RouterLink>
                    <button
                      v-if="worker.is_active"
                      type="button"
                      class="button button--danger"
                      :disabled="workersStore.deactivatingId === worker.israeli_id"
                      @click="deactivateWorker(worker)"
                    >
                      {{ workersStore.deactivatingId === worker.israeli_id ? 'Deactivating...' : 'Deactivate' }}
                    </button>
                    <button
                      type="button"
                      class="button button--danger"
                      :disabled="workersStore.deletingId === worker.israeli_id"
                      @click="deleteWorker(worker)"
                    >
                      {{ workersStore.deletingId === worker.israeli_id ? 'Deleting...' : 'Delete' }}
                    </button>
                  </template>
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

    <WorkerImportModal
      :show="showImport"
      @close="closeImport"
      @imported="onWorkersImported"
    />
  </main>
</template>

<style scoped>
@import '@/assets/ui/button.css';
@import '@/assets/ui/forms.css';
@import '@/assets/ui/page.css';
@import '@/assets/ui/table.css';

.toolbar {
  display: grid;
  grid-template-columns: minmax(14rem, 1fr) repeat(2, minmax(10rem, 12rem)) auto;
  gap: 1rem;
  align-items: end;
}

.toolbar__actions {
  display: flex;
  gap: 0.5rem;
}

.view-toggle {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  color: #64748b;
}

.pagination p {
  margin: 0;
}

.pagination__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

@media (max-width: 820px) {
  .toolbar {
    grid-template-columns: 1fr;
  }

  .toolbar__actions,
  .pagination,
  .pagination__actions {
    align-items: stretch;
    flex-direction: column;
  }

  .pagination {
    align-items: flex-start;
  }
}
</style>
