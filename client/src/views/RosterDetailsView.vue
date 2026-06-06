<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import { get, type Roster } from '@/api/rosters'
import { getReferenceData, type WorkerShift } from '@/api/workers'

const route = useRoute()

const roster = ref<Roster | null>(null)
const shifts = ref<WorkerShift[]>([])
const loading = ref(true)
const filtering = ref(false)
const error = ref('')
const filterDate = ref('')
const filterShiftId = ref<number | ''>('')

const rosterId = computed(() => Number(route.params.id))

const periodLabel = computed(() => {
  if (roster.value === null) {
    return ''
  }

  return `${String(roster.value.month).padStart(2, '0')}/${roster.value.year}`
})

const assignmentCount = computed(
  () => roster.value?.assignments?.length ?? roster.value?.assignment_count ?? 0,
)

async function loadRoster(): Promise<void> {
  const params: { date?: string; shift_id?: number } = {}

  if (filterDate.value !== '') {
    params.date = filterDate.value
  }

  if (filterShiftId.value !== '') {
    params.shift_id = Number(filterShiftId.value)
  }

  const response = await get(rosterId.value, params)
  roster.value = response.data
}

onMounted(async () => {
  loading.value = true
  error.value = ''

  try {
    const referenceResponse = await getReferenceData()
    shifts.value = referenceResponse.data.shifts
    await loadRoster()
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 404) {
      error.value = 'Roster not found.'
    } else {
      error.value = 'Could not load roster. Please try again.'
    }
  } finally {
    loading.value = false
  }
})

async function applyFilters(): Promise<void> {
  filtering.value = true
  error.value = ''

  try {
    await loadRoster()
  } catch {
    error.value = 'Could not apply filters. Please try again.'
  } finally {
    filtering.value = false
  }
}

async function resetFilters(): Promise<void> {
  filterDate.value = ''
  filterShiftId.value = ''
  await applyFilters()
}
</script>

<template>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">Rosters</p>
        <h1 class="page__title">Roster Details</h1>
        <p v-if="roster" class="page__description">
          {{ roster.status }} roster for {{ periodLabel }} with {{ assignmentCount }} assignments.
        </p>
      </div>
      <div class="page__actions">
        <RouterLink class="button" :to="{ name: 'rosters' }">All rosters</RouterLink>
        <RouterLink class="button" :to="{ name: 'rosters.generate' }">Generate another</RouterLink>
      </div>
    </header>

    <div v-if="loading" class="panel empty-state">Loading roster...</div>

    <div v-else-if="error && roster === null" class="panel">
      <div class="alert" role="alert">{{ error }}</div>
    </div>

    <template v-else-if="roster">
      <section class="panel">
        <div class="roster-details__meta">
          <p><strong>Status:</strong> {{ roster.status }}</p>
          <p><strong>Generated:</strong> {{ roster.generated_at ?? '-' }}</p>
          <p v-if="roster.published_at"><strong>Published:</strong> {{ roster.published_at }}</p>
        </div>
      </section>

      <section class="panel">
        <form class="toolbar roster-details__toolbar" @submit.prevent="applyFilters">
          <label class="field">
            <span class="field__label">Date</span>
            <input v-model="filterDate" class="input" type="date" />
          </label>

          <label class="field">
            <span class="field__label">Shift</span>
            <select v-model="filterShiftId" class="input">
              <option value="">All shifts</option>
              <option v-for="shift in shifts" :key="shift.id" :value="shift.id">
                {{ shift.code }} — {{ shift.label }}
              </option>
            </select>
          </label>

          <div class="toolbar__actions">
            <button type="submit" class="button button--primary" :disabled="filtering">
              {{ filtering ? 'Filtering...' : 'Filter' }}
            </button>
            <button type="button" class="button" :disabled="filtering" @click="resetFilters">
              Reset
            </button>
          </div>
        </form>

        <div v-if="error" class="alert" role="alert">{{ error }}</div>
      </section>

      <section class="panel">
        <h2 class="roster-details__section-title">Assignments ({{ assignmentCount }})</h2>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Shift</th>
                <th>Worker</th>
                <th>Role</th>
                <th>Source</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!roster.assignments?.length">
                <td colspan="5" class="table__empty">No assignments match the current filters.</td>
              </tr>
              <tr
                v-for="assignment in roster.assignments"
                :key="assignment.id ?? `${assignment.work_date}-${assignment.shift_id}-${assignment.worker_id}`"
              >
                <td>{{ assignment.work_date }}</td>
                <td>{{ assignment.shift_code ?? assignment.shift_id }}</td>
                <td>{{ assignment.worker_name ?? assignment.worker_id }}</td>
                <td>{{ assignment.role_name ?? '-' }}</td>
                <td>{{ assignment.source }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </main>
</template>

<style scoped>
.roster-details__meta {
  display: grid;
  gap: 0.5rem;
}

.roster-details__meta p {
  margin: 0;
}

.roster-details__section-title {
  margin: 0 0 1rem;
  font-size: 1.125rem;
}

.roster-details__toolbar {
  grid-template-columns: minmax(12rem, 14rem) minmax(12rem, 16rem) auto;
}
</style>
