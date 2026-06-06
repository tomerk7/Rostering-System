<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRostersStore } from '@/stores/rosters'
import { useRosterReference } from '@/composables/useRosterReference'
import AssignmentFormModal from '@/components/rosters/AssignmentFormModal.vue'
import RosterAlertSummary from '@/components/rosters/RosterAlertSummary.vue'
import RosterGrid, { type GridCellSelection } from '@/components/rosters/RosterGrid.vue'
import { formatMonthYear } from '@/lib/rosterGrid'
import type { Worker } from '@/api/workers'

const route = useRoute()
const router = useRouter()
const rostersStore = useRostersStore()
const referenceData = useRosterReference()

const rosterId = computed(() => Number(route.params.id))
const showAssignmentModal = ref(false)
const assignmentError = ref('')
const assignmentContext = ref<GridCellSelection | null>(null)

const periodLabel = computed(() => {
  if (!rostersStore.roster) {
    return ''
  }

  return formatMonthYear(rostersStore.roster.year, rostersStore.roster.month)
})

const isDraft = computed(() => rostersStore.roster?.status === 'draft')

const activeWorkers = computed(() => Array.from(referenceData.workersById.values()))

const modalWorkers = computed((): Worker[] => {
  const roleId = assignmentContext.value?.roleId

  if (!roleId) {
    return activeWorkers.value
  }

  return activeWorkers.value.filter((worker) => worker.role.id === roleId)
})

onMounted(async () => {
  await Promise.all([referenceData.load(), rostersStore.fetchRoster(rosterId.value)])
})

watch(rosterId, async (id) => {
  if (!Number.isFinite(id)) {
    return
  }

  await rostersStore.fetchRoster(id)
})

function openAssignmentModal(context: GridCellSelection | null = null) {
  assignmentError.value = ''
  assignmentContext.value = context
  showAssignmentModal.value = true
}

function closeAssignmentModal() {
  showAssignmentModal.value = false
  assignmentContext.value = null
  assignmentError.value = ''
}

async function submitAssignment(payload: { worker_id: number; shift_id: number; work_date: string }) {
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

async function removeAssignment(assignmentId: number) {
  if (!rostersStore.roster) {
    return
  }

  if (!window.confirm('Remove this assignment from the draft roster?')) {
    return
  }

  await rostersStore.removeManualAssignment(rostersStore.roster.id, assignmentId)
}

async function publishRoster() {
  if (!rostersStore.roster) {
    return
  }

  if (!window.confirm(`Publish the ${periodLabel.value} roster? This cannot be undone.`)) {
    return
  }

  await rostersStore.publish(rostersStore.roster.id)
}

function deleteConfirmMessage(): string {
  const roster = rostersStore.roster
  const period = periodLabel.value

  if (!roster) {
    return ''
  }

  if (roster.status === 'published') {
    return `Delete the published ${period} roster? This will remove the active schedule for this month.`
  }

  if (roster.status === 'superseded') {
    return `Delete the superseded ${period} roster? This cannot be undone.`
  }

  return `Delete the ${period} draft roster? This cannot be undone.`
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
</script>

<template>
  <main class="page page--wide">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">Rosters</p>
        <h1 class="page__title">
          <template v-if="rostersStore.roster">{{ periodLabel }}</template>
          <template v-else>Roster detail</template>
        </h1>
        <p v-if="rostersStore.roster" class="page__description">
          Status:
          <span class="badge" :class="rostersStore.roster.status === 'published' ? 'badge--success' : 'badge--muted'">
            {{ rostersStore.roster.status }}
          </span>
        </p>
      </div>
      <div class="page__actions">
        <RouterLink class="button" :to="{ name: 'rosters' }">Back to list</RouterLink>
        <button
          v-if="isDraft"
          type="button"
          class="button"
          :disabled="rostersStore.assignmentLoading"
          @click="openAssignmentModal()"
        >
          Add assignment
        </button>
        <button
          v-if="isDraft"
          type="button"
          class="button button--primary"
          :disabled="rostersStore.publishing"
          @click="publishRoster"
        >
          {{ rostersStore.publishing ? 'Publishing...' : 'Publish' }}
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
      <div v-if="rostersStore.error && !showAssignmentModal" class="alert" role="alert">
        {{ rostersStore.error }}
      </div>

      <div v-if="referenceData.error" class="alert" role="alert">
        {{ referenceData.error }}
      </div>

      <div v-if="rostersStore.loading || referenceData.loading" class="empty-state">
        Loading roster...
      </div>

      <div v-else-if="!rostersStore.roster" class="empty-state">
        Roster not found.
        <button type="button" class="button" @click="router.push({ name: 'rosters' })">
          Back to list
        </button>
      </div>

      <template v-else-if="referenceData.reference">
        <RosterAlertSummary
          :summary="rostersStore.summary"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :shifts="referenceData.reference.shifts"
          :roles="referenceData.reference.roles"
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
          :editable="isDraft"
          @cell-click="openAssignmentModal"
          @remove-assignment="removeAssignment"
        />
      </template>
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
