<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { CoverageShortage, HoursShortfall, RosterReports, RosterSummary } from '@/api/rosters'
import type { ReferenceRole, Worker, WorkerShift } from '@/api/workers'
import { formatWorkDateLabel } from '@/lib/rosterGrid'

const props = defineProps<{
  summary: RosterSummary | null
  reports: RosterReports
  workersById: Map<number, Worker>
  shifts?: WorkerShift[]
  roles?: ReferenceRole[]
}>()

const shiftsById = computed(() => new Map((props.shifts ?? []).map((shift) => [shift.id, shift])))
const rolesById = computed(() => new Map((props.roles ?? []).map((role) => [role.id, role])))

const hasAlerts = computed(
  () => props.reports.coverage_shortages.length > 0 || props.reports.hours_shortfalls.length > 0,
)

const isExpanded = ref(false)

watch(
  hasAlerts,
  (value) => {
    isExpanded.value = value
  },
  { immediate: true },
)

function workerName(shortfall: HoursShortfall): string {
  return props.workersById.get(shortfall.worker_id)?.full_name ?? `Worker #${shortfall.worker_id}`
}

function shortageLabel(shortage: CoverageShortage): string {
  const shift = shiftsById.value.get(shortage.shift_id)
  const role = rolesById.value.get(shortage.role_id)

  return `${formatWorkDateLabel(shortage.work_date)} · Shift ${shift?.code ?? shortage.shift_id} · ${role?.name ?? `Role #${shortage.role_id}`}`
}
</script>

<template>
  <section class="roster-alerts">
    <header class="roster-alerts__header">
      <button
        type="button"
        class="roster-alerts__toggle"
        :aria-expanded="isExpanded"
        aria-controls="roster-alerts-body"
        @click="isExpanded = !isExpanded"
      >
        <span
          class="roster-alerts__chevron"
          :class="{ 'roster-alerts__chevron--expanded': isExpanded }"
          aria-hidden="true"
        >
          ▸
        </span>
        <h2 class="roster-alerts__title">Roster alerts</h2>
      </button>
      <p v-if="summary" class="roster-alerts__meta">
        {{ summary.assignment_count }} assignments ·
        {{ summary.coverage_shortage_count }} coverage gaps ·
        {{ summary.hours_shortfall_count }} hour shortfalls
      </p>
    </header>

    <div v-show="isExpanded" id="roster-alerts-body" class="roster-alerts__body">
      <p v-if="!hasAlerts" class="roster-alerts__ok">
        No coverage shortages or minimum-hour shortfalls detected.
      </p>

      <div v-else class="roster-alerts__groups">
      <div v-if="reports.coverage_shortages.length" class="roster-alerts__group">
        <h3 class="roster-alerts__group-title">
          Coverage shortages ({{ reports.coverage_shortages.length }})
        </h3>
        <ul class="roster-alerts__list">
          <li
            v-for="(shortage, index) in reports.coverage_shortages"
            :key="`${shortage.work_date}-${shortage.shift_id}-${shortage.role_id}-${index}`"
            class="roster-alerts__item roster-alerts__item--warning"
          >
            {{ shortageLabel(shortage) }}: {{ shortage.assigned }}/{{ shortage.required }} assigned
          </li>
        </ul>
      </div>

      <div v-if="reports.hours_shortfalls.length" class="roster-alerts__group">
        <h3 class="roster-alerts__group-title">
          Minimum-hour shortfalls ({{ reports.hours_shortfalls.length }})
        </h3>
        <ul class="roster-alerts__list">
          <li
            v-for="shortfall in reports.hours_shortfalls"
            :key="shortfall.worker_id"
            class="roster-alerts__item roster-alerts__item--info"
          >
            {{ workerName(shortfall) }}: {{ shortfall.scheduled_hours }}h scheduled,
            {{ shortfall.min_hours }}h minimum
          </li>
        </ul>
      </div>
    </div>
    </div>
  </section>
</template>