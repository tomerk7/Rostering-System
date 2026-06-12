<script setup>
import { computed, ref } from 'vue'
import { buildRosterGrid } from '@/lib/rosterGrid'
import { shiftLabel } from '@/lib/shifts'

const props = defineProps({
  startDate: { type: String, required: true },
  endDate: { type: String, required: true },
  shifts: { type: Array, required: true },
  requirements: { type: Array, required: true },
  roles: { type: Array, required: true },
  assignments: { type: Array, required: true },
  reports: { type: Object, required: true },
  workersById: { type: Map, required: true },
  editable: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  showNavigation: { type: Boolean, default: true },
  fullMonth: { type: Boolean, default: false },
  canGoPrevious: { type: Boolean, default: false },
  canGoNext: { type: Boolean, default: false },
})

const emit = defineEmits(['cell-click', 'remove-assignment', 'previous-week', 'next-week'])

const compact = ref(false)

const todayIso = (() => {
  const now = new Date()
  return [
    now.getFullYear(),
    String(now.getMonth() + 1).padStart(2, '0'),
    String(now.getDate()).padStart(2, '0'),
  ].join('-')
})()

/**
 * Grid model for the visible week range.
 *
 * @returns {object}
 */
const grid = computed(() =>
  buildRosterGrid({
    startDate: props.startDate,
    endDate: props.endDate,
    shifts: props.shifts,
    requirements: props.requirements,
    roles: props.roles,
    assignments: props.assignments,
    reports: props.reports,
    workersById: props.workersById,
  }),
)

/**
 * Shifts sorted by code for column headers.
 *
 * @returns {object[]}
 */
const sortedShifts = computed(() =>
  [...props.shifts].sort((left, right) => left.code.localeCompare(right.code)),
)

/**
 * Grid rows for the visible week range.
 *
 * @returns {object[]}
 */
const visibleRows = computed(() => (props.loading ? [] : grid.value.rows))

/**
 * Label for the visible roster period in hint text.
 *
 * @returns {string}
 */
const periodLabel = computed(() => (props.fullMonth ? 'this month' : 'this week'))

/**
 * Count of understaffed slots in the visible range.
 *
 * @returns {number}
 */
const issueCount = computed(() =>
  grid.value.rows.reduce(
    (total, row) => total + row.shifts.filter((cell) => cell.isUnderstaffed).length,
    0,
  ),
)

/**
 * Aggregate a cell's per-role demands into an overall coverage summary.
 *
 * Status follows the same per-role shortage rules as `cell.isUnderstaffed`.
 * Filled counts cap each role at its requirement so overstaffing in one role
 * cannot mask a shortage in another.
 *
 * @param {object} cell
 * @returns {{ filled: number, required: number, shortage: number, ratio: number, status: string }}
 */
function coverageOf(cell) {
  const required = cell.roles.reduce((sum, role) => sum + role.required, 0)
  const shortage = cell.roles.reduce((sum, role) => sum + role.shortage, 0)
  const filled = required - shortage
  const ratio = required === 0 ? 1 : Math.min(filled / required, 1)

  return {
    filled,
    required,
    shortage,
    ratio,
    status: statusOfCell(cell, filled, required),
  }
}

/**
 * Derive a coverage status from per-role shortages.
 *
 * @param {object} cell
 * @param {number} filled
 * @param {number} required
 * @returns {'none'|'full'|'empty'|'short'}
 */
function statusOfCell(cell, filled, required) {
  if (required === 0) {
    return 'none'
  }
  if (!cell.isUnderstaffed) {
    return 'full'
  }
  if (filled === 0) {
    return 'empty'
  }
  return 'short'
}

/**
 * Human-readable coverage label for a grid cell.
 *
 * @param {object} cell
 * @returns {string}
 */
function statusLabel(cell) {
  const { shortage, status } = coverageOf(cell)
  if (status === 'none') {
    return 'No demand'
  }
  if (status === 'full') {
    return 'Fully staffed'
  }
  return `Short ${shortage}`
}

/**
 * Build two-letter initials from a worker name.
 *
 * @param {string} name
 * @returns {string}
 */
function initials(name) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
}

/**
 * Build the visible worker label for a roster assignment.
 *
 * @param {{ workerName: string, roleName: string }} assignment
 * @returns {string}
 */
function workerLabel(assignment) {
  return `${assignment.workerName} - ${assignment.roleName}`
}

/**
 * Whether the given ISO date is today.
 *
 * @param {string} workDate
 * @returns {boolean}
 */
function isToday(workDate) {
  return workDate === todayIso
}

/**
 * Open the assignment modal prefilled from a grid cell click.
 *
 * @param {object} cell
 * @param {object} row
 * @returns {void}
 */
function onCellClick(cell, row) {
  if (!props.editable) {
    return
  }

  emit('cell-click', {
    workDate: row.workDate,
    shiftId: cell.shiftId,
    roleId: firstShortRoleId(cell),
  })
}

/**
 * First role id with a staffing shortage in the cell.
 *
 * @param {object} cell
 * @returns {number|undefined}
 */
function firstShortRoleId(cell) {
  return cell.roles.find((role) => role.shortage > 0)?.roleId
}
</script>

