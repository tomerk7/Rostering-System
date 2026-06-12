<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  /**
   * Column definitions: { key, label, numeric?, sortable?, formatter? }.
   */
  columns: {
    type: Array,
    required: true,
  },
  rows: {
    type: Array,
    required: true,
  },
  /**
   * Row property used as the v-for key.
   */
  rowKey: {
    type: String,
    required: true,
  },
  initialSort: {
    type: Object,
    default: null,
  },
  emptyText: {
    type: String,
    default: 'No rows to display.',
  },
})

const sortKey = ref(props.initialSort?.key ?? null)
const sortDirection = ref(props.initialSort?.direction ?? 'desc')

/**
 * Column definitions keyed by column key.
 *
 * @returns {Map<string, object>}
 */
const columnsByKey = computed(() => new Map(props.columns.map((column) => [column.key, column])))

/**
 * Rows sorted by the active column and direction.
 *
 * @returns {object[]}
 */
const sortedRows = computed(() => {
  if (!sortKey.value) {
    return props.rows
  }

  const column = columnsByKey.value.get(sortKey.value)
  const factor = sortDirection.value === 'asc' ? 1 : -1

  return [...props.rows].sort((left, right) => factor * compareValues(
    left[sortKey.value],
    right[sortKey.value],
    column?.numeric ?? false,
  ))
})

/**
 * Compare two cell values, sorting null/undefined last in any direction.
 *
 * @param {*} left
 * @param {*} right
 * @param {boolean} numeric
 * @returns {number}
 */
function compareValues(left, right, numeric) {
  if (left == null && right == null) {
    return 0
  }

  if (left == null) {
    return 1
  }

  if (right == null) {
    return -1
  }

  if (numeric) {
    return Number(left) - Number(right)
  }

  return String(left).localeCompare(String(right))
}

/**
 * Toggle the sort direction or activate a new sort column.
 *
 * @param {object} column
 * @returns {void}
 */
function toggleSort(column) {
  if (column.sortable === false) {
    return
  }

  if (sortKey.value === column.key) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    return
  }

  sortKey.value = column.key
  sortDirection.value = column.numeric ? 'desc' : 'asc'
}

/**
 * aria-sort value for a column header.
 *
 * @param {object} column
 * @returns {string}
 */
function ariaSort(column) {
  if (sortKey.value !== column.key) {
    return 'none'
  }

  return sortDirection.value === 'asc' ? 'ascending' : 'descending'
}

/**
 * Render a cell through the column formatter when provided.
 *
 * @param {object} column
 * @param {object} row
 * @returns {*}
 */
function cellValue(column, row) {
  return column.formatter ? column.formatter(row[column.key], row) : row[column.key]
}
</script>

<template>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th
            v-for="column in columns"
            :key="column.key"
            :aria-sort="ariaSort(column)"
          >
            <button
              v-if="column.sortable !== false"
              type="button"
              class="sortable-table__header"
              @click="toggleSort(column)"
            >
              {{ column.label }}
              <span
                class="sortable-table__indicator"
                aria-hidden="true"
              >
                {{ sortKey === column.key ? (sortDirection === 'asc' ? '▲' : '▼') : '' }}
              </span>
            </button>
            <template v-else>
              {{ column.label }}
            </template>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in sortedRows"
          :key="row[rowKey]"
        >
          <td
            v-for="column in columns"
            :key="column.key"
          >
            <slot
              :name="`cell-${column.key}`"
              :row="row"
              :value="row[column.key]"
            >
              {{ cellValue(column, row) }}
            </slot>
          </td>
        </tr>
        <tr v-if="!sortedRows.length">
          <td
            class="table__empty"
            :colspan="columns.length"
          >
            {{ emptyText }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
@import '@/assets/ui/table.css';

.sortable-table__header {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0;
  border: none;
  background: none;
  cursor: pointer;
  font: inherit;
  color: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
}

.sortable-table__indicator {
  min-width: 0.75rem;
  font-size: 0.625rem;
  color: #2563eb;
}
</style>
