<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useRostersStore } from '@/stores/rosters'

const router = useRouter()
const rostersStore = useRostersStore()

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
  value: index + 1,
  label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, index, 1)),
}))

const yearOptions = computed(() => {
  const currentYear = new Date().getFullYear()
  return [currentYear - 1, currentYear, currentYear + 1]
})

const canGenerate = computed(
  () => rostersStore.selectedYear != null && rostersStore.selectedMonth != null,
)

onMounted(() => {
  rostersStore.clearPreview()
  rostersStore.clearRoster()
  rostersStore.clearErrors()
  rostersStore.setSelectedMonth(null, null)
})

function onPeriodChange() {
  rostersStore.clearErrors()
}

async function generateRoster() {
  if (!canGenerate.value) {
    return
  }

  const roster = await rostersStore.saveDraft(
    rostersStore.selectedYear,
    rostersStore.selectedMonth,
  )

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
          Select a month and generate a draft roster. You can review, edit, and publish it afterward.
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
          <select
            v-model="rostersStore.selectedYear"
            class="input"
            required
            @change="onPeriodChange"
          >
            <option
              :value="null"
              disabled
            >
              Select year
            </option>
            <option
              v-for="year in yearOptions"
              :key="year"
              :value="year"
            >{{ year }}</option>
          </select>
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
            :disabled="rostersStore.saving || !canGenerate"
          >
            {{ rostersStore.saving ? 'Generating...' : 'Generate' }}
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

      <div class="empty-state">
        Choose a month and click Generate to create a draft roster you can edit.
      </div>
    </section>
  </main>
</template>