<template>
  <section class="roster-grid">
    <header class="roster-grid__header">
      <div class="roster-grid__heading">
        <h2 class="roster-grid__title">
          {{ grid.rangeLabel }}
        </h2>
        <p class="roster-grid__hint">
          Coverage by day and shift.
          <template v-if="issueCount">
            <strong class="roster-grid__hint-flag">{{ issueCount }}</strong>
            understaffed slot{{ issueCount === 1 ? '' : 's' }} {{ periodLabel }}.
          </template>
          <template v-else>
            All slots fully staffed.
          </template>
        </p>
      </div>

      <div class="roster-grid__controls">
        <slot name="view-toggle" />
        <nav
          v-if="showNavigation"
          class="roster-grid__navigation"
          aria-label="Roster weeks"
        >
          <button
            type="button"
            class="button"
            :disabled="loading || !canGoPrevious"
            @click="emit('previous-week')"
          >
            Previous
          </button>
          <button
            type="button"
            class="button"
            :disabled="loading || !canGoNext"
            @click="emit('next-week')"
          >
            Next
          </button>
        </nav>
      </div>
    </header>

    <div class="roster-grid__toolbar">
      <div
        class="roster-grid__legend"
        aria-hidden="true"
      >
        <span class="roster-grid__legend-item">
          <span class="roster-grid__swatch roster-grid__swatch--full" />Full
        </span>
        <span class="roster-grid__legend-item">
          <span class="roster-grid__swatch roster-grid__swatch--short" />Short
        </span>
        <span class="roster-grid__legend-item">
          <span class="roster-grid__swatch roster-grid__swatch--empty" />Empty
        </span>
      </div>

      <div class="roster-grid__filters">
        <label class="roster-grid__toggle">
          <input
            v-model="compact"
            type="checkbox"
          >
          Compact
        </label>
      </div>
    </div>

    <div
      class="roster-grid__table-wrap"
      :class="{ 'roster-grid__table-wrap--full-month': fullMonth }"
    >
      <table
        class="roster-grid__table"
        :class="{ 'roster-grid__table--compact': compact }"
      >
        <thead>
          <tr>
            <th class="roster-grid__date-col">
              Day
            </th>
            <th
              v-for="shift in sortedShifts"
              :key="shift.id"
              class="roster-grid__shift-col"
              scope="col"
            >
              <span class="roster-grid__shift-code">Shift {{ shift.code }}</span>
              <span class="roster-grid__shift-label">{{ shiftLabel(shift) }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td
              :colspan="sortedShifts.length + 1"
              class="roster-grid__loading"
            >
              Loading roster...
            </td>
          </tr>
          <tr v-else-if="!visibleRows.length">
            <td
              :colspan="sortedShifts.length + 1"
              class="roster-grid__loading"
            >
              No roster data for {{ periodLabel }}.
            </td>
          </tr>
          <tr
            v-for="row in visibleRows"
            :key="row.workDate"
          >
            <th
              scope="row"
              class="roster-grid__date-cell"
              :class="{ 'roster-grid__date-cell--today': isToday(row.workDate) }"
            >
              <span class="roster-grid__date-primary">{{ row.dayLabel }}</span>
              <span class="roster-grid__date-secondary">{{ row.workDate }}</span>
              <span
                v-if="isToday(row.workDate)"
                class="roster-grid__today-tag"
              >Today</span>
            </th>
            <td
              v-for="cell in row.shifts"
              :key="`${row.workDate}-${cell.shiftId}`"
              class="roster-grid__cell"
              :class="[`roster-grid__cell--${coverageOf(cell).status}`, {
                'roster-grid__cell--editable': editable,
              }]"
            >
              <div class="roster-grid__cell-inner">
                <div
                  class="roster-grid__coverage"
                  :class="`roster-grid__coverage--${coverageOf(cell).status}`"
                >
                  <span class="roster-grid__coverage-count">
                    {{ coverageOf(cell).filled }}/{{ coverageOf(cell).required }}
                  </span>
                  <span class="roster-grid__coverage-status">{{ statusLabel(cell) }}</span>
                  <span
                    class="roster-grid__meter"
                    :aria-label="`${coverageOf(cell).filled} of ${coverageOf(cell).required} filled`"
                  >
                    <span
                      class="roster-grid__meter-fill"
                      :style="{ width: `${coverageOf(cell).ratio * 100}%` }"
                    />
                  </span>
                </div>

                <ul class="roster-grid__roles">
                  <li
                    v-for="role in cell.roles"
                    :key="role.roleId"
                    class="roster-grid__role"
                    :class="{ 'roster-grid__role--short': role.shortage > 0 }"
                  >
                    <span class="roster-grid__role-name">{{ role.roleName }}</span>
                    <span class="roster-grid__role-count">{{ role.assigned }}/{{ role.required }}</span>
                  </li>
                </ul>

                <ul
                  v-if="cell.assignments.length"
                  class="roster-grid__assignments"
                >
                  <li
                    v-for="assignment in cell.assignments"
                    :key="`${assignment.workerId}-${assignment.assignmentId ?? assignment.workerName}`"
                    class="roster-grid__chip"
                    :class="`roster-grid__chip--${assignment.source}`"
                    :title="`${assignment.workerName} · ${assignment.roleName} · ${assignment.source}`"
                  >
                    <span class="roster-grid__avatar">{{ initials(assignment.workerName) }}</span>
                    <span class="roster-grid__worker">{{ workerLabel(assignment) }}</span>
                    <button
                      v-if="editable && assignment.assignmentId"
                      type="button"
                      class="roster-grid__remove"
                      title="Remove assignment"
                      aria-label="Remove assignment"
                      @click.stop="emit('remove-assignment', assignment.assignmentId)"
                    >
                      &times;
                    </button>
                  </li>
                </ul>

                <button
                  v-if="editable"
                  type="button"
                  class="roster-grid__add"
                  @click.stop="onCellClick(cell, row)"
                >
                  + Add worker
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
@import '@/assets/ui/button.css';

