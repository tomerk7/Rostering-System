<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRostersStore } from '@/stores/rosters'
import { useRosterReference } from '@/composables/useRosterReference'
import AssignmentFormModal from '@/components/rosters/AssignmentFormModal.vue'
import RosterAlertSummary from '@/components/rosters/RosterAlertSummary.vue'
import RosterGrid from '@/components/rosters/RosterGrid.vue'
import { formatMonthYear } from '@/lib/rosterGrid'

const rostersStore = useRostersStore()
const referenceData = useRosterReference()
const showAssignmentModal = ref(false)
const assignmentError = ref('')
const assignmentContext = ref(null)

const currentYear = new Date().getFullYear()

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
  value: index + 1,
  label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, index, 1)),
}))

const canGenerate = computed(
  () => rostersStore.selectedYear != null && rostersStore.selectedMonth != null,
)

const hasGeneratedDraft = computed(
  () => rostersStore.generatedDraft != null && referenceData.reference != null,
)

const periodLabel = computed(() => {
  const draft = rostersStore.generatedDraft

  return draft ? formatMonthYear(draft.year, draft.month) : ''
})

const activeWorkers = computed(() => Array.from(referenceData.workersById.values()))

const modalWorkers = computed(() => {
  const roleId = assignmentContext.value?.roleId

  return roleId
    ? activeWorkers.value.filter((worker) => worker.role.id === roleId)
    : activeWorkers.value
})

onMounted(() => {
  rostersStore.clearGeneratedDraft()
  rostersStore.clearRoster()
  rostersStore.clearErrors()
  rostersStore.setSelectedMonth(currentYear, null)
  referenceData.load()
})

function onPeriodChange() {
  rostersStore.clearErrors()
  rostersStore.clearGeneratedDraft()
}

async function generateRoster() {
  if (!canGenerate.value) {
    return
  }

  await rostersStore.generateDraft(
    rostersStore.selectedYear,
    rostersStore.selectedMonth,
  )
}

function openAssignmentModal(context = null) {
  assignmentError.value = ''
  assignmentContext.value = context
  showAssignmentModal.value = true
}

function closeAssignmentModal() {
  showAssignmentModal.value = false
  assignmentContext.value = null
  assignmentError.value = ''
}

async function submitAssignment(payload) {
  const draft = rostersStore.generatedDraft

  if (!draft) {
    return
  }

  const roster = await rostersStore.addManualAssignment(draft.id, payload)

  if (roster) {
    closeAssignmentModal()
    return
  }

  assignmentError.value = rostersStore.validationErrors.assignment?.[0] ?? rostersStore.error
}

async function removeAssignment(assignmentId) {
  const draft = rostersStore.generatedDraft

  if (!draft || !window.confirm('Remove this assignment from the draft roster?')) {
    return
  }

  await rostersStore.removeManualAssignment(draft.id, assignmentId)
}

async function publishGeneratedDraft() {
  const draft = rostersStore.generatedDraft

  if (!draft || !window.confirm(`Publish the ${periodLabel.value} roster? This cannot be undone.`)) {
    return
  }

  await rostersStore.publish(draft.id)
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
          Select a month and generate a draft roster. Review the result below.
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
          v-if="hasGeneratedDraft && rostersStore.generatedDraft.status === 'draft'"
          type="button"
          class="button"
          :disabled="rostersStore.assignmentLoading"
          @click="openAssignmentModal()"
        >
          Add assignment
        </button>
        <button
          v-if="hasGeneratedDraft && rostersStore.generatedDraft.status === 'draft'"
          type="button"
          class="button button--primary"
          :disabled="rostersStore.publishing"
          @click="publishGeneratedDraft"
        >
          {{ rostersStore.publishing ? 'Publishing...' : 'Publish' }}
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
            :disabled="rostersStore.generating || !canGenerate"
          >
            {{ rostersStore.generating ? 'Generating...' : 'Generate' }}
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
        Generating draft roster...
      </div>

      <template v-else-if="hasGeneratedDraft">
        <RosterAlertSummary
          :summary="rostersStore.summary"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :shifts="referenceData.reference.shifts"
          :roles="referenceData.reference.roles"
        />

        <RosterGrid
          :year="rostersStore.generatedDraft.year"
          :month="rostersStore.generatedDraft.month"
          :shifts="referenceData.reference.shifts"
          :requirements="referenceData.reference.shift_role_requirements"
          :roles="referenceData.reference.roles"
          :assignments="rostersStore.assignments"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :editable="rostersStore.generatedDraft.status === 'draft'"
          @cell-click="openAssignmentModal"
          @remove-assignment="removeAssignment"
        />
      </template>

      <div
        v-else
        class="empty-state"
      >
        Choose a month and click Generate to create a draft roster.
      </div>
    </section>

    <AssignmentFormModal
      :show="showAssignmentModal"
      :workers="modalWorkers.length ? modalWorkers : activeWorkers"
      :shifts="referenceData.reference?.shifts ?? []"
      :roles="referenceData.reference?.roles"
      :initial-date="assignmentContext?.workDate"
      :initial-shift-id="assignmentContext?.shiftId"
      :initial-role-id="assignmentContext?.roleId"
      :saving="rostersStore.assignmentLoading"
      :error="assignmentError"
      @close="closeAssignmentModal"
      @submit="submitAssignment"
    />
  </main>
</template>
