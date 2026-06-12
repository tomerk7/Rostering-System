<script setup>
defineProps({
  /**
   * Leaderboards payload: { highest_paid, lowest_paid, most_hours, fewest_hours }.
   */
  leaderboards: {
    type: Object,
    required: true,
  },
})

const boards = [
  { key: 'highest_paid', title: 'Highest paid', field: 'total_cost', unit: 'currency' },
  { key: 'lowest_paid', title: 'Lowest paid', field: 'total_cost', unit: 'currency' },
  { key: 'most_hours', title: 'Most hours', field: 'actual_hours', unit: 'hours' },
  { key: 'fewest_hours', title: 'Fewest hours', field: 'actual_hours', unit: 'hours' },
]

const currencyFormat = new Intl.NumberFormat('en-US', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

/**
 * Format a leaderboard value for display.
 *
 * @param {object} board
 * @param {object} entry
 * @returns {string}
 */
function formatValue(board, entry) {
  const value = entry[board.field]

  return board.unit === 'currency' ? `₪${currencyFormat.format(value)}` : `${value}h`
}
</script>

<template>
  <div class="leaderboards">
    <section
      v-for="board in boards"
      :key="board.key"
      class="leaderboards__board"
    >
      <h3 class="leaderboards__title">
        {{ board.title }}
      </h3>
      <ol
        v-if="(leaderboards[board.key] ?? []).length"
        class="leaderboards__list"
      >
        <li
          v-for="entry in leaderboards[board.key]"
          :key="entry.worker_id"
          class="leaderboards__entry"
        >
          <span class="leaderboards__name">{{ entry.name }}</span>
          <span class="leaderboards__value">{{ formatValue(board, entry) }}</span>
        </li>
      </ol>
      <p
        v-else
        class="leaderboards__empty"
      >
        No data.
      </p>
    </section>
  </div>
</template>

<style scoped>
.leaderboards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  gap: 1rem;
}

.leaderboards__board {
  padding: 1rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  background: #f8fafc;
}

.leaderboards__title {
  margin: 0 0 0.75rem;
  font-size: 0.75rem;
  font-weight: 700;
  color: #475569;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.leaderboards__list {
  margin: 0;
  padding-left: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.leaderboards__entry {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: #334155;
}

.leaderboards__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.leaderboards__value {
  font-weight: 600;
  white-space: nowrap;
}

.leaderboards__empty {
  margin: 0;
  font-size: 0.875rem;
  color: #64748b;
}
</style>