.roster-grid {
  --ok: #16a34a;
  --ok-bg: #f0fdf4;
  --ok-border: #bbf7d0;
  --warn: #d97706;
  --warn-bg: #fffbeb;
  --warn-border: #fde68a;
  --crit: #dc2626;
  --crit-bg: #fef2f2;
  --crit-border: #fecaca;
  --line: #e2e8f0;
  --muted: #64748b;

  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.roster-grid__title {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
}

.roster-grid__header,
.roster-grid__navigation,
.roster-grid__controls {
  display: flex;
  gap: 0.5rem;
}

.roster-grid__header {
  align-items: flex-end;
  justify-content: space-between;
}

.roster-grid__controls {
  align-items: center;
}

.roster-grid__hint {
  margin: 0.25rem 0 0;
  font-size: 0.8125rem;
  color: var(--muted);
}

.roster-grid__hint-flag {
  color: var(--crit);
  font-weight: 700;
}

/* Toolbar: legend + filters */
.roster-grid__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem 0.75rem;
  background: #f8fafc;
  border: 1px solid var(--line);
  border-radius: 0.5rem;
}

.roster-grid__legend,
.roster-grid__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1rem;
  align-items: center;
}

.roster-grid__legend-item {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 0.75rem;
  color: var(--muted);
}

.roster-grid__swatch {
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 0.25rem;
  border: 1px solid transparent;
}

.roster-grid__swatch--full {
  background: var(--ok-bg);
  border-color: var(--ok);
}

.roster-grid__swatch--short {
  background: var(--warn-bg);
  border-color: var(--warn);
}

.roster-grid__swatch--empty {
  background: var(--crit-bg);
  border-color: var(--crit);
}

.roster-grid__toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 0.8125rem;
  color: #334155;
  cursor: pointer;
}

/* Table shell */
.roster-grid__table-wrap {
  position: relative;
  max-height: 55vh;
  overflow: auto;
  border: 1px solid var(--line);
  border-radius: 0.75rem;
}

.roster-grid__table-wrap--full-month {
  max-height: 75vh;
}

.roster-grid__table {
  width: 100%;
  min-width: 1080px;
  border-collapse: separate;
  border-spacing: 0;
}

.roster-grid__table th,
.roster-grid__table td {
  padding: 0.5rem;
  vertical-align: top;
  border-bottom: 1px solid var(--line);
}

/* Sticky header row */
.roster-grid__table thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  font-size: 0.75rem;
  font-weight: 700;
  color: #475569;
  text-align: left;
  background: #f1f5f9;
  border-bottom: 1px solid #cbd5e1;
}

