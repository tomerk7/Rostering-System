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
          Click an understaffed cell to add a worker.
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
                'roster-grid__cell--editable': editable && cell.isUnderstaffed,
              }"
              :title="editable && cell.isUnderstaffed ? 'Click to add assignment' : undefined"
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
