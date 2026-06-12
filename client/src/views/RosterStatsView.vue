<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { exportRoster } from '@/api/rosters'
import { resolveErrorMessage } from '@/lib/apiError'
import { useRostersStore } from '@/stores/rosters'
import StatsLeaderboards from '@/components/rosters/StatsLeaderboards.vue'
import Button from '@/components/ui/Button.vue'
import SortableTable from '@/components/ui/SortableTable.vue'
import { formatMonthYear } from '@/lib/rosterGrid'

const route = useRoute()
const router = useRouter()
const rostersStore = useRostersStore()

/**
 * Roster id from the route params.
 *
 * @returns {number}
 */
const rosterId = computed(() => Number(route.params.id))
const exporting = ref(false)
const exportError = ref('')

const currencyFormat = new Intl.NumberFormat('en-US', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

/**
 * Format a number as ILS currency.
 *
 * @param {number} value
 * @returns {string}
 */
function formatCurrency(value) {
  return `₪${currencyFormat.format(value)}`
}

/**
 * Format a percentage with two decimals.
 *
 * @param {number} value
 * @returns {string}
 */
function formatPercent(value) {
  return `${Number(value).toFixed(2)}%`
}

const columns = [
  { key: 'worker_id', label: 'Worker ID' },
  { key: 'name', label: 'Name' },
  { key: 'role', label: 'Role' },
  { key: 'min_hours', label: 'Min hours', numeric: true },
  { key: 'max_hours', label: 'Max hours', numeric: true },
  { key: 'actual_hours', label: 'Actual hours', numeric: true },
  { key: 'percent_of_min', label: '% of min', numeric: true, formatter: formatPercent },
  { key: 'percent_of_max', label: '% of max', numeric: true, formatter: formatPercent },
  { key: 'shortfall_hours', label: 'Shortfall', numeric: true },
  { key: 'total_cost', label: 'Total cost', numeric: true, formatter: formatCurrency },
]

/**
 * Formatted month and year label for the loaded roster.
 *
 * @returns {string}
 */
const periodLabel = computed(() => {
  if (!rostersStore.roster) {
    return ''
  }

  return formatMonthYear(rostersStore.roster.year, rostersStore.roster.month)
})

/**
 * Whether the roster has unresolved coverage shortages.
 *
 * @returns {boolean}
 */
const hasCoverageShortages = computed(
  () => (rostersStore.summary?.coverage_shortage_count ?? 0) > 0,
)

/**
 * Tooltip shown when export is disabled due to coverage shortages.
 *
 * @returns {string}
 */
const exportDisabledReason = computed(() => (
  hasCoverageShortages.value
    ? 'Export is available only when all shifts are fully assigned.'
    : ''
))

onMounted(async () => {
  await loadStats()
})

watch(rosterId, async () => {
  await loadStats()
})

/**
 * Load roster metadata and per-worker statistics.
 *
 * @returns {Promise<void>}
 */
async function loadStats() {
  await Promise.all([
    rostersStore.fetchRoster(rosterId.value),
    rostersStore.fetchStats(rosterId.value),
  ])
}

/**
 * Export the roster as CSV when fully assigned.
 *
 * @returns {Promise<void>}
 */
async function downloadExport() {
  const roster = rostersStore.roster

  if (!roster || hasCoverageShortages.value) {
    return
  }

  exporting.value = true
  exportError.value = ''

  try {
    const blob = await exportRoster(roster.id)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `roster-${roster.year}-${String(roster.month).padStart(2, '0')}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error) {
    exportError.value = resolveErrorMessage(error, 'Could not export roster. Please try again.')
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <main class="page page--wide">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">
          Rosters
        </p>
        <h1 class="page__title">
          <template v-if="rostersStore.roster">
            {{ periodLabel }} — Stats
          </template>
          <template v-else>
            Roster stats
          </template>
        </h1>
        <p class="page__description">
          Per-worker hours and cost for this roster. Costs use the rate recorded
          when each shift was assigned, so they never change retroactively.
        </p>
      </div>
      <div class="page__actions">
        <Button :to="{ name: 'rosters.show', params: { id: rosterId } }">
          Back to roster
        </Button>
        <Button
          v-if="rostersStore.roster"
          :disabled="exporting || hasCoverageShortages"
          :title="exportDisabledReason"
          @click="downloadExport"
        >
          {{ exporting ? 'Exporting...' : 'Export CSV' }}
        </Button>
      </div>
    </header>

    <section class="panel">
      <div
        v-if="exportError"
        class="alert"
        role="alert"
      >
        {{ exportError }}
      </div>

      <div
        v-if="rostersStore.error"
        class="alert"
        role="alert"
      >
        {{ rostersStore.error }}
      </div>

      <div
        v-if="rostersStore.loading || rostersStore.statsLoading"
        class="empty-state"
      >
        Loading stats...
      </div>

      <div
        v-else-if="!rostersStore.roster"
        class="empty-state"
      >
        Roster not found.
        <Button @click="router.push({ name: 'rosters' })">
          Back to list
        </Button>
      </div>

      <template v-else-if="rostersStore.stats">
        <div class="stats-summary">
          <div class="stats-summary__item">
            <span class="stats-summary__label">Total cost</span>
            <span class="stats-summary__value">
              {{ formatCurrency(rostersStore.stats.summary.total_cost) }}
            </span>
          </div>
          <div class="stats-summary__item">
            <span class="stats-summary__label">Total hours</span>
            <span class="stats-summary__value">{{ rostersStore.stats.summary.total_hours }}h</span>
          </div>
          <div class="stats-summary__item">
            <span class="stats-summary__label">Workers with shortfall</span>
            <span class="stats-summary__value">
              {{ rostersStore.stats.summary.workers_with_shortfall }}
            </span>
          </div>
          <div class="stats-summary__item">
            <span class="stats-summary__label">Workers assigned</span>
            <span class="stats-summary__value">{{ rostersStore.stats.rows.length }}</span>
          </div>
        </div>

        <StatsLeaderboards :leaderboards="rostersStore.stats.summary.leaderboards" />

        <SortableTable
          class="stats-table"
          :columns="columns"
          :rows="rostersStore.stats.rows"
          row-key="worker_id"
          :initial-sort="{ key: 'actual_hours', direction: 'desc' }"
          empty-text="No workers assigned to this roster."
        >
          <template #cell-shortfall_hours="{ value }">
            <span
              v-if="value > 0"
              class="badge badge--muted"
            >{{ value }}h short</span>
            <template v-else>
              —
            </template>
          </template>
        </SortableTable>
      </template>
    </section>
  </main>
</template>

<style scoped>
@import '@/assets/ui/page.css';
@import '@/assets/ui/table.css';

.stats-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.stats-summary__item {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 1rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
}

.stats-summary__label {
  font-size: 0.75rem;
  font-weight: 700;
  color: #475569;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.stats-summary__value {
  font-size: 1.25rem;
  font-weight: 700;
  color: #0f172a;
}

.stats-table {
  margin-top: 1rem;
}
</style>