.roster-grid__shift-code {
  display: block;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.roster-grid__shift-label {
  display: block;
  margin-top: 0.125rem;
  font-size: 0.6875rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: none;
  letter-spacing: normal;
}

.roster-grid__date-col {
  width: 9rem;
}

.roster-grid__shift-col {
  min-width: 15rem;
}

/* Frozen first column */
.roster-grid__date-col,
.roster-grid__date-cell {
  position: sticky;
  left: 0;
  z-index: 1;
  background: #f8fafc;
}

.roster-grid__date-col {
  z-index: 3;
}

.roster-grid__date-cell {
  text-align: left;
  border-right: 1px solid var(--line);
}

.roster-grid__date-cell--today {
  box-shadow: inset 3px 0 0 #2563eb;
}

.roster-grid__date-primary {
  display: block;
  font-size: 0.875rem;
  font-weight: 700;
}

.roster-grid__date-secondary {
  display: block;
  margin-top: 0.125rem;
  font-size: 0.75rem;
  color: var(--muted);
}

.roster-grid__today-tag {
  display: inline-block;
  margin-top: 0.375rem;
  padding: 0.0625rem 0.375rem;
  font-size: 0.625rem;
  font-weight: 700;
  color: #fff;
  background: #2563eb;
  border-radius: 999px;
}

/* Cells + semantic status accent */
.roster-grid__cell {
  background: #fff;
}

.roster-grid__cell--short {
  background: var(--warn-bg);
  box-shadow: inset 4px 0 0 var(--warn);
}

.roster-grid__cell--empty {
  background: var(--crit-bg);
  box-shadow: inset 4px 0 0 var(--crit);
}

.roster-grid__cell-inner {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

/* Coverage summary */
.roster-grid__coverage {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.125rem 0.5rem;
  align-items: baseline;
}

.roster-grid__coverage-count {
  font-size: 0.9375rem;
  font-weight: 800;
  color: #0f172a;
}

.roster-grid__coverage-status {
  justify-self: end;
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.roster-grid__coverage--full .roster-grid__coverage-status {
  color: var(--ok);
}

.roster-grid__coverage--short .roster-grid__coverage-status {
  color: var(--warn);
}

.roster-grid__coverage--empty .roster-grid__coverage-status {
  color: var(--crit);
}

.roster-grid__meter {
  grid-column: 1 / -1;
  height: 0.3125rem;
  margin-top: 0.125rem;
  overflow: hidden;
  background: #e2e8f0;
  border-radius: 999px;
}

.roster-grid__meter-fill {
  display: block;
  height: 100%;
  background: var(--ok);
  border-radius: 999px;
  transition: width 0.2s ease;
}

.roster-grid__coverage--short .roster-grid__meter-fill {
  background: var(--warn);
}

.roster-grid__coverage--empty .roster-grid__meter-fill {
  background: var(--crit);
}

/* Per-role demand list */
.roster-grid__roles,
.roster-grid__assignments {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.roster-grid__role {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.75rem;
  color: #475569;
}

.roster-grid__role-count {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.roster-grid__role--short {
  font-weight: 700;
  color: var(--warn);
}

/* Assignment chips */
.roster-grid__chip {
  display: flex;
  gap: 0.375rem;
  align-items: center;
  padding: 0.1875rem 0.375rem;
  font-size: 0.8125rem;
  background: #f1f5f9;
  border: 1px solid var(--line);
  border-radius: 999px;
}

.roster-grid__chip--manual {
  background: #eff6ff;
  border-color: #bfdbfe;
}

.roster-grid__avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.25rem;
  height: 1.25rem;
  font-size: 0.625rem;
  font-weight: 700;
  color: #1e293b;
  background: #cbd5e1;
  border-radius: 999px;
  flex-shrink: 0;
}

.roster-grid__chip--manual .roster-grid__avatar {
  color: #fff;
  background: #2563eb;
}

.roster-grid__worker {
  font-weight: 600;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.roster-grid__remove {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.125rem;
  height: 1.125rem;
  margin-left: auto;
  padding: 0;
  font-size: 0.875rem;
  line-height: 1;
  color: #94a3b8;
  background: transparent;
  border: none;
  border-radius: 999px;
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.15s ease, background 0.15s ease, color 0.15s ease;
}

.roster-grid__chip:hover .roster-grid__remove {
  opacity: 1;
}

.roster-grid__remove:hover {
  color: var(--crit);
  background: var(--crit-bg);
}

/* Add affordance */
.roster-grid__add {
  align-self: flex-start;
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #2563eb;
  background: transparent;
  border: 1px dashed #bfdbfe;
  border-radius: 0.375rem;
  cursor: pointer;
  opacity: 0.55;
  transition: opacity 0.15s ease, background 0.15s ease;
}

.roster-grid__cell:hover .roster-grid__add {
  opacity: 1;
}

.roster-grid__add:hover {
  background: #eff6ff;
}

.roster-grid__loading {
  padding: 2rem;
  color: var(--muted);
  text-align: center;
}

/* Compact density */
.roster-grid__table--compact th,
.roster-grid__table--compact td {
  padding: 0.375rem 0.5rem;
}

.roster-grid__table--compact .roster-grid__roles {
  display: none;
}

.roster-grid__table--compact .roster-grid__cell-inner {
  gap: 0.375rem;
}

@media (max-width: 640px) {
  .roster-grid__header {
    align-items: stretch;
    flex-direction: column;
  }

  .roster-grid__date-col,
  .roster-grid__date-cell {
    position: static;
  }
}
</style>
