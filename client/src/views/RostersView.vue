<script setup>
import { onMounted } from 'vue'
import { useRostersStore } from '@/stores/rosters'
import { formatMonthYear } from '@/lib/rosterGrid'

const rostersStore = useRostersStore()

onMounted(async () => {
  await rostersStore.fetchRosters()
})

function periodLabel(roster) {
  return formatMonthYear(roster.year, roster.month)
}

function deleteConfirmMessage(roster) {
  return `Delete the ${periodLabel(roster)} roster? This will remove the schedule for this month.`
}

async function deleteRoster(roster) {
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
        <p class="page__eyebrow">
          Rosters
        </p>
        <h1 class="page__title">
          Roster Management
        </h1>
        <p class="page__description">
          Browse saved rosters, generate new ones, and open the monthly grid for review.
        </p>
      </div>
      <div class="page__actions">
        <RouterLink
          class="button button--primary"
          :to="{ name: 'rosters.generate' }"
        >
          Generate roster
        </RouterLink>
      </div>
    </header>

    <section class="panel">
      <div
        v-if="rostersStore.error"
        class="alert"
        role="alert"
      >
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
              <th class="table__actions">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rostersStore.loading">
              <td
                colspan="6"
                class="table__empty"
              >
                Loading rosters...
              </td>
            </tr>
            <tr v-else-if="rostersStore.rosters.length === 0">
              <td
                colspan="6"
                class="table__empty"
              >
                No rosters found.
              </td>
            </tr>
            <template v-else>
              <tr
                v-for="roster in rostersStore.rosters"
                :key="roster.id"
              >
                <td>
                  <strong>{{ periodLabel(roster) }}</strong>
                </td>
                <td>
                  <span class="badge badge--success">
                    {{ roster.status }}
                  </span>
                </td>
                <td>{{ roster.assignments_count ?? '-' }}</td>
                <td>{{ roster.generated_at ? new Date(roster.generated_at).toLocaleString() : '-' }}</td>
                <td>{{ roster.published_at ? new Date(roster.published_at).toLocaleString() : '-' }}</td>
                <td class="table__actions">
                  <RouterLink
                    class="button"
                    :to="{ name: 'rosters.show', params: { id: roster.id } }"
                  >
                    View
                  </RouterLink>
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