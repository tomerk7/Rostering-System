<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useRostersStore } from '@/stores/rosters'
import { useRosterReference } from '@/composables/useRosterReference'
import RosterAlertSummary from '@/components/rosters/RosterAlertSummary.vue'
import RosterGrid from '@/components/rosters/RosterGrid.vue'
import { formatMonthYear } from '@/lib/rosterGrid'

const router = useRouter()
const rostersStore = useRostersStore()
const referenceData = useRosterReference()

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
  value: index + 1,
  label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, index, 1)),
}))

const yearOptions = computed(() => {
  const currentYear = new Date().getFullYear()
  return [currentYear - 1, currentYear, currentYear + 1]
})

const periodLabel = computed(() =>
  formatMonthYear(rostersStore.selectedYear, rostersStore.selectedMonth),
)

const hasPreview = computed(() => rostersStore.preview !== null)

onMounted(async () => {
  await referenceData.load()
})

async function runPreview() {
  await rostersStore.generatePreview(rostersStore.selectedYear, rostersStore.selectedMonth)
}

async function saveDraft() {
  const roster = await rostersStore.saveDraft(rostersStore.selectedYear, rostersStore.selectedMonth)

  if (roster) {
    await router.push({ name: 'rosters.show', params: { id: roster.id } })
  }
}
</script>

<template>
  <main class="page page--wide">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">Rosters</p>
        <h1 class="page__title">Generate Roster</h1>
        <p class="page__description">
          Select a month, run a preview, review alerts, then save as draft.
        </p>
      </div>
      <div class="page__actions">
        <RouterLink class="button" :to="{ name: 'rosters' }">Back to list</RouterLink>
      </div>
    </header>

    <section class="panel">
      <form class="toolbar roster-toolbar" @submit.prevent="runPreview">
        <label class="field">
          <span class="field__label">Year</span>
          <select v-model.number="rostersStore.selectedYear" class="input">
            <option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option>
          </select>
        </label>

        <label class="field">
          <span class="field__label">Month</span>
          <select v-model.number="rostersStore.selectedMonth" class="input">
            <option v-for="month in monthOptions" :key="month.value" :value="month.value">
              {{ month.label }}
            </option>
          </select>
        </label>

        <div class="toolbar__actions">
          <button type="submit" class="button button--primary" :disabled="rostersStore.previewing">
            {{ rostersStore.previewing ? 'Generating preview...' : 'Run preview' }}
          </button>
          <button
            v-if="hasPreview"
            type="button"
            class="button button--primary"
            :disabled="rostersStore.saving"
            @click="saveDraft"
          >
            {{ rostersStore.saving ? 'Saving draft...' : 'Save draft' }}
          </button>
        </div>
      </form>

      <div v-if="rostersStore.error" class="alert" role="alert">
        {{ rostersStore.error }}
      </div>

      <div v-if="referenceData.error" class="alert" role="alert">
        {{ referenceData.error }}
      </div>

      <div v-if="referenceData.loading" class="empty-state">Loading reference data...</div>

      <template v-else-if="hasPreview && referenceData.reference">
        <div class="roster-preview-banner">
          <strong>Preview for {{ periodLabel }}</strong>
          <span>Review alerts before saving. Nothing is persisted until you save the draft.</span>
        </div>

        <RosterAlertSummary
          :summary="rostersStore.summary"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :shifts="referenceData.reference.shifts"
          :roles="referenceData.reference.roles"
        />

        <RosterGrid
          :year="rostersStore.selectedYear"
          :month="rostersStore.selectedMonth"
          :shifts="referenceData.reference.shifts"
          :requirements="referenceData.reference.shift_role_requirements"
          :roles="referenceData.reference.roles"
          :assignments="rostersStore.assignments"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
        />
      </template>

      <div v-else class="empty-state">
        Choose a month and run preview to see the roster grid and alerts.
      </div>
    </section>
  </main>
</template>