<script setup>
import { computed, ref, watch } from 'vue'
import { downloadWorkersSample, importWorkers } from '@/api/workers'
import { isAxiosError } from 'axios'
import Button from '@/components/ui/Button.vue'
import Field from '@/components/ui/Field.vue'

const props = defineProps({
  show: { type: Boolean, required: true },
})

const emit = defineEmits(['close', 'imported'])

const importFile = ref(null)
const importing = ref(false)
const importError = ref('')
const importSummary = ref(null)
const importErrors = ref([])
const downloadingSample = ref(false)
const showGuide = ref(false)

const hasResult = computed(
  () => importSummary.value !== null || importErrors.value.length > 0,
)

const importFixedColumns = [
  { key: 'full_name', label: 'full_name', hint: 'Worker name' },
  { key: 'israeli_id', label: 'israeli_id', hint: '9-digit ID' },
  { key: 'role', label: 'role', hint: 'General Guard, Supervisor, or Screener' },
  { key: 'status', label: 'status', hint: 'Active or Inactive' },
  { key: 'hourly_cost', label: 'hourly_cost', hint: 'e.g. 52.50' },
  { key: 'min_monthly_hours', label: 'min_monthly_hours', hint: 'Contract minimum' },
  { key: 'max_monthly_hours', label: 'max_monthly_hours', hint: 'Contract maximum' },
]

const importShiftColumns = [
  { key: '00:00-08:00', label: '00:00-08:00', hint: 'Morning shift' },
  { key: '08:00-16:00', label: '08:00-16:00', hint: 'Day shift' },
  { key: '16:00-00:00', label: '16:00-00:00', hint: 'Evening shift' },
]

const importDayLegend = [
  { day: 1, label: 'Sun' },
  { day: 2, label: 'Mon' },
  { day: 3, label: 'Tue' },
  { day: 4, label: 'Wed' },
  { day: 5, label: 'Thu' },
  { day: 6, label: 'Fri' },
  { day: 7, label: 'Sat' },
]

const importExpressionExamples = [
  { expression: '2-6', meaning: 'Monday through Friday' },
  { expression: '1|7', meaning: 'Sunday or Saturday' },
  { expression: '1-7', meaning: 'Every day of the week' },
  { expression: '1|3|5', meaning: 'Sunday, Tuesday, and Thursday' },
  { expression: '(empty)', meaning: 'Worker is unavailable for that shift' },
]

const importExampleRows = [
  {
    full_name: 'Dana Cohen',
    israeli_id: '234567816',
    role: 'Supervisor',
    status: 'Active',
    hourly_cost: '75.00',
    min_monthly_hours: '120',
    max_monthly_hours: '180',
    '00:00-08:00': '1-4',
    '08:00-16:00': '1-4',
    '16:00-00:00': '',
  },
  {
    full_name: 'Yossi Levi',
    israeli_id: '314159260',
    role: 'General Guard',
    status: 'Active',
    hourly_cost: '52.50',
    min_monthly_hours: '80',
    max_monthly_hours: '160',
    '00:00-08:00': '1-7',
    '08:00-16:00': '1-7',
    '16:00-00:00': '1-7',
  },
  {
    full_name: 'Maya Bar',
    israeli_id: '271828188',
    role: 'Screener',
    status: 'Inactive',
    hourly_cost: '60.00',
    min_monthly_hours: '0',
    max_monthly_hours: '120',
    '00:00-08:00': '',
    '08:00-16:00': '2|4|6',
    '16:00-00:00': '',
  },
]

function resetState() {
  importFile.value = null
  importError.value = ''
  importSummary.value = null
  importErrors.value = []
  showGuide.value = false
}

watch(
  () => props.show,
  (isOpen) => {
    if (isOpen) {
      resetState()
    }
  },
)

watch(hasResult, (value) => {
  if (value) {
    showGuide.value = false
  }
})

function close() {
  emit('close')
}

function onFileChange(event) {
  importFile.value = event.target.files?.[0] ?? null
}

async function submitImport() {
  if (importFile.value === null) {
    importError.value = 'Please choose a CSV file to import.'
    return
  }

  importing.value = true
  importError.value = ''
  importSummary.value = null
  importErrors.value = []

  try {
    const response = await importWorkers(importFile.value)
    importSummary.value = response.data
    importErrors.value = response.errors ?? []
    emit('imported')
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 422) {
      const fileErrors = error.response.data?.errors?.file
      importError.value = fileErrors?.[0] ?? 'The uploaded file is invalid.'
    } else {
      importError.value = 'Import failed. Please try again.'
    }
  } finally {
    importing.value = false
  }
}

async function downloadSample() {
  downloadingSample.value = true
  importError.value = ''

  try {
    const blob = await downloadWorkersSample()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'workers-sample.csv'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch {
    importError.value = 'Could not download sample CSV. Please try again.'
  } finally {
    downloadingSample.value = false
  }
}
</script>

<template>
  <div
    v-if="show"
    class="modal"
    role="dialog"
    aria-modal="true"
    @click.self="close"
  >
    <div class="modal__card modal__card--import">
      <header class="modal__header">
        <div>
          <h2 class="modal__title">
            Import workers from CSV
          </h2>
          <p class="modal__subtitle">
            One row per worker. Include a header row with the exact column names below.
          </p>
        </div>
        <Button @click="close">
          Close
        </Button>
      </header>

      <div class="import-guide-toggle">
        <button
          type="button"
          class="import-guide-toggle__button"
          @click="showGuide = !showGuide"
        >
          {{ showGuide ? 'Hide format guide' : 'Show format guide' }}
        </button>
      </div>

      <section
        v-if="showGuide"
        class="import-guide"
      >
        <div class="import-guide__panel">
          <h3 class="import-guide__title">
            Worker columns
          </h3>
          <p class="import-guide__text">
            Fixed order — use these exact header names:
          </p>
          <div class="import-guide__chips">
            <span
              v-for="column in importFixedColumns"
              :key="column.key"
              class="import-guide__chip"
              :title="column.hint"
            >
              {{ column.label }}
            </span>
          </div>
        </div>

        <div class="import-guide__panel">
          <h3 class="import-guide__title">
            Availability columns
          </h3>
          <p class="import-guide__text">
            One column per shift. Put a day expression in each cell, or leave it empty when
            the worker is unavailable.
          </p>
          <div class="import-guide__shifts">
            <div
              v-for="shift in importShiftColumns"
              :key="shift.key"
              class="import-guide__shift"
            >
              <span class="import-guide__shift-code">{{ shift.label }}</span>
              <span class="import-guide__shift-hint">{{ shift.hint }}</span>
            </div>
          </div>
        </div>

        <div class="import-guide__columns">
          <div class="import-guide__panel">
            <h3 class="import-guide__title">
              Day expressions
            </h3>
            <ul class="import-guide__list">
              <li
                v-for="example in importExpressionExamples"
                :key="example.expression"
              >
                <code class="import-guide__code">{{ example.expression }}</code>
                <span>{{ example.meaning }}</span>
              </li>
            </ul>
          </div>

          <div class="import-guide__panel">
            <h3 class="import-guide__title">
              Days of week
            </h3>
            <div class="import-guide__days">
              <span
                v-for="day in importDayLegend"
                :key="day.day"
                class="import-guide__day"
              >
                <strong>{{ day.day }}</strong>
                {{ day.label }}
              </span>
            </div>
            <p class="import-guide__note">
              1 = Sunday through 7 = Saturday
            </p>
          </div>
        </div>
      </section>

      <section
        v-if="showGuide"
        class="import-example"
      >
        <div class="import-example__header">
          <h3 class="import-example__title">
            Example
          </h3>
          <p class="import-example__text">
            Your file should look like this (empty cells mean unavailable):
          </p>
        </div>
        <div class="import-example__scroll">
          <table class="import-example__table">
            <thead>
              <tr>
                <th
                  v-for="column in importFixedColumns"
                  :key="column.key"
                  class="import-example__th import-example__th--fixed"
                >
                  {{ column.label }}
                </th>
                <th
                  v-for="shift in importShiftColumns"
                  :key="shift.key"
                  class="import-example__th import-example__th--shift"
                  :title="shift.hint"
                >
                  {{ shift.label }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, rowIndex) in importExampleRows"
                :key="rowIndex"
              >
                <td
                  v-for="column in importFixedColumns"
                  :key="column.key"
                  class="import-example__td"
                >
                  {{ row[column.key] }}
                </td>
                <td
                  v-for="shift in importShiftColumns"
                  :key="shift.key"
                  class="import-example__td import-example__td--shift"
                  :class="{ 'import-example__td--empty': row[shift.key] === '' }"
                >
                  {{ row[shift.key] === '' ? '—' : row[shift.key] }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div
        v-if="showGuide"
        class="import-guide__sample"
      >
        <Button
          :disabled="downloadingSample"
          @click="downloadSample"
        >
          {{ downloadingSample ? 'Downloading...' : 'Download sample CSV' }}
        </Button>
      </div>

      <div
        v-if="showGuide && importError"
        class="alert"
        role="alert"
      >
        {{ importError }}
      </div>

      <template v-if="!showGuide">
        <section class="import-upload">
          <Field label="CSV file">
            <input
              class="input"
              type="file"
              accept=".csv,text/csv"
              @change="onFileChange"
            >
          </Field>
        </section>

        <div
          v-if="importError"
          class="alert"
          role="alert"
        >
          {{ importError }}
        </div>

        <div
          v-if="importSummary"
          class="alert alert--success"
          role="status"
        >
          Imported {{ importSummary.imported }} of {{ importSummary.total }} rows
          ({{ importSummary.created }} created, {{ importSummary.updated }} updated,
          {{ importSummary.skipped }} skipped).
        </div>

        <div
          v-if="importErrors.length"
          class="import-errors"
        >
          <h3 class="import-errors__title">
            Row errors ({{ importErrors.length }})
          </h3>
          <div class="import-errors__scroll">
            <table class="table import-errors__table">
              <thead>
                <tr>
                  <th class="import-errors__th import-errors__th--line">
                    Line
                  </th>
                  <th class="import-errors__th import-errors__th--field">
                    Field
                  </th>
                  <th class="import-errors__th import-errors__th--message">
                    Message
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(rowError, index) in importErrors"
                  :key="`${rowError.line}-${index}`"
                >
                  <td class="import-errors__td import-errors__td--line">
                    {{ rowError.line }}
                  </td>
                  <td class="import-errors__td import-errors__td--field">
                    {{ rowError.field }}
                  </td>
                  <td class="import-errors__td import-errors__td--message">
                    {{ rowError.message }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <footer class="modal__footer">
          <Button
            :disabled="importing"
            @click="close"
          >
            Done
          </Button>
          <Button
            variant="primary"
            :disabled="importing || importFile === null"
            @click="submitImport"
          >
            {{ importing ? 'Importing...' : 'Import' }}
          </Button>
        </footer>
      </template>
    </div>
  </div>
</template>

<style scoped>
@import '@/assets/ui/modal.css';

.modal__card--import {
  width: min(1080px, 100%);
  gap: 1.25rem;
}

.modal__subtitle {
  margin: 0.25rem 0 0;
  font-size: 0.875rem;
  color: #64748b;
}

.import-guide {
  display: flex;
  flex-direction: column;
  gap: 0.875rem;
}

.import-guide__panel {
  padding: 0.875rem 1rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
}

.import-guide__title {
  margin: 0 0 0.375rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: #334155;
}

.import-guide__text {
  margin: 0 0 0.625rem;
  font-size: 0.8125rem;
  color: #64748b;
}

.import-guide__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
}

.import-guide__chip {
  padding: 0.2rem 0.5rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.75rem;
  color: #1e40af;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 0.375rem;
}

.import-guide__shifts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
}

.import-guide__shift {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  padding: 0.5rem 0.625rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
}

.import-guide__shift-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.8125rem;
  font-weight: 700;
  color: #0f172a;
}

