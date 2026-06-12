<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRostersStore } from '@/stores/rosters'
import Button from '@/components/ui/Button.vue'
import Field from '@/components/ui/Field.vue'
import Select from '@/components/ui/Select.vue'
import Table from '@/components/ui/Table.vue'

const rostersStore = useRostersStore()

const selectedMonth = ref(null)

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
  value: index + 1,
  label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, index, 1)),
}))

const canRun = computed(() => selectedMonth.value != null)

const currencyFormat = new Intl.NumberFormat('en-US', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const metricRows = computed(() => {
  const benchmark = rostersStore.benchmark

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
  const benchmark = rostersStore.benchmark

  if (!benchmark) {
    return ''
  }

  return `Saved: ${currencyFormat.format(benchmark.saved_amount)} (${benchmark.saved_percent.toFixed(2)}%)`
})

onMounted(() => {
  rostersStore.clearErrors()
  rostersStore.benchmark = null
})

function onPeriodChange() {
  rostersStore.clearErrors()
}

async function runBenchmark() {
  if (!canRun.value) {
    return
  }

  await rostersStore.runBenchmark(selectedMonth.value)
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
            :disabled="rostersStore.benchmarking || !canRun"
          >
            {{ rostersStore.benchmarking ? 'Running benchmark...' : 'Run benchmark' }}
          </Button>
        </div>
      </form>

      <p
        v-if="rostersStore.benchmarking"
        class="page__description"
      >
        Generating the roster twice — this can take several seconds.
      </p>

      <div
        v-if="rostersStore.error"
        class="alert"
        role="alert"
      >
        {{ rostersStore.error }}
      </div>

      <template v-if="rostersStore.benchmark">
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
          v-if="rostersStore.benchmark.assignments_match === false"
          class="alert"
          role="alert"
        >
          Coverage changed between runs — this should never happen, investigate!
        </div>
      </template>
    </section>
  </main>
</template>

<style scoped>
@import '@/assets/ui/page.css';

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

@media (max-width: 820px) {
  .toolbar {
    grid-template-columns: 1fr;
  }

  .toolbar__actions {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
