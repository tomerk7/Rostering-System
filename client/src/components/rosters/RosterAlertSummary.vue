<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  reports: { type: Object, required: true },
  workersById: { type: Map, required: true },
})

const hoursCount = computed(() => props.reports.hours_shortfalls.length)
const hasAlerts = computed(() => hoursCount.value > 0)

const isExpanded = ref(true)

watch(
  hasAlerts,
  (value) => {
    if (value) {
      isExpanded.value = true
    }
  },
  { immediate: true },
)

const sortedHoursShortfalls = computed(() => {
  return [...props.reports.hours_shortfalls].sort(
    (left, right) => shortfallGap(right) - shortfallGap(left),
  )
})

function workerName(shortfall) {
  return shortfall.worker_name
    ?? props.workersById.get(shortfall.worker_id)?.full_name
    ?? `Worker #${shortfall.worker_id}`
}

function shortfallGap(shortfall) {
  return shortfall.shortfall_hours ?? Math.max(shortfall.min_hours - shortfall.scheduled_hours, 0)
}
</script>

<template>
  <section
    v-if="hasAlerts"
    class="roster-alerts roster-alerts--warning"
  >
    <button
      type="button"
      class="roster-alerts__header"
      :aria-expanded="isExpanded"
      aria-controls="roster-alerts-body"
      @click="isExpanded = !isExpanded"
    >
      <h2 class="roster-alerts__title">
        Hour shortfalls
      </h2>
      <p class="roster-alerts__meta">
        {{ hoursCount }} worker{{ hoursCount === 1 ? '' : 's' }} below minimum hours
        <span
          class="roster-alerts__chevron"
          :class="{ 'roster-alerts__chevron--expanded': isExpanded }"
          aria-hidden="true"
        >▸</span>
      </p>
    </button>

    <div
      v-if="isExpanded"
      id="roster-alerts-body"
      class="roster-alerts__scroll"
    >
      <ul class="roster-alerts__list">
        <li
          v-for="shortfall in sortedHoursShortfalls"
          :key="shortfall.worker_id"
          class="roster-alerts__item roster-alerts__item--info"
        >
          <span class="roster-alerts__item-main">{{ workerName(shortfall) }}</span>
          <span class="roster-alerts__item-meta">
            {{ shortfall.scheduled_hours }}h / {{ shortfall.min_hours }}h · −{{ shortfallGap(shortfall) }}h
          </span>
        </li>
      </ul>
    </div>
  </section>
</template>
