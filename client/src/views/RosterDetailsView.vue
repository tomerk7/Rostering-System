<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRostersStore } from '@/stores/rosters'
import { useRosterReference } from '@/composables/useRosterReference'
import RosterAlertSummary from '@/components/rosters/RosterAlertSummary.vue'
import RosterGrid from '@/components/rosters/RosterGrid.vue'
import { formatMonthYear } from '@/lib/rosterGrid'

const route = useRoute()
const router = useRouter()
const rostersStore = useRostersStore()
const referenceData = useRosterReference()

const rosterId = computed(() => Number(route.params.id))

const periodLabel = computed(() => {
  if (!rostersStore.roster) {
    return ''
  }

  return formatMonthYear(rostersStore.roster.year, rostersStore.roster.month)
})

const isDraft = computed(() => rostersStore.roster?.status === 'draft')

onMounted(async () => {
  await Promise.all([referenceData.load(), rostersStore.fetchRoster(rosterId.value)])
})

watch(rosterId, async (id) => {
  if (!Number.isFinite(id)) {
    return
  }

  await rostersStore.fetchRoster(id)
})

async function publishRoster() {
  if (!rostersStore.roster) {
    return
  }

  if (!window.confirm(`Publish the ${periodLabel.value} roster? This cannot be undone.`)) {
    return
  }

  await rostersStore.publish(rostersStore.roster.id)
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
          class="button button--primary"
          :disabled="rostersStore.publishing"
          @click="publishRoster"
        >
          {{ rostersStore.publishing ? 'Publishing...' : 'Publish' }}
        </button>
      </div>
    </header>

    <section class="panel">
      <div v-if="rostersStore.error" class="alert" role="alert">
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
        />
      </template>
    </section>
  </main>
</template>