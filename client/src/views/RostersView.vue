<script setup lang="ts">
import { onMounted } from 'vue'
import { useRostersStore } from '@/stores/rosters'
import { formatMonthYear } from '@/lib/rosterGrid'
import type { Roster } from '@/api/rosters'

const rostersStore = useRostersStore()

onMounted(async () => {
  await rostersStore.fetchRosters()
})

function periodLabel(roster: Roster): string {
  return formatMonthYear(roster.year, roster.month)
}

function statusClass(status: Roster['status']): string {
  if (status === 'published') {
    return 'badge--success'
  }

  return 'badge--muted'
}

async function publishRoster(roster: Roster) {
  if (!window.confirm(`Publish the ${periodLabel(roster)} roster? This cannot be undone.`)) {
    return
  }

  await rostersStore.publish(roster.id)
  await rostersStore.fetchRosters()
}

function deleteConfirmMessage(roster: Roster): string {
  const period = periodLabel(roster)

  if (roster.status === 'published') {
    return `Delete the published ${period} roster? This will remove the active schedule for this month.`
  }

  if (roster.status === 'superseded') {
    return `Delete the superseded ${period} roster? This cannot be undone.`
  }

  return `Delete the ${period} draft roster? This cannot be undone.`
}

async function deleteRoster(roster: Roster) {
  if (!window.confirm(deleteConfirmMessage(roster))) {
    return
  }

  await rostersStore.removeRoster(roster.id)
}
</script>

<template>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">Rosters</p>
        <h1 class="page__title">Roster Management</h1>
        <p class="page__description">
          Browse saved rosters, generate new drafts, and open the monthly grid for review.
        </p>
      </div>
      <div class="page__actions">
        <RouterLink class="button button--primary" :to="{ name: 'rosters.generate' }">
          Generate roster
        </RouterLink>
      </div>
    </header>

    <section class="panel">
      <div v-if="rostersStore.error" class="alert" role="alert">
        {{ rostersStore.error }}
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Period</th>
              <th>Status</th>
              <th>Assignments</th>
              <th>Generated</th>
              <th>Published</th>
              <th class="table__actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rostersStore.loading">
              <td colspan="6" class="table__empty">Loading rosters...</td>
            </tr>
            <tr v-else-if="rostersStore.rosters.length === 0">
              <td colspan="6" class="table__empty">No rosters found.</td>
            </tr>
            <template v-else>
              <tr v-for="roster in rostersStore.rosters" :key="roster.id">
                <td>
                  <strong>{{ periodLabel(roster) }}</strong>
                </td>
                <td>
                  <span class="badge" :class="statusClass(roster.status)">
                    {{ roster.status }}
                  </span>
                </td>
                <td>{{ roster.assignments_count ?? '-' }}</td>
                <td>{{ roster.generated_at ? new Date(roster.generated_at).toLocaleString() : '-' }}</td>
                <td>{{ roster.published_at ? new Date(roster.published_at).toLocaleString() : '-' }}</td>
                <td class="table__actions">
                  <RouterLink class="button" :to="{ name: 'rosters.show', params: { id: roster.id } }">
                    View
                  </RouterLink>
                  <button
                    v-if="roster.status === 'draft'"
                    type="button"
                    class="button button--primary"
                    :disabled="rostersStore.publishing"
                    @click="publishRoster(roster)"
                  >
                    Publish
                  </button>
                  <button
                    type="button"
                    class="button button--danger"
                    :disabled="rostersStore.deletingId === roster.id"
                    @click="deleteRoster(roster)"
                  >
                    {{ rostersStore.deletingId === roster.id ? 'Deleting...' : 'Delete' }}
                  </button>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</template>