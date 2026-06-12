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
      <div class="roster-alerts__header-text">
        <h2 class="roster-alerts__title">
          Hour shortfalls
        </h2>
        <p class="roster-alerts__meta">
          {{ hoursCount }} worker{{ hoursCount === 1 ? '' : 's' }} below minimum hours
        </p>
      </div>
      <span
        class="roster-alerts__chevron"
        :class="{ 'roster-alerts__chevron--expanded': isExpanded }"
        aria-hidden="true"
      />
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
          class="roster-alerts__item"
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
  margin-bottom: 0.75rem;
  padding: 0.75rem;
  background: #fffbeb;
  border: 1px solid #fcd34d;
  border-radius: 0.625rem;
  box-shadow: 0 1px 2px rgb(15 23 42 / 4%);
}

.roster-alerts__header {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0;
  font: inherit;
  color: inherit;
  text-align: left;
  cursor: pointer;
  background: none;
  border: none;
}

.roster-alerts__header-text {
  min-width: 0;
}

.roster-alerts__title {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 700;
  color: #92400e;
}

.roster-alerts__meta {
  margin: 0.125rem 0 0;
  font-size: 0.8125rem;
  color: #b45309;
}

.roster-alerts__chevron {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  background: #fef3c7;
  border: 1px solid #fde68a;
  border-radius: 999px;
  transition: transform 0.15s ease, background 0.15s ease;
}

.roster-alerts__chevron::before {
  content: '';
  width: 0.375rem;
  height: 0.375rem;
  border-right: 2px solid #b45309;
  border-bottom: 2px solid #b45309;
  transform: rotate(-45deg) translate(-1px, -1px);
  transition: transform 0.15s ease;
}

.roster-alerts__chevron--expanded::before {
  transform: rotate(45deg) translate(-1px, -1px);
}

.roster-alerts__header:hover .roster-alerts__chevron {
  background: #fde68a;
}

.roster-alerts__scroll {
  max-height: 8rem;
  margin-top: 0.625rem;
  padding-top: 0.625rem;
  overflow-y: auto;
  border-top: 1px solid #fde68a;
}

.roster-alerts__list {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.roster-alerts__item {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.75rem;
  align-items: baseline;
  justify-content: space-between;
  padding: 0.5rem 0.625rem;
  font-size: 0.8125rem;
  background: #fff;
  border: 1px solid #fde68a;
  border-radius: 0.5rem;
}

.roster-alerts__item-main {
  font-weight: 600;
  color: #78350f;
}

.roster-alerts__item-meta {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  color: #b45309;
}
</style>
