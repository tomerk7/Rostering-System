<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRostersStore } from '@/stores/rosters'
import { useRosterReference } from '@/composables/useRosterReference'
import AssignmentFormModal from '@/components/rosters/AssignmentFormModal.vue'
import RosterAlertSummary from '@/components/rosters/RosterAlertSummary.vue'
import RosterGrid from '@/components/rosters/RosterGrid.vue'
import { formatMonthYear } from '@/lib/rosterGrid'

const route = useRoute()
const router = useRouter()
const rostersStore = useRostersStore()
const referenceData = useRosterReference()

const rosterId = computed(() => Number(route.params.id))
const showAssignmentModal = ref(false)
const assignmentError = ref('')
const assignmentContext = ref(null)

const periodLabel = computed(() => {
  if (!rostersStore.roster) {
    return ''
  }

  return formatMonthYear(rostersStore.roster.year, rostersStore.roster.month)
})

const activeWorkers = computed(() => Array.from(referenceData.workersById.values()))

onMounted(async () => {
  await loadRoster()
})

watch(rosterId, async () => {
  await loadRoster()
})

async function loadRoster() {
  await Promise.all([referenceData.load(), rostersStore.fetchRoster(rosterId.value)])
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
  if (!rostersStore.roster) {
    return
  }

  assignmentError.value = ''

  const roster = await rostersStore.addManualAssignment(rostersStore.roster.id, payload)

  if (roster) {
    closeAssignmentModal()
    return
  }

  assignmentError.value = rostersStore.validationErrors.assignment?.[0] ?? rostersStore.error
}

async function removeAssignment(assignmentId) {
  if (!rostersStore.roster) {
    return
  }

  if (!window.confirm('Remove this assignment from the roster?')) {
    return
  }

  await rostersStore.removeManualAssignment(rostersStore.roster.id, assignmentId)
}

function deleteConfirmMessage() {
  const roster = rostersStore.roster

  if (!roster) {
    return ''
  }

  return `Delete the ${periodLabel.value} roster? This will remove the schedule for this month.`
}

async function deleteRoster() {
  if (!rostersStore.roster) {
    return
  }

  if (!window.confirm(deleteConfirmMessage())) {
    return
  }

  const deleted = await rostersStore.removeRoster(rostersStore.roster.id)

  if (deleted) {
    await router.push({ name: 'rosters' })
  }
}

function regenerateConfirmMessage() {
  return `Regenerate the ${periodLabel.value} roster? This will replace all assignments and remove any manual edits.`
}

async function regenerateRoster() {
  const roster = rostersStore.roster

  if (!roster) {
    return
  }

  if (!window.confirm(regenerateConfirmMessage())) {
    return
  }

  const regenerated = await rostersStore.regenerate(roster.id)

  if (!regenerated) {
    return
  }

  await loadRoster()
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
            {{ periodLabel }}
          </template>
          <template v-else>
            Roster detail
          </template>
        </h1>
      </div>
      <div class="page__actions">
        <RouterLink
          class="button"
          :to="{ name: 'rosters' }"
        >
          Back to list
        </RouterLink>
        <button
          v-if="rostersStore.roster"
          type="button"
          class="button button--primary"
          :disabled="rostersStore.generating"
          @click="regenerateRoster"
        >
          {{ rostersStore.generating ? 'Regenerating...' : 'Regenerate' }}
        </button>
        <button
          type="button"
          class="button button--danger"
          :disabled="rostersStore.deletingId === rosterId"
          @click="deleteRoster"
        >
          {{ rostersStore.deletingId === rosterId ? 'Deleting...' : 'Delete' }}
        </button>
      </div>
    </header>

    <section class="panel">
      <div
        v-if="rostersStore.error && !showAssignmentModal"
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
        v-if="rostersStore.loading || referenceData.loading"
        class="empty-state"
      >
        Loading roster...
      </div>

      <div
        v-else-if="!rostersStore.roster"
        class="empty-state"
      >
        Roster not found.
        <button
          type="button"
          class="button"
          @click="router.push({ name: 'rosters' })"
        >
          Back to list
        </button>
      </div>

      <template v-else-if="referenceData.reference">
        <RosterAlertSummary
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
        />

        <RosterGrid
          :year="rostersStore.roster.year"
          :month="rostersStore.roster.month"
          :shifts="referenceData.reference.shifts"
          :requirements="referenceData.reference.shift_role_requirements"
          :roles="referenceData.reference.roles"
          :assignments="rostersStore.assignments"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :editable="true"
          @cell-click="openAssignmentModal"
          @remove-assignment="removeAssignment"
        />
      </template>
    </section>

    <AssignmentFormModal
      :show="showAssignmentModal"
      :workers="activeWorkers"
      :assignments="rostersStore.assignments"
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

<style scoped>
@import '@/assets/ui/button.css';
@import '@/assets/ui/forms.css';
@import '@/assets/ui/page.css';
</style>
