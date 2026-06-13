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
const selectedPreference = ref('balanced')

const objectiveOptions = [
  { value: 'maximum_savings', label: 'Maximum savings' },
  { value: 'cost_focused', label: 'Cost focused' },
  { value: 'balanced', label: 'Balanced' },
  { value: 'distribution_focused', label: 'Spread hours evenly' },
]

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

function formatDeltaCost(value) {
  if (value === 0) return '—'
  const abs = `₪${currencyFormat.format(Math.abs(value))}`
  return value > 0 ? `+${abs}` : `-${abs}`
}

function formatDeltaHours(value) {
  if (value === 0) return '—'
  return value > 0 ? `+${value}h` : `${value}h`
}

function formatWorkloadSpread(value) {
  return `${Number(value).toFixed(1)} hours`
}

const metricRows = computed(() => {
  const benchmark = benchmarkStore.benchmark

  if (!benchmark) {
    return []
  }

  const { plain, optimized } = benchmark

  return [
    { key: 'assignments', label: 'Assignments', plain: plain.assignments, optimized: optimized.assignments },
    { key: 'coverage_shortages', label: 'Coverage shortages', plain: plain.coverage_shortages, optimized: optimized.coverage_shortages },
    { key: 'total_cost', label: 'Total cost', plain: formatCurrency(plain.total_cost), optimized: formatCurrency(optimized.total_cost) },
    { key: 'min_hours_shortfall_workers', label: 'Min-hours shortfall (workers)', plain: plain.min_hours_shortfall_workers, optimized: optimized.min_hours_shortfall_workers },
    { key: 'min_hours_shortfall_hours', label: 'Min-hours shortfall (hours)', plain: plain.min_hours_shortfall_hours, optimized: optimized.min_hours_shortfall_hours },
    { key: 'max_hours_violations', label: 'Max-hours violations (workers)', plain: plain.max_hours_violations, optimized: optimized.max_hours_violations },
    {
      key: 'hours_std_dev',
      label: 'Workload spread',
      hint: 'How much workers\' assigned hours differ from the average. Lower is more evenly distributed.',
      lowerIsBetter: true,
      plain: formatWorkloadSpread(plain.hours_std_dev),
      optimized: formatWorkloadSpread(optimized.hours_std_dev),
    },
    { key: 'generation_seconds', label: 'Generation time', plain: `${plain.generation_seconds.toFixed(2)}s`, optimized: `${optimized.generation_seconds.toFixed(2)}s` },
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

  await benchmarkStore.runBenchmark(selectedMonth.value, selectedPreference.value)
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
          Compare plain greedy roster generation against a cost-optimized run for
          the selected objective. Nothing is saved.
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

        <Field label="Objective">
          <Select v-model="selectedPreference">
            <option
              v-for="option in objectiveOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
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
              :key="row.key"
            >
              <td>
                <div class="benchmark-metric">
                  <div class="benchmark-metric__heading">
                    <span class="benchmark-metric__label">{{ row.label }}</span>
                    <span
                      v-if="row.lowerIsBetter"
                      class="benchmark-metric__badge"
                    >Lower is better</span>
                  </div>
                  <p
                    v-if="row.hint"
                    class="benchmark-metric__hint"
                  >
                    {{ row.hint }}
                  </p>
                </div>
              </td>
              <td>{{ row.plain }}</td>
              <td>{{ row.optimized }}</td>
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

        <div
          v-if="workerDeltas.length"
          class="benchmark-table-scroll"
        >
          <SortableTable
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
        </div>

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

      </template>
    </section>
  </main>
</template>

<style scoped>
@import '@/assets/ui/page.css';
@import '@/assets/ui/table.css';

.toolbar {
  display: grid;
  grid-template-columns: repeat(2, minmax(10rem, 14rem)) auto;
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

.benchmark-metric__heading {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem 0.5rem;
  align-items: center;
}

.benchmark-metric__label {
  font-weight: 600;
  color: #0f172a;
}

.benchmark-metric__badge {
  font-size: 0.6875rem;
  font-weight: 700;
  color: #166534;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.benchmark-metric__hint {
  margin: 0.25rem 0 0;
  font-size: 0.8125rem;
  line-height: 1.4;
  color: #64748b;
}

.section-title {
  margin: 1.5rem 0 0.75rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: #0f172a;
}

.benchmark-table-scroll {
  max-height: 50vh;
  overflow: auto;
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
