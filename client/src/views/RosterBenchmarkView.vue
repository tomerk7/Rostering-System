<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRosterBenchmarkStore } from '@/stores/rosterBenchmark'
import StatsLeaderboards from '@/components/rosters/StatsLeaderboards.vue'
import Button from '@/components/ui/Button.vue'
import Field from '@/components/ui/Field.vue'
import Select from '@/components/ui/Select.vue'
import SortableTable from '@/components/ui/SortableTable.vue'
import Table from '@/components/ui/Table.vue'

const benchmarkStore = useRosterBenchmarkStore()

const selectedMonth = ref(null)
const showFullTables = ref(false)

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
  value: index + 1,
  label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, index, 1)),
}))

const canRun = computed(() => selectedMonth.value != null)

const currencyFormat = new Intl.NumberFormat('en-US', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

function formatCurrency(value) {
  return `₪${currencyFormat.format(value)}`
}

function formatPercent(value) {
  return `${Number(value).toFixed(2)}%`
}

function formatDeltaCost(value) {
  if (value === 0) return '—'
  const abs = `₪${currencyFormat.format(Math.abs(value))}`
  return value > 0 ? `+${abs}` : `-${abs}`
}

function formatDeltaHours(value) {
  if (value === 0) return '—'
  return value > 0 ? `+${value}h` : `${value}h`
}

const metricRows = computed(() => {
  const benchmark = benchmarkStore.benchmark

  if (!benchmark) {
    return []
  }

  const { plain, optimized } = benchmark

  return [
    ['Assignments', plain.assignments, optimized.assignments],
    ['Coverage shortages', plain.coverage_shortages, optimized.coverage_shortages],
    ['Total cost', currencyFormat.format(plain.total_cost), currencyFormat.format(optimized.total_cost)],
    ['Min-hours shortfall (workers)', plain.min_hours_shortfall_workers, optimized.min_hours_shortfall_workers],
    ['Min-hours shortfall (hours)', plain.min_hours_shortfall_hours, optimized.min_hours_shortfall_hours],
    ['Max-hours violations (workers)', plain.max_hours_violations, optimized.max_hours_violations],
    ['Hours std deviation', plain.hours_std_dev.toFixed(2), optimized.hours_std_dev.toFixed(2)],
    ['Generation time', `${plain.generation_seconds.toFixed(2)}s`, `${optimized.generation_seconds.toFixed(2)}s`],
  ]
})

const savedSummary = computed(() => {
  const benchmark = benchmarkStore.benchmark

  if (!benchmark) {
    return ''
  }

  return `Saved: ${currencyFormat.format(benchmark.saved_amount)} (${benchmark.saved_percent.toFixed(2)}%)`
})

const workerDeltas = computed(() => benchmarkStore.benchmark?.worker_stats?.deltas ?? [])

const deltaColumns = [
  { key: 'name', label: 'Worker' },
  { key: 'plain_hours', label: 'Plain hours', numeric: true },
  { key: 'optimized_hours', label: 'Opt. hours', numeric: true },
  { key: 'hours_delta', label: 'Δ hours', numeric: true, sortable: false },
  { key: 'plain_cost', label: 'Plain cost', numeric: true, formatter: formatCurrency },
  { key: 'optimized_cost', label: 'Opt. cost', numeric: true, formatter: formatCurrency },
  { key: 'cost_delta', label: 'Δ cost', numeric: true, sortable: false },
  { key: 'shortfall_change', label: 'Shortfall', sortable: false },
]

const workerColumns = [
  { key: 'worker_id', label: 'Worker ID' },
  { key: 'name', label: 'Name' },
  { key: 'min_hours', label: 'Min hours', numeric: true },
  { key: 'max_hours', label: 'Max hours', numeric: true },
  { key: 'actual_hours', label: 'Actual hours', numeric: true },
  { key: 'percent_of_min', label: '% of min', numeric: true, formatter: formatPercent },
  { key: 'percent_of_max', label: '% of max', numeric: true, formatter: formatPercent },
  { key: 'shortfall_hours', label: 'Shortfall', numeric: true },
  { key: 'total_cost', label: 'Total cost', numeric: true, formatter: formatCurrency },
]

onMounted(() => {
  benchmarkStore.clearErrors()
  benchmarkStore.reset()
})

function onPeriodChange() {
  benchmarkStore.clearErrors()
}

async function runBenchmark() {
  if (!canRun.value) {
    return
  }

  showFullTables.value = false
  await benchmarkStore.runBenchmark(selectedMonth.value)
}
</script>

<template>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">
          Rosters
        </p>
        <h1 class="page__title">
          Benchmark
        </h1>
        <p class="page__description">
          Compare plain greedy roster generation against the cost-optimized run
          for a month in the current year. Nothing is saved.
        </p>
      </div>
      <div class="page__actions">
        <Button :to="{ name: 'rosters' }">
          Back to list
        </Button>
      </div>
    </header>

    <section class="panel">
      <form
        class="toolbar"
        @submit.prevent="runBenchmark"
      >
        <Field label="Month">
          <Select
            v-model="selectedMonth"
            required
            @change="onPeriodChange"
          >
            <option
              :value="null"
              disabled
            >
              Select month
            </option>
            <option
              v-for="month in monthOptions"
              :key="month.value"
              :value="month.value"
            >
              {{ month.label }}
            </option>
          </Select>
        </Field>

        <div class="toolbar__actions">
          <Button
            type="submit"
            variant="primary"
            :disabled="benchmarkStore.benchmarking || !canRun"
          >
            {{ benchmarkStore.benchmarking ? 'Running benchmark...' : 'Run benchmark' }}
          </Button>
        </div>
      </form>

      <p
        v-if="benchmarkStore.benchmarking"
        class="page__description"
      >
        Generating the roster twice — this can take several seconds.
      </p>

      <div
        v-if="benchmarkStore.error"
        class="alert"
        role="alert"
      >
        {{ benchmarkStore.error }}
      </div>

      <template v-if="benchmarkStore.benchmark">
        <Table>
          <thead>
            <tr>
              <th>Metric</th>
              <th>Plain (greedy)</th>
              <th>Optimized (greedy + SA)</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in metricRows"
              :key="row[0]"
            >
              <td>{{ row[0] }}</td>
              <td>{{ row[1] }}</td>
              <td>{{ row[2] }}</td>
            </tr>
          </tbody>
        </Table>

        <p class="benchmark-saved">
          {{ savedSummary }}
        </p>

        <div
          v-if="benchmarkStore.benchmark.assignments_match === false"
          class="alert"
          role="alert"
        >
          Coverage changed between runs — this should never happen, investigate!
        </div>

        <!-- Worker deltas -->
        <h2 class="section-title">
          Workers affected by optimization
        </h2>

        <SortableTable
          v-if="workerDeltas.length"
          :columns="deltaColumns"
          :rows="workerDeltas"
          row-key="worker_id"
          :initial-sort="{ key: 'optimized_cost', direction: 'desc' }"
          empty-text="No workers changed."
        >
          <template #cell-hours_delta="{ value }">
            <span
              :class="{
                'delta--neg': value < 0,
                'delta--pos': value > 0,
              }"
            >{{ formatDeltaHours(value) }}</span>
          </template>
          <template #cell-cost_delta="{ value }">
            <span
              :class="{
                'delta--neg': value < 0,
                'delta--pos': value > 0,
              }"
            >{{ formatDeltaCost(value) }}</span>
          </template>
          <template #cell-shortfall_change="{ value }">
            <span
              v-if="value === 'appeared'"
              class="badge badge--warn"
            >appeared</span>
            <span
              v-else-if="value === 'disappeared'"
              class="badge badge--success"
            >resolved</span>
            <template v-else>
              —
            </template>
          </template>
        </SortableTable>

        <p
          v-else
          class="benchmark-empty"
        >
          Optimization did not change any worker's allocation.
        </p>

        <!-- Leaderboards -->
        <div class="benchmark-leaderboards">
          <div>
            <h2 class="section-title">
              Plain leaderboards
            </h2>
            <StatsLeaderboards :leaderboards="benchmarkStore.benchmark.worker_stats.leaderboards.plain" />
          </div>
          <div>
            <h2 class="section-title">
              Optimized leaderboards
            </h2>
            <StatsLeaderboards :leaderboards="benchmarkStore.benchmark.worker_stats.leaderboards.optimized" />
          </div>
        </div>

        <!-- Full worker tables -->
        <div
          v-if="benchmarkStore.benchmark.worker_stats.truncated"
          class="alert"
          role="alert"
        >
          Per-worker detail tables are omitted — workforce exceeds 300 workers.
          Deltas and leaderboards above are still complete.
        </div>

        <template v-else>
          <div class="benchmark-toggle">
            <Button
              type="button"
              @click="showFullTables = !showFullTables"
            >
              {{ showFullTables ? 'Hide full tables' : 'Show full tables' }}
            </Button>
          </div>

          <template v-if="showFullTables">
            <h2 class="section-title section-title--spaced">
              Plain — per-worker stats
            </h2>
            <SortableTable
              :columns="workerColumns"
              :rows="benchmarkStore.benchmark.worker_stats.plain"
              row-key="worker_id"
              :initial-sort="{ key: 'total_cost', direction: 'desc' }"
              empty-text="No workers assigned."
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

            <h2 class="section-title section-title--spaced">
              Optimized — per-worker stats
            </h2>
            <SortableTable
              :columns="workerColumns"
              :rows="benchmarkStore.benchmark.worker_stats.optimized"
              row-key="worker_id"
              :initial-sort="{ key: 'total_cost', direction: 'desc' }"
              empty-text="No workers assigned."
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
        </template>
      </template>
    </section>
  </main>
</template>

<style scoped>
@import '@/assets/ui/page.css';
@import '@/assets/ui/table.css';

.toolbar {
  display: grid;
  grid-template-columns: minmax(10rem, 14rem) auto;
  gap: 1rem;
  align-items: end;
}

.toolbar__actions {
  display: flex;
  gap: 0.5rem;
}

.benchmark-saved {
  margin-top: 1rem;
  font-weight: 600;
  color: #334155;
}

.section-title {
  margin: 1.5rem 0 0.75rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: #0f172a;
}

.section-title--spaced {
  margin-top: 2rem;
}

.benchmark-empty {
  margin-top: 0.75rem;
  color: #64748b;
  font-size: 0.875rem;
}

.benchmark-leaderboards {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.5rem;
  margin-top: 0.5rem;
}

.benchmark-toggle {
  margin-top: 1rem;
}

.delta--neg {
  color: #166534;
  font-weight: 600;
}

.delta--pos {
  color: #991b1b;
  font-weight: 600;
}

@media (max-width: 820px) {
  .toolbar {
    grid-template-columns: 1fr;
  }

  .toolbar__actions {
    align-items: stretch;
    flex-direction: column;
  }

  .benchmark-leaderboards {
    grid-template-columns: 1fr;
  }
}
</style>