.import-guide__shift-hint {
  font-size: 0.75rem;
  color: #64748b;
}

.import-guide__columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.875rem;
}

.import-guide__list {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.import-guide__list li {
  display: grid;
  grid-template-columns: 4.5rem 1fr;
  gap: 0.5rem;
  align-items: baseline;
  font-size: 0.8125rem;
  color: #475569;
}

.import-guide__code {
  padding: 0.125rem 0.375rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.75rem;
  color: #0f172a;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 0.25rem;
}

.import-guide__days {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 0.375rem;
}

.import-guide__day {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.125rem;
  padding: 0.375rem 0.25rem;
  font-size: 0.75rem;
  color: #475569;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 0.375rem;
}

.import-guide__day strong {
  font-size: 0.875rem;
  color: #0f172a;
}

.import-guide__note {
  margin: 0.625rem 0 0;
  font-size: 0.75rem;
  color: #64748b;
}

.import-guide__sample {
  display: flex;
  justify-content: flex-end;
}

.import-example {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.import-example__header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.5rem;
}

.import-example__title {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 700;
  color: #334155;
}

.import-example__text {
  margin: 0;
  font-size: 0.8125rem;
  color: #64748b;
}

.import-example__scroll {
  overflow-x: auto;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  background: #fff;
}

.import-example__table {
  width: 100%;
  min-width: 56rem;
  border-collapse: collapse;
  font-size: 0.75rem;
}

.import-example__th {
  padding: 0.5rem 0.625rem;
  text-align: left;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.6875rem;
  font-weight: 700;
  color: #334155;
  background: #f1f5f9;
  border-bottom: 1px solid #e2e8f0;
  white-space: nowrap;
}

.import-example__th--shift {
  color: #1d4ed8;
  background: #eff6ff;
}

.import-example__td {
  padding: 0.5rem 0.625rem;
  color: #334155;
  border-bottom: 1px solid #f1f5f9;
  white-space: nowrap;
}

.import-example__td--shift {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-weight: 600;
  color: #1e40af;
  background: #f8fafc;
}

.import-example__td--empty {
  font-weight: 400;
  color: #94a3b8;
  text-align: center;
}

.import-example__table tbody tr:last-child .import-example__td {
  border-bottom: none;
}

.import-upload {
  padding-top: 0.25rem;
  border-top: 1px solid #e2e8f0;
}

.import-upload .field {
  margin: 0;
}

.import-guide-toggle {
  margin-top: -0.25rem;
}

.import-guide-toggle__button {
  padding: 0;
  font-size: 0.8125rem;
  font-weight: 600;
  color: #2563eb;
  background: none;
  border: none;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 0.125rem;
}

.import-guide-toggle__button:hover {
  color: #1d4ed8;
}

.import-errors__title {
  margin: 0 0 0.5rem;
  font-size: 0.9375rem;
  color: #334155;
}

.import-errors__scroll {
  max-height: 45vh;
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
}

.import-errors__table {
  min-width: 0;
  width: 100%;
  table-layout: fixed;
}

.import-errors__th--line,
.import-errors__td--line {
  width: 3.5rem;
  white-space: nowrap;
}

.import-errors__th--field,
.import-errors__td--field {
  width: 7rem;
  white-space: nowrap;
}

.import-errors__th--message,
.import-errors__td--message {
  white-space: normal;
  word-break: break-word;
}

@media (max-width: 820px) {
  .import-guide__columns,
  .import-guide__shifts {
    grid-template-columns: 1fr;
  }

  .import-guide__days {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
</style>
