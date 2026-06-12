<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useRostersStore } from '@/stores/rosters'
import { useRosterReference } from '@/composables/useRosterReference'
import RosterAlertSummary from '@/components/rosters/RosterAlertSummary.vue'
import RosterGrid from '@/components/rosters/RosterGrid.vue'

const router = useRouter()
const rostersStore = useRostersStore()
const referenceData = useRosterReference()

const currentYear = new Date().getFullYear()

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
  value: index + 1,
  label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, index, 1)),
}))

const canGenerate = computed(
  () => rostersStore.selectedYear != null && rostersStore.selectedMonth != null,
)

const hasGeneratedRoster = computed(
  () => rostersStore.generatedRoster != null && referenceData.reference != null,
)

const hasCoverageShortages = computed(
  () => (rostersStore.summary?.coverage_shortage_count ?? 0) > 0,
)

onMounted(() => {
  rostersStore.generatedRoster = null
  rostersStore.roster = null
  rostersStore.clearErrors()
  rostersStore.selectedYear = currentYear
  rostersStore.selectedMonth = null
  referenceData.load()
})

function onPeriodChange() {
  rostersStore.clearErrors()
  rostersStore.generatedRoster = null
}

async function generateRoster() {
  if (!canGenerate.value) {
    return
  }

  await rostersStore.generate(
    rostersStore.selectedYear,
    rostersStore.selectedMonth,
  )
}

async function saveRoster() {
  const preview = rostersStore.generatedRoster

  if (!preview) {
    return
  }

  if (hasCoverageShortages.value && !window.confirm(
    'This roster has coverage shortages. Save it anyway?',
  )) {
    return
  }

  const roster = await rostersStore.save(preview.year, preview.month)

  if (roster) {
    await router.push({ name: 'rosters.show', params: { id: roster.id } })
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
          Generate Roster
        </h1>
        <p class="page__description">
          Select a month and generate a preview. Review the alerts, then save to
          persist the roster.
        </p>
      </div>
      <div class="page__actions">
        <RouterLink
          class="button"
          :to="{ name: 'rosters' }"
        >
          Back to list
        </RouterLink>
      </div>
    </header>

    <section class="panel">
      <form
        class="toolbar roster-toolbar"
        @submit.prevent="generateRoster"
      >
        <label class="field">
          <span class="field__label">Year</span>
          <input
            :value="currentYear"
            class="input"
            type="text"
            readonly
          >
        </label>

        <label class="field">
          <span class="field__label">Month</span>
          <select
            v-model="rostersStore.selectedMonth"
            class="input"
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
          </select>
        </label>

        <div class="toolbar__actions">
          <button
            type="submit"
            class="button button--primary"
            :disabled="rostersStore.generating || !canGenerate"
          >
            {{ rostersStore.generating ? 'Generating...' : 'Generate' }}
          </button>
          <button
            v-if="hasGeneratedRoster"
            type="button"
            class="button button--primary"
            :disabled="rostersStore.saving"
            @click="saveRoster"
          >
            {{ rostersStore.saving ? 'Saving...' : 'Save roster' }}
          </button>
        </div>
      </form>

      <div
        v-if="rostersStore.error"
        class="alert"
        role="alert"
      >
        {{ rostersStore.error }}
      </div>

      <div
        v-if="referenceData.error"
        class="alert"
        role="alert"
      >
        {{ referenceData.error }}
      </div>

      <div
        v-if="rostersStore.generating"
        class="empty-state"
      >
        Generating roster...
      </div>

      <template v-else-if="hasGeneratedRoster">
        <p class="roster-preview-hint">
          This is a preview. Nothing is saved until you click Save roster.
        </p>

        <RosterAlertSummary
          :summary="rostersStore.summary"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :shifts="referenceData.reference.shifts"
          :roles="referenceData.reference.roles"
        />

        <RosterGrid
          :year="rostersStore.generatedRoster.year"
          :month="rostersStore.generatedRoster.month"
          :shifts="referenceData.reference.shifts"
          :requirements="referenceData.reference.shift_role_requirements"
          :roles="referenceData.reference.roles"
          :assignments="rostersStore.assignments"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :editable="false"
        />
      </template>

      <div
        v-else
        class="empty-state"
      >
        Choose a month and click Generate to create a roster.
      </div>
    </section>
  </main>
</template>
