<script setup lang="ts">
import { computed } from 'vue'
import type { RosterAssignment, RosterPreviewAssignment, RosterReports } from '@/api/rosters'
import type { ReferenceRole, ShiftRoleRequirement, Worker, WorkerShift } from '@/api/workers'
import { buildRosterGrid, formatDemandSummary } from '@/lib/rosterGrid'

const props = defineProps<{
  year: number
  month: number
  shifts: WorkerShift[]
  requirements: ShiftRoleRequirement[]
  roles: ReferenceRole[]
  assignments: RosterAssignment[] | RosterPreviewAssignment[]
  reports: RosterReports
  workersById: Map<number, Worker>
}>()

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
</script>

<template>
  <section class="roster-grid">
    <header>
      <h2 class="roster-grid__title">{{ grid.monthLabel }}</h2>
      <p class="roster-grid__hint">Daily assignments by shift. Understaffed cells are highlighted.</p>
    </header>

    <div class="roster-grid__table-wrap">
      <table class="roster-grid__table">
        <thead>
          <tr>
            <th class="roster-grid__date-col">Date</th>
            <th v-for="shift in shifts" :key="shift.id" class="roster-grid__shift-col">
              Shift {{ shift.code }}
              <span class="roster-grid__shift-label">{{ shift.label }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in grid.rows" :key="row.workDate">
            <td class="roster-grid__date-cell">
              <span class="roster-grid__date-primary">{{ row.dayLabel }}</span>
              <span class="roster-grid__date-secondary">{{ row.workDate }}</span>
            </td>
            <td
              v-for="cell in row.shifts"
              :key="`${row.workDate}-${cell.shiftId}`"
              class="roster-grid__cell"
              :class="{ 'roster-grid__cell--understaffed': cell.isUnderstaffed }"
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

              <ul v-if="cell.assignments.length" class="roster-grid__assignments">
                <li
                  v-for="assignment in cell.assignments"
                  :key="`${assignment.workerId}-${assignment.assignmentId ?? assignment.workerName}`"
                  class="roster-grid__assignment"
                >
                  <span class="roster-grid__worker">{{ assignment.workerName }}</span>
                  <span class="roster-grid__role-badge">{{ assignment.roleCode }}</span>
                  <span class="roster-grid__source-badge">{{ assignment.source }}</span>
                </li>
              </ul>
              <p v-else class="roster-grid__empty-slot">No assignments</p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
