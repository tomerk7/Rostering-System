<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { isAxiosError } from 'axios'
import {
  preview,
  saveDraft,
  type RosterPreview,
} from '@/api/rosters'

const router = useRouter()

const currentYear = new Date().getFullYear()

const year = ref(currentYear)
const month = ref(new Date().getMonth() + 1)
const generating = ref(false)
const saving = ref(false)
const error = ref('')
const rosterPreview = ref<RosterPreview | null>(null)

const monthOptions = [
  { value: 1, label: 'January' },
  { value: 2, label: 'February' },
  { value: 3, label: 'March' },
  { value: 4, label: 'April' },
  { value: 5, label: 'May' },
  { value: 6, label: 'June' },
  { value: 7, label: 'July' },
  { value: 8, label: 'August' },
  { value: 9, label: 'September' },
  { value: 10, label: 'October' },
  { value: 11, label: 'November' },
  { value: 12, label: 'December' },
]

const yearOptions = Array.from({ length: 5 }, (_, index) => currentYear - 1 + index)

const periodLabel = computed(() => {
  if (rosterPreview.value === null) {
    return ''
  }

  const monthName = monthOptions.find((option) => option.value === rosterPreview.value?.month)?.label

  return `${monthName} ${rosterPreview.value.year}`
})

const canSaveDraft = computed(() => rosterPreview.value !== null && !generating.value && !saving.value)

const hasCoverageShortages = computed(
  () => (rosterPreview.value?.coverage_shortages.length ?? 0) > 0,
)

const hasHoursShortfalls = computed(
  () => (rosterPreview.value?.hours_shortfalls.length ?? 0) > 0,
)

const hasAlerts = computed(() => hasCoverageShortages.value || hasHoursShortfalls.value)

function resolveErrorMessage(err: unknown, fallback: string): string {
  if (isAxiosError(err) && err.response?.data?.message) {
    return String(err.response.data.message)
  }

  return fallback
}

async function onGenerate() {
  generating.value = true
  error.value = ''
  rosterPreview.value = null

  try {
    const response = await preview({ year: year.value, month: month.value })
    rosterPreview.value = response.data
  } catch (err) {
    error.value = resolveErrorMessage(err, 'Could not generate roster preview. Please try again.')
  } finally {
    generating.value = false
  }
}

