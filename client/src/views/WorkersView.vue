<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useWorkersStore } from '@/stores/workers'
import type { Worker } from '@/api/workers'

const workersStore = useWorkersStore()

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

async function deleteWorker(worker: Worker) {
  if (!window.confirm(`Delete ${worker.full_name}?`)) {
    return
  }

  await workersStore.removeWorker(worker.id)
}

function formatCurrency(value: string | number | undefined): string {
  if (value === undefined) {
    return '-'
  }

  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'ILS',
    maximumFractionDigits: 2,
  }).format(Number(value))
}

function availabilitySummary(worker: Worker): string {
  const contract = worker.contract

  if (contract === null) {
    return '-'
  }

  const shiftCodes = contract.availability.shifts.map((shift) => shift.code).join(', ')

  return `${contract.availability.days.length} days / ${shiftCodes || 'no shifts'}`
}
</script>

<template>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">Workers</p>
        <h1 class="page__title">Worker Directory</h1>
        <p class="page__description">Search, filter, and manage worker profiles for scheduling.</p>
      </div>
      <RouterLink class="button button--primary" :to="{ name: 'workers.create' }">Add worker</RouterLink>
    </header>

    <section class="panel">
      <form class="toolbar" @submit.prevent="workersStore.applyFilters">
        <label class="field toolbar__search">
          <span class="field__label">Search</span>
          <input
            v-model="workersStore.search"
            class="input"
            type="search"
            placeholder="Name or Israeli ID"
          />
        </label>

        <label class="field">
          <span class="field__label">Role</span>
          <select v-model="workersStore.roleCode" class="input">
            <option v-for="role in roleOptions" :key="role.value" :value="role.value">
              {{ role.label }}
            </option>
          </select>
        </label>

        <label class="field">
          <span class="field__label">Status</span>
          <select v-model="workersStore.status" class="input">
            <option v-for="status in statusOptions" :key="status.value" :value="status.value">
              {{ status.label }}
            </option>
          </select>
        </label>

        <div class="toolbar__actions">
          <button type="submit" class="button button--primary" :disabled="workersStore.loading">
            Search
          </button>
          <button type="button" class="button" :disabled="workersStore.loading" @click="resetFilters">
            Reset
          </button>
        </div>
      </form>

      <div v-if="workersStore.error" class="alert" role="alert">
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
              <th class="table__actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="workersStore.loading">
              <td colspan="7" class="table__empty">Loading workers...</td>
            </tr>
            <tr v-else-if="workersStore.workers.length === 0">
              <td colspan="7" class="table__empty">No workers found.</td>
            </tr>
            <template v-else>
              <tr v-for="worker in workersStore.workers" :key="worker.id">
                <td>
                  <strong>{{ worker.full_name }}</strong>
                </td>
                <td>{{ worker.israeli_id }}</td>
                <td>{{ worker.role.name ?? '-' }}</td>
                <td>
                  <span class="badge" :class="worker.is_active ? 'badge--success' : 'badge--muted'">
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
                <RouterLink class="button" :to="{ name: 'workers.edit', params: { id: worker.id } }">
                  Edit
                </RouterLink>
                  <button
                    type="button"
                    class="button button--danger"
                    :disabled="workersStore.deletingId === worker.id"
                    @click="deleteWorker(worker)"
                  >
                    {{ workersStore.deletingId === worker.id ? 'Deleting...' : 'Delete' }}
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
  </main>
</template>
