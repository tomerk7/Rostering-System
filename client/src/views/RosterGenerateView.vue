<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useRostersStore } from '@/stores/rosters'
import Button from '@/components/ui/Button.vue'
import Field from '@/components/ui/Field.vue'
import Select from '@/components/ui/Select.vue'

const router = useRouter()
const rostersStore = useRostersStore()

const optimizeCost = ref(false)

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
  value: index + 1,
  label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, index, 1)),
}))

const canGenerate = computed(
  () => rostersStore.currentYear != null && rostersStore.selectedMonth != null,
)

const existingRosterForPeriod = computed(() => {
  if (rostersStore.currentYear == null || rostersStore.selectedMonth == null) {
    return null
  }

  return rostersStore.rosters.find(
    (roster) => roster.year === rostersStore.currentYear
      && roster.month === rostersStore.selectedMonth,
  ) ?? null
})

onMounted(async () => {
  rostersStore.clearErrors()
  rostersStore.selectedMonth = null
  await rostersStore.fetchRosters()
})

function onPeriodChange() {
  rostersStore.clearErrors()
}

async function generateRoster() {
  if (!canGenerate.value) {
    return
  }

  const existing = existingRosterForPeriod.value
  const roster = existing
    ? await rostersStore.regenerate(existing.id, optimizeCost.value)
    : await rostersStore.create(rostersStore.selectedMonth, optimizeCost.value)

  if (roster) {
    await router.push({ name: 'rosters.show', params: { id: roster.id } })
  }
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
          Generate Roster
        </h1>
        <p class="page__description">
          Select a month and generate a roster. You will be taken to the schedule
          preview where you can review alerts and make manual edits.
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
        class="toolbar roster-toolbar"
        @submit.prevent="generateRoster"
      >
        <Field label="Month">
          <Select
            v-model="rostersStore.selectedMonth"
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

        <label class="check-field">
          <input
            v-model="optimizeCost"
            type="checkbox"
          >
          <span>Schedule by cost efficiency</span>
        </label>

        <div class="toolbar__actions">
          <Button
            type="submit"
            variant="primary"
            :disabled="rostersStore.generating || !canGenerate"
          >
            {{ rostersStore.generating ? 'Generating...' : 'Generate roster' }}
          </Button>
        </div>
      </form>

      <p
        v-if="existingRosterForPeriod"
        class="page__description"
      >
        A roster already exists for this month. Generating will replace it.
      </p>

      <div
        v-if="rostersStore.error"
        class="alert"
        role="alert"
      >
        {{ rostersStore.error }}
      </div>
    </section>
  </main>
</template>

<style scoped>
@import '@/assets/ui/page.css';

.toolbar {
  display: grid;
  grid-template-columns: minmax(14rem, 1fr) repeat(2, minmax(10rem, 12rem)) auto;
  gap: 1rem;
  align-items: end;
}

.roster-toolbar {
  grid-template-columns: repeat(2, minmax(10rem, 14rem)) auto;
}

.check-field {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  min-height: 2.375rem;
  color: #334155;
}

.check-field input {
  width: 1rem;
  height: 1rem;
  accent-color: #2563eb;
}

.toolbar__actions {
  display: flex;
  gap: 0.5rem;
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
