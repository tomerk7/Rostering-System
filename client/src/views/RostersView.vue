<script setup>
import { onMounted } from 'vue'
import { useRostersStore } from '@/stores/rosters'
import { formatMonthYear } from '@/lib/rosterGrid'
import Button from '@/components/ui/Button.vue'
import Table from '@/components/ui/Table.vue'

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
        <Button
          variant="primary"
          :to="{ name: 'rosters.generate' }"
        >
          Generate roster
        </Button>
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

      <Table>
        <thead>
          <tr>
            <th>Period</th>
            <th>Assignments</th>
            <th>Generated</th>
            <th class="table__actions">
              Actions
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rostersStore.loading">
            <td
              colspan="4"
              class="table__empty"
            >
              Loading rosters...
            </td>
          </tr>
          <tr v-else-if="rostersStore.rosters.length === 0">
            <td
              colspan="4"
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
              <td>{{ roster.assignments_count ?? '-' }}</td>
              <td>{{ roster.generated_at ? new Date(roster.generated_at).toLocaleString() : '-' }}</td>
              <td class="table__actions">
                <Button :to="{ name: 'rosters.show', params: { id: roster.id } }">
                  View
                </Button>
                <Button
                  variant="danger"
                  :disabled="rostersStore.deletingId === roster.id"
                  @click="deleteRoster(roster)"
                >
                  {{ rostersStore.deletingId === roster.id ? 'Deleting...' : 'Delete' }}
                </Button>
              </td>
            </tr>
          </template>
        </tbody>
      </Table>
    </section>
  </main>
</template>

<style scoped>
@import '@/assets/ui/page.css';
</style>