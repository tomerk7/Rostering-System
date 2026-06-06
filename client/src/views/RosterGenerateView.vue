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

const hasPreview = computed(
  () => rostersStore.preview != null && referenceData.reference != null,
)

onMounted(() => {
  rostersStore.clearPreview()
  rostersStore.clearRoster()
  rostersStore.clearErrors()
  rostersStore.setSelectedMonth(currentYear, null)
  referenceData.load()
})

function onPeriodChange() {
  rostersStore.clearErrors()
  rostersStore.clearPreview()
}

async function generateRoster() {
  if (!canGenerate.value) {
    return
  }

  await rostersStore.generatePreview(
    rostersStore.selectedYear,
    rostersStore.selectedMonth,
  )
}

async function saveDraft() {
  const roster = await rostersStore.saveFromGeneration()

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
          Select a month and generate a roster preview. Review it below, then save it as a draft.
        </p>
      </div>
      <div class="page__actions">
        <RouterLink
          class="button"
          :to="{ name: 'rosters' }"
        >
          Back to list
        </RouterLink>
        <button
          v-if="hasPreview"
          type="button"
          class="button button--primary"
          :disabled="rostersStore.saving"
          @click="saveDraft"
        >
          {{ rostersStore.saving ? 'Saving...' : 'Save as draft' }}
        </button>
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
            :disabled="rostersStore.previewing || !canGenerate"
          >
            {{ rostersStore.previewing ? 'Generating...' : 'Generate' }}
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
        v-if="rostersStore.previewing"
        class="empty-state"
      >
        Generating roster preview...
      </div>

      <template v-else-if="hasPreview">
        <RosterAlertSummary
          :summary="rostersStore.summary"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :shifts="referenceData.reference.shifts"
          :roles="referenceData.reference.roles"
        />

        <RosterGrid
          :year="rostersStore.preview.year"
          :month="rostersStore.preview.month"
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
        Choose a month and click Generate to preview a roster you can save as a draft.
      </div>
    </section>
  </main>
</template>