async function onSaveDraft() {
  if (rosterPreview.value === null) {
    return
  }

  saving.value = true
  error.value = ''

  try {
    const response = await saveDraft({
      year: rosterPreview.value.year,
      month: rosterPreview.value.month,
    })

    await router.push({ name: 'rosters.show', params: { id: response.data.id } })
  } catch (err) {
    error.value = resolveErrorMessage(err, 'Could not save roster draft. Please try again.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">Rosters</p>
        <h1 class="page__title">Generate Roster</h1>
        <p class="page__description">
          Pick a month, run the engine, review coverage and hours alerts, then save as a draft.
        </p>
      </div>
      <div class="page__actions">
        <button
          type="button"
          class="button button--primary"
          :disabled="!canSaveDraft"
          @click="onSaveDraft"
        >
          {{ saving ? 'Saving...' : 'Save Draft' }}
        </button>
      </div>
    </header>

    <section class="panel">
      <form class="toolbar roster-generate__toolbar" @submit.prevent="onGenerate">
        <label class="field">
          <span class="field__label">Year</span>
          <select v-model.number="year" class="input">
            <option v-for="option in yearOptions" :key="option" :value="option">
              {{ option }}
            </option>
          </select>
        </label>

        <label class="field">
          <span class="field__label">Month</span>
          <select v-model.number="month" class="input">
            <option v-for="option in monthOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>

        <div class="toolbar__actions">
          <button type="submit" class="button button--primary" :disabled="generating || saving">
            {{ generating ? 'Generating...' : 'Generate' }}
          </button>
        </div>
      </form>

      <div v-if="error" class="alert" role="alert">
        {{ error }}
      </div>
    </section>

    <template v-if="rosterPreview">
      <section class="panel roster-generate__alerts">
        <h2 class="roster-generate__section-title">Alerts</h2>

        <div
          v-if="hasAlerts"
          class="alert"
          role="alert"
        >
          Review the alerts below before saving this roster.
        </div>

        <p
          v-else
          class="roster-generate__alerts-clear"
        >
          No coverage or hours alerts for this month.
        </p>

        <details
          class="roster-generate__disclosure"
          :class="{ 'roster-generate__disclosure--alert': hasCoverageShortages }"
          :open="hasCoverageShortages"
        >
          <summary class="roster-generate__disclosure-summary">
            <span class="roster-generate__disclosure-label">Coverage Shortages ({{ rosterPreview.coverage_shortages.length }})</span>
            <span
              v-if="hasCoverageShortages"
              class="badge"
            >Alert</span>
          </summary>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Shift</th>
                  <th>Role</th>
                  <th>Required</th>
                  <th>Assigned</th>
                  <th>Missing</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="rosterPreview.coverage_shortages.length === 0">
                  <td colspan="6" class="table__empty">No coverage shortages.</td>
                </tr>
                <tr
                  v-for="(shortage, index) in rosterPreview.coverage_shortages"
                  :key="`${shortage.work_date}-${shortage.shift_id}-${shortage.role_id}-${index}`"
                >
                  <td>{{ shortage.work_date }}</td>
                  <td>{{ shortage.shift_code ?? shortage.shift_id }}</td>
                  <td>{{ shortage.role_name ?? shortage.role_id }}</td>
                  <td>{{ shortage.required }}</td>
                  <td>{{ shortage.assigned }}</td>
                  <td>{{ shortage.missing }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>

        <details
          class="roster-generate__disclosure"
          :class="{ 'roster-generate__disclosure--alert': hasHoursShortfalls }"
          :open="hasHoursShortfalls"
        >
          <summary class="roster-generate__disclosure-summary">
            <span class="roster-generate__disclosure-label">Hours Shortfalls ({{ rosterPreview.hours_shortfalls.length }})</span>
            <span
              v-if="hasHoursShortfalls"
              class="badge"
            >Alert</span>
          </summary>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Worker</th>
                  <th>Min Hours</th>
                  <th>Scheduled</th>
                  <th>Shortfall</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="rosterPreview.hours_shortfalls.length === 0">
                  <td colspan="4" class="table__empty">No hours shortfalls.</td>
                </tr>
                <tr
                  v-for="(shortfall, index) in rosterPreview.hours_shortfalls"
                  :key="`${shortfall.worker_id}-${index}`"
                >
                  <td>{{ shortfall.worker_name ?? shortfall.worker_id }}</td>
                  <td>{{ shortfall.min_hours }}</td>
                  <td>{{ shortfall.scheduled_hours }}</td>
                  <td>{{ shortfall.shortfall_hours }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </section>

      <section class="panel">
        <h2 class="roster-generate__section-title">Summary — {{ periodLabel }}</h2>
        <div class="roster-generate__summary">
          <p><strong>Assignments:</strong> {{ rosterPreview.summary.assignment_count }}</p>
          <p>
            <strong>Coverage shortages:</strong>
            {{ rosterPreview.summary.coverage_shortage_count }}
            <span v-if="hasCoverageShortages" class="badge">Alert</span>
          </p>
          <p>
            <strong>Hours shortfalls:</strong>
            {{ rosterPreview.summary.hours_shortfall_count }}
            <span v-if="hasHoursShortfalls" class="badge">Alert</span>
          </p>
        </div>
      </section>

      <section class="panel">
        <h2 class="roster-generate__section-title">Assignments ({{ rosterPreview.assignments.length }})</h2>
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
              <tr v-if="rosterPreview.assignments.length === 0">
                <td colspan="5" class="table__empty">No assignments generated.</td>
              </tr>
              <tr
                v-for="(assignment, index) in rosterPreview.assignments"
                :key="`${assignment.work_date}-${assignment.shift_id}-${assignment.worker_id}-${index}`"
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

    <section v-else-if="!generating" class="panel empty-state">
      Choose a year and month, then click Generate to preview the roster.
    </section>
  </main>
</template>

<style scoped>
.roster-generate__toolbar {
  grid-template-columns: minmax(8rem, 10rem) minmax(12rem, 16rem) auto;
}

.roster-generate__section-title {
  margin: 0;
  font-size: 1.125rem;
}

.roster-generate__summary {
  display: grid;
  gap: 0.5rem;
  margin: 0;
}

.roster-generate__summary p {
  margin: 0;
}

.roster-generate__alerts {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.roster-generate__alerts-clear {
  margin: 0;
  color: #64748b;
}

.roster-generate__disclosure {
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  overflow: hidden;
}

.roster-generate__disclosure--alert {
  border-color: #fecaca;
}

.roster-generate__disclosure-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.875rem 1rem;
  font-weight: 600;
  color: #334155;
  cursor: pointer;
  list-style: none;
  background: #f8fafc;
}

.roster-generate__disclosure--alert .roster-generate__disclosure-summary {
  color: #991b1b;
  background: #fef2f2;
}

.roster-generate__disclosure-summary::-webkit-details-marker {
  display: none;
}

.roster-generate__disclosure-label {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.roster-generate__disclosure-summary::before {
  flex-shrink: 0;
  margin-right: 0.5rem;
  font-size: 0.75rem;
  color: #64748b;
  content: '▸';
}

.roster-generate__disclosure[open] .roster-generate__disclosure-summary::before {
  content: '▾';
}

.roster-generate__disclosure .table-wrap {
  border: none;
  border-radius: 0;
}
</style>
