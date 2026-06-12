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

<style scoped>
.roster-alerts {
  padding: 0.625rem 0.75rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
}

.roster-alerts--warning {
  border-color: #fdba74;
  background: #fffaf5;
}

.roster-alerts__header {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.75rem;
  align-items: baseline;
  width: 100%;
  padding: 0;
  font: inherit;
  color: inherit;
  text-align: left;
  cursor: default;
  background: none;
  border: none;
}

.roster-alerts--warning .roster-alerts__header {
  cursor: pointer;
}

.roster-alerts__title {
  margin: 0;
  font-size: 0.9375rem;
}

.roster-alerts__meta {
  margin: 0;
  font-size: 0.75rem;
  color: #64748b;
}

.roster-alerts__chevron {
  display: inline-block;
  margin-left: 0.25rem;
  font-size: 0.625rem;
  transition: transform 0.15s ease;
}

.roster-alerts__chevron--expanded {
  transform: rotate(90deg);
}

.roster-alerts__scroll {
  max-height: 10rem;
  margin-top: 0.5rem;
  padding-top: 0.5rem;
  overflow-y: auto;
  border-top: 1px solid #e2e8f0;
}

.roster-alerts__list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.roster-alerts__item {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.5rem;
  align-items: baseline;
  justify-content: space-between;
  padding: 0.375rem 0.5rem;
  font-size: 0.75rem;
  border-radius: 0.375rem;
}

.roster-alerts__item-main {
  color: #334155;
}

.roster-alerts__item-meta {
  font-weight: 600;
  white-space: nowrap;
}

.roster-alerts__item--info {
  background: #dbeafe;
}

.roster-alerts__item--info .roster-alerts__item-meta {
  color: #1e40af;
}
</style>
