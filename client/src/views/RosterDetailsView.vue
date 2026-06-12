<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { exportRoster } from '@/api/rosters'
import { resolveErrorMessage } from '@/lib/apiError'
import { useRostersStore } from '@/stores/rosters'
import { useRosterReference } from '@/composables/useRosterReference'
import AssignmentFormModal from '@/components/rosters/AssignmentFormModal.vue'
import RosterAlertSummary from '@/components/rosters/RosterAlertSummary.vue'
import RosterGrid from '@/components/rosters/RosterGrid.vue'
import {
  addDays,
  formatMonthYear,
  getMonthRange,
  getRosterWeekRange,
} from '@/lib/rosterGrid'

const route = useRoute()
const router = useRouter()
const rostersStore = useRostersStore()
const referenceData = useRosterReference()

/**
 * Roster id from the route params.
 *
 * @returns {number}
 */
const rosterId = computed(() => Number(route.params.id))
const showAssignmentModal = ref(false)
const assignmentError = ref('')
const assignmentContext = ref(null)
const weekAnchor = ref('')
const viewMode = ref('week')
const exporting = ref(false)
const exportError = ref('')
const optimizeCost = ref(false)

/**
 * Formatted month and year label for the loaded roster.
 *
 * @returns {string}
 */
const periodLabel = computed(() => {
  if (!rostersStore.roster) {
    return ''
  }

  return formatMonthYear(rostersStore.roster.year, rostersStore.roster.month)
})

/**
 * Active workers from reference data.
 *
 * @returns {object[]}
 */
const activeWorkers = computed(() => Array.from(referenceData.workersById.values()))

/**
 * Inclusive date range for the roster month.
 *
 * @returns {{ startDate: string, endDate: string }|null}
 */
const monthRange = computed(() => {
  const roster = rostersStore.roster

  return roster ? getMonthRange(roster.year, roster.month) : null
})
/**
 * Inclusive date range for the visible week.
 *
 * @returns {{ startDate: string, endDate: string }|null}
 */
const weekRange = computed(() => {
  const roster = rostersStore.roster

  if (!roster || !weekAnchor.value) {
    return null
  }

  return getRosterWeekRange(roster.year, roster.month, weekAnchor.value)
})
/**
 * Whether the grid can navigate to the previous week within the month.
 *
 * @returns {boolean}
 */
const canGoPrevious = computed(
  () => weekRange.value && monthRange.value
    && weekRange.value.startDate > monthRange.value.startDate,
)

/**
 * Whether the grid can navigate to the next week within the month.
 *
 * @returns {boolean}
 */
const canGoNext = computed(
  () => weekRange.value && monthRange.value
    && weekRange.value.endDate < monthRange.value.endDate,
)

/**
 * Date range shown in the grid for the active view mode.
 *
 * @returns {{ startDate: string, endDate: string }|null}
 */
const gridRange = computed(() => {
  if (viewMode.value === 'month') {
    return monthRange.value
  }

  return weekRange.value
})

/**
 * Whether the roster has unresolved coverage shortages.
 *
 * @returns {boolean}
 */
const hasCoverageShortages = computed(
  () => (rostersStore.summary?.coverage_shortage_count ?? 0) > 0,
)

/**
 * Tooltip shown when export is disabled due to coverage shortages.
 *
 * @returns {string}
 */
const exportDisabledReason = computed(() => (
  hasCoverageShortages.value
    ? 'Export is available only when all shifts are fully assigned.'
    : ''
))

onMounted(async () => {
  await loadRoster()
})

watch(rosterId, async () => {
  await loadRoster()
})

/**
 * Load roster metadata, reference data, and the initial week.
 *
 * @returns {Promise<void>}
 */
async function loadRoster() {
  await Promise.all([referenceData.load(), rostersStore.fetchRoster(rosterId.value)])

  const roster = rostersStore.roster
  if (!roster) {
    return
  }

  const today = new Date()
  const isCurrentMonth = today.getFullYear() === roster.year
    && today.getMonth() + 1 === roster.month
  weekAnchor.value = isCurrentMonth
    ? [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        String(today.getDate()).padStart(2, '0'),
      ].join('-')
    : getMonthRange(roster.year, roster.month).startDate

  await loadVisibleRange()
}

/**
 * Load assignments for the active grid range.
 *
 * @returns {Promise<boolean>}
 */
async function loadVisibleRange() {
  const roster = rostersStore.roster
  const range = gridRange.value

  if (!roster || !range) {
    return false
  }

  const response = await rostersStore.fetchAssignments(roster.id, {
    fromDate: range.startDate,
    toDate: range.endDate,
  })

  return Boolean(response)
}

/**
 * Load assignments for the week containing the anchor date.
 *
 * @param {string} anchorDate
 * @returns {Promise<boolean>}
 */
async function loadWeek(anchorDate) {
  weekAnchor.value = anchorDate
  return loadVisibleRange()
}

/**
 * Shift the visible week by a number of days.
 *
 * @param {number} days
 * @returns {Promise<void>}
 */
async function moveWeek(days) {
  await loadWeek(addDays(weekAnchor.value, days))
}

/**
 * Switch between week and full-month grid views.
 *
 * @param {'week'|'month'} mode
 * @returns {Promise<void>}
 */
async function setViewMode(mode) {
  if (viewMode.value === mode) {
    return
  }

  viewMode.value = mode
  await loadVisibleRange()
}

/**
 * Open the manual assignment modal, optionally prefilled from a grid cell.
 *
 * @param {object|null} [context]
 * @returns {void}
 */
