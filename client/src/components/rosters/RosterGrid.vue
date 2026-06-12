<script setup>
import { computed } from 'vue'
import { buildRosterGrid, formatDemandSummary } from '@/lib/rosterGrid'

const props = defineProps({
  year: { type: Number, required: true },
  month: { type: Number, required: true },
  shifts: { type: Array, required: true },
  requirements: { type: Array, required: true },
  roles: { type: Array, required: true },
  assignments: { type: Array, required: true },
  reports: { type: Object, required: true },
  workersById: { type: Map, required: true },
  editable: { type: Boolean, default: false },
})

const emit = defineEmits(['cell-click', 'remove-assignment'])

const grid = computed(() =>
  buildRosterGrid({
    year: props.year,
    month: props.month,
    shifts: props.shifts,
    requirements: props.requirements,
    roles: props.roles,
    assignments: props.assignments,
    reports: props.reports,
    workersById: props.workersById,
  }),
)

function onCellClick(workDate, shiftId, roleId) {
  if (!props.editable) {
    return
  }

  emit('cell-click', { workDate, shiftId, roleId })
}

function firstShortRoleId(cell) {
  return cell.roles.find((role) => role.shortage > 0)?.roleId
}
</script>

<template>
  <section class="roster-grid">
    <header>
      <h2 class="roster-grid__title">
        {{ grid.monthLabel }}
      </h2>
      <p class="roster-grid__hint">
        Daily assignments by shift. Understaffed cells are highlighted.
        <template v-if="editable">
          Click any cell to add a worker.
        </template>
      </p>
    </header>

    <div class="roster-grid__table-wrap">
      <table class="roster-grid__table">
        <thead>
          <tr>
            <th class="roster-grid__date-col">
              Date
            </th>
            <th
              v-for="shift in shifts"
              :key="shift.id"
              class="roster-grid__shift-col"
            >
              Shift {{ shift.code }}
              <span class="roster-grid__shift-label">{{ shift.label }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in grid.rows"
            :key="row.workDate"
          >
            <td class="roster-grid__date-cell">
              <span class="roster-grid__date-primary">{{ row.dayLabel }}</span>
              <span class="roster-grid__date-secondary">{{ row.workDate }}</span>
            </td>
            <td
              v-for="cell in row.shifts"
              :key="`${row.workDate}-${cell.shiftId}`"
              class="roster-grid__cell"
              :class="{
                'roster-grid__cell--understaffed': cell.isUnderstaffed,
                'roster-grid__cell--editable': editable,
              }"
              :title="editable ? 'Click to add assignment' : undefined"
              @click="onCellClick(row.workDate, cell.shiftId, firstShortRoleId(cell))"
            >
              <div class="roster-grid__demand">
                <span class="roster-grid__demand-label">Need</span>
                <span class="roster-grid__demand-value">{{ formatDemandSummary(cell.roles) }}</span>
              </div>

              <ul class="roster-grid__roles">
                <li
                  v-for="role in cell.roles"
                  :key="role.roleId"
                  class="roster-grid__role"
                  :class="{ 'roster-grid__role--short': role.shortage > 0 }"
                >
                  <span>{{ role.roleName }}</span>
                  <span>{{ role.assigned }}/{{ role.required }}</span>
                </li>
              </ul>

              <ul
                v-if="cell.assignments.length"
                class="roster-grid__assignments"
              >
                <li
                  v-for="assignment in cell.assignments"
                  :key="`${assignment.workerId}-${assignment.assignmentId ?? assignment.workerName}`"
                  class="roster-grid__assignment"
                >
                  <span class="roster-grid__worker">{{ assignment.workerName }}</span>
                  <span class="roster-grid__role-badge">{{ assignment.roleCode }}</span>
                  <span class="roster-grid__source-badge">{{ assignment.source }}</span>
                  <button
                    v-if="editable && assignment.assignmentId"
                    type="button"
                    class="roster-grid__remove"
                    title="Remove assignment"
                    @click.stop="emit('remove-assignment', assignment.assignmentId)"
                  >
                    Remove
                  </button>
                </li>
              </ul>
              <p
                v-else
                class="roster-grid__empty-slot"
              >
                No assignments
              </p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.roster-grid {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.roster-grid__title {
  margin: 0;
  font-size: 0.9375rem;
}

.roster-grid__hint {
  margin: 0;
  font-size: 0.75rem;
  color: #64748b;
}

.roster-grid__table-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
}

.roster-grid__table {
  width: 100%;
  min-width: 1080px;
  border-collapse: collapse;
}

.roster-grid__table th,
.roster-grid__table td {
  padding: 0.75rem;
  vertical-align: top;
  border-bottom: 1px solid #e2e8f0;
}

.roster-grid__table thead th {
  font-size: 0.75rem;
  font-weight: 700;
  color: #475569;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  background: #f8fafc;
}

.roster-grid__date-col {
  width: 9rem;
}

.roster-grid__shift-col {
  min-width: 16rem;
}

.roster-grid__shift-label {
  display: block;
  margin-top: 0.125rem;
  font-size: 0.6875rem;
  font-weight: 600;
  color: #64748b;
  text-transform: none;
  letter-spacing: normal;
}

.roster-grid__date-cell {
  background: #f8fafc;
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
  color: #64748b;
}

.roster-grid__cell {
  background: #fff;
}

.roster-grid__cell--understaffed {
  background: #fff7ed;
  box-shadow: inset 0 0 0 1px #fdba74;
}

.roster-grid__cell--editable {
  cursor: pointer;
}

.roster-grid__cell--editable:hover {
  background: #ffedd5;
}

.roster-grid__demand {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
  align-items: baseline;
  margin-bottom: 0.5rem;
}

.roster-grid__demand-label {
  font-size: 0.6875rem;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.roster-grid__demand-value {
  font-size: 0.8125rem;
  font-weight: 700;
  color: #334155;
}

.roster-grid__roles,
.roster-grid__assignments {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.roster-grid__roles {
  margin-bottom: 0.5rem;
}

.roster-grid__role {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.75rem;
  color: #475569;
}

.roster-grid__role--short {
  font-weight: 700;
  color: #c2410c;
}

.roster-grid__assignment {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
  align-items: center;
  font-size: 0.8125rem;
}

.roster-grid__worker {
  font-weight: 600;
  color: #0f172a;
}

.roster-grid__role-badge,
.roster-grid__source-badge {
  font-size: 0.6875rem;
}

.roster-grid__remove {
  margin-left: auto;
  padding: 0.125rem 0.375rem;
  font-size: 0.6875rem;
  color: #b91c1c;
  background: transparent;
  border: 1px solid #fecaca;
  border-radius: 0.375rem;
  cursor: pointer;
}

.roster-grid__remove:hover {
  background: #fef2f2;
}

.roster-grid__empty-slot {
  margin: 0;
  font-size: 0.75rem;
  color: #94a3b8;
}
</style>