function openAssignmentModal(context = null) {
  assignmentError.value = ''
  assignmentContext.value = context
  showAssignmentModal.value = true
}

/**
 * Close the assignment modal and clear its state.
 *
 * @returns {void}
 */
function closeAssignmentModal() {
  showAssignmentModal.value = false
  assignmentContext.value = null
  assignmentError.value = ''
}

/**
 * Add a manual assignment and refresh the current week.
 *
 * @param {object} payload
 * @returns {Promise<void>}
 */
async function submitAssignment(payload) {
  if (!rostersStore.roster) {
    return
  }

  assignmentError.value = ''

  const roster = await rostersStore.addManualAssignment(rostersStore.roster.id, payload)

  if (roster) {
    await loadVisibleRange()
    closeAssignmentModal()
    return
  }

  assignmentError.value = rostersStore.validationErrors.assignment?.[0] ?? rostersStore.error
}

/**
 * Remove a manual assignment after confirmation.
 *
 * @param {number} assignmentId
 * @returns {Promise<void>}
 */
async function removeAssignment(assignmentId) {
  if (!rostersStore.roster) {
    return
  }

  if (!window.confirm('Remove this assignment from the roster?')) {
    return
  }

  const roster = await rostersStore.removeManualAssignment(
    rostersStore.roster.id,
    assignmentId,
  )

  if (roster) {
    await loadVisibleRange()
  }
}

/**
 * Confirmation message shown before deleting the roster.
 *
 * @returns {string}
 */
function deleteConfirmMessage() {
  const roster = rostersStore.roster

  if (!roster) {
    return ''
  }

  return `Delete the ${periodLabel.value} roster? This will remove the schedule for this month.`
}

/**
 * Delete the roster and return to the list.
 *
 * @returns {Promise<void>}
 */
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

/**
 * Confirmation message shown before regenerating the roster.
 *
 * @returns {string}
 */
function regenerateConfirmMessage() {
  return `Regenerate the ${periodLabel.value} roster? This will replace all assignments and remove any manual edits.`
}

/**
 * Regenerate the roster and reload the view.
 *
 * @returns {Promise<void>}
 */
async function regenerateRoster() {
  const roster = rostersStore.roster

  if (!roster) {
    return
  }

  if (!window.confirm(regenerateConfirmMessage())) {
    return
  }

  const regenerated = await rostersStore.regenerate(roster.id, optimizeCost.value)

  if (!regenerated) {
    return
  }

  await loadRoster()
}

/**
 * Export the roster as CSV when fully assigned.
 *
 * @returns {Promise<void>}
 */
async function downloadExport() {
  const roster = rostersStore.roster

  if (!roster || hasCoverageShortages.value) {
    return
  }

  exporting.value = true
  exportError.value = ''

  try {
    const blob = await exportRoster(roster.id)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `roster-${roster.year}-${String(roster.month).padStart(2, '0')}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error) {
    exportError.value = resolveErrorMessage(error, 'Could not export roster. Please try again.')
  } finally {
    exporting.value = false
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
          class="button"
          :disabled="exporting || hasCoverageShortages"
          :title="exportDisabledReason"
          @click="downloadExport"
        >
          {{ exporting ? 'Exporting...' : 'Export CSV' }}
        </button>
        <label
          v-if="rostersStore.roster"
          class="check-field"
        >
          <input
            v-model="optimizeCost"
            type="checkbox"
          >
          <span>Schedule by cost efficiency</span>
        </label>
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
        v-if="exportError"
        class="alert"
        role="alert"
      >
        {{ exportError }}
      </div>

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
          v-if="gridRange"
          :start-date="gridRange.startDate"
          :end-date="gridRange.endDate"
          :shifts="referenceData.reference.shifts"
          :requirements="referenceData.reference.shift_role_requirements"
          :roles="referenceData.reference.roles"
          :assignments="rostersStore.assignments"
          :reports="rostersStore.reports"
          :workers-by-id="referenceData.workersById"
          :editable="true"
          :loading="rostersStore.assignmentsLoading"
          :show-navigation="viewMode === 'week'"
          :full-month="viewMode === 'month'"
          :can-go-previous="canGoPrevious"
          :can-go-next="canGoNext"
          @cell-click="openAssignmentModal"
          @remove-assignment="removeAssignment"
          @previous-week="moveWeek(-7)"
          @next-week="moveWeek(7)"
        >
          <template #view-toggle>
            <div
              class="roster-view-toggle"
              role="group"
              aria-label="Roster grid view"
            >
              <button
                type="button"
                class="button"
                :class="{ 'button--primary': viewMode === 'week' }"
                @click="setViewMode('week')"
              >
                Week
              </button>
              <button
                type="button"
                class="button"
                :class="{ 'button--primary': viewMode === 'month' }"
                @click="setViewMode('month')"
              >
                Full month
              </button>
            </div>
          </template>
        </RosterGrid>
      </template>
    </section>

    <AssignmentFormModal
      :show="showAssignmentModal"
      :workers="activeWorkers"
      :assignments="rostersStore.assignments"
      :assigned-hours-by-worker="rostersStore.assignedHoursByWorker"
      :shifts="referenceData.reference?.shifts ?? []"
      :roles="referenceData.reference?.roles"
      :initial-date="assignmentContext?.workDate"
      :min-date="monthRange?.startDate"
      :max-date="monthRange?.endDate"
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

.roster-view-toggle {
  display: inline-flex;
  gap: 0.375rem;
}

.check-field {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  min-height: 2.375rem;
  color: #334155;
  font-size: 0.875rem;
}

.check-field input {
  width: 1rem;
  height: 1rem;
  accent-color: #2563eb;
}
</style>
