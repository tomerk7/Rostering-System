<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useRoute, useRouter } from 'vue-router'
import {
  createWorker,
  getReferenceData,
  getWorker,
  updateWorker,
  type ReferenceData,
  type WorkerPayload,
} from '@/api/workers'

const route = useRoute()
const router = useRouter()

const workerId = computed(() => {
  const id = Number(route.params.id)

  return Number.isInteger(id) && id > 0 ? id : null
})
const isEdit = computed(() => workerId.value !== null)

const referenceData = ref<ReferenceData>({ roles: [], shifts: [], shift_role_requirements: [] })
const loading = ref(false)
const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

const form = reactive({
  full_name: '',
  israeli_id: '',
  role_id: null as number | null,
  is_active: true,
  hourly_cost: '',
  min_monthly_hours: '',
  max_monthly_hours: '',
  availability_days: [] as number[],
  availability_shifts: [] as number[],
})

const dayOptions = [
  { value: 0, label: 'Sunday' },
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
]

const pageTitle = computed(() => (isEdit.value ? 'Edit Worker' : 'Create Worker'))
const submitLabel = computed(() => {
  if (submitting.value) {
    return isEdit.value ? 'Saving...' : 'Creating...'
  }

  return isEdit.value ? 'Save changes' : 'Create worker'
})

onMounted(async () => {
  await loadForm()
})

async function loadForm() {
  loading.value = true
  formError.value = ''

  try {
    const referenceResponse = await getReferenceData()
    referenceData.value = referenceResponse.data

    if (workerId.value !== null) {
      const workerResponse = await getWorker(workerId.value)
      const worker = workerResponse.data

      form.full_name = worker.full_name
      form.israeli_id = worker.israeli_id
      form.role_id = worker.role.id
      form.is_active = worker.is_active
      form.hourly_cost = worker.contract?.hourly_cost?.toString() ?? ''
      form.min_monthly_hours = worker.contract?.min_monthly_hours.toString() ?? ''
      form.max_monthly_hours = worker.contract?.max_monthly_hours.toString() ?? ''
      form.availability_days = worker.contract?.availability.days ?? []
      form.availability_shifts = worker.contract?.availability.shifts.map((shift) => shift.id) ?? []
    }
  } catch {
    formError.value = 'Could not load the worker form. Please try again.'
  } finally {
    loading.value = false
  }
}

async function submitForm() {
  submitting.value = true
  formError.value = ''
  fieldErrors.value = {}

  try {
    const payload = buildPayload()

    if (workerId.value !== null) {
      await updateWorker(workerId.value, payload)
    } else {
      await createWorker(payload)
    }

    await router.push({ name: 'workers' })
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 422) {
      const data = error.response.data as { errors?: Record<string, string[]>; message?: string }
      fieldErrors.value = data.errors ?? {}
      formError.value = data.message ?? 'Please fix the highlighted fields.'
    } else {
      formError.value = 'Could not save worker. Please try again.'
    }
  } finally {
    submitting.value = false
  }
}

function buildPayload(): WorkerPayload {
  return {
    full_name: form.full_name.trim(),
    israeli_id: form.israeli_id.trim(),
    role_id: form.role_id,
    is_active: form.is_active,
    contract: {
      hourly_cost: form.hourly_cost === '' ? null : Number(form.hourly_cost),
      min_monthly_hours: form.min_monthly_hours === '' ? null : Number(form.min_monthly_hours),
      max_monthly_hours: form.max_monthly_hours === '' ? null : Number(form.max_monthly_hours),
    },
    availability: {
      days: form.availability_days,
      shifts: form.availability_shifts,
    },
  }
}

function fieldError(field: string): string {
  return fieldErrors.value[field]?.[0] ?? ''
}
</script>

<template>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">Workers</p>
        <h1 class="page__title">{{ pageTitle }}</h1>
        <p class="page__description">Manage personal details, contract limits, and availability.</p>
      </div>
      <RouterLink class="button" :to="{ name: 'workers' }">Back to workers</RouterLink>
    </header>

    <section class="panel">
      <div v-if="formError" class="alert" role="alert">
        {{ formError }}
      </div>

      <div v-if="loading" class="empty-state">Loading form...</div>

      <form v-else class="worker-form" novalidate @submit.prevent="submitForm">
        <section class="form-section">
          <div>
            <h2 class="form-section__title">Worker Details</h2>
            <p class="form-section__description">Identity, role, and active status.</p>
          </div>

          <div class="form-grid">
            <label class="field">
              <span class="field__label">Full name</span>
              <input
                v-model="form.full_name"
                class="input"
                :class="{ 'input--error': fieldError('full_name') }"
                type="text"
                autocomplete="name"
              />
              <span v-if="fieldError('full_name')" class="field__error">
                {{ fieldError('full_name') }}
              </span>
            </label>

            <label class="field">
              <span class="field__label">Israeli ID</span>
              <input
                v-model="form.israeli_id"
                class="input"
                :class="{ 'input--error': fieldError('israeli_id') }"
                type="text"
                inputmode="numeric"
                maxlength="9"
              />
              <span v-if="fieldError('israeli_id')" class="field__error">
                {{ fieldError('israeli_id') }}
              </span>
            </label>

            <label class="field">
              <span class="field__label">Role</span>
              <select
                v-model="form.role_id"
                class="input"
                :class="{ 'input--error': fieldError('role_id') }"
              >
                <option :value="null">Select role</option>
                <option v-for="role in referenceData.roles" :key="role.id" :value="role.id">
                  {{ role.name }}
                </option>
              </select>
              <span v-if="fieldError('role_id')" class="field__error">
                {{ fieldError('role_id') }}
              </span>
            </label>

            <label class="check-field">
              <input v-model="form.is_active" type="checkbox" />
              <span>Worker is active</span>
            </label>
          </div>
        </section>

        <section class="form-section">
          <div>
            <h2 class="form-section__title">Contract</h2>
            <p class="form-section__description">Hourly cost and monthly hour boundaries.</p>
          </div>

          <div class="form-grid form-grid--three">
            <label class="field">
              <span class="field__label">Hourly cost</span>
              <input
                v-model="form.hourly_cost"
                class="input"
                :class="{ 'input--error': fieldError('contract.hourly_cost') }"
                type="number"
                min="0"
                step="0.01"
              />
              <span v-if="fieldError('contract.hourly_cost')" class="field__error">
                {{ fieldError('contract.hourly_cost') }}
              </span>
            </label>

            <label class="field">
              <span class="field__label">Minimum monthly hours</span>
              <input
                v-model="form.min_monthly_hours"
                class="input"
                :class="{ 'input--error': fieldError('contract.min_monthly_hours') }"
                type="number"
                min="0"
                max="744"
              />
              <span v-if="fieldError('contract.min_monthly_hours')" class="field__error">
                {{ fieldError('contract.min_monthly_hours') }}
              </span>
            </label>

            <label class="field">
              <span class="field__label">Maximum monthly hours</span>
              <input
                v-model="form.max_monthly_hours"
                class="input"
                :class="{ 'input--error': fieldError('contract.max_monthly_hours') }"
                type="number"
                min="0"
                max="744"
              />
              <span v-if="fieldError('contract.max_monthly_hours')" class="field__error">
                {{ fieldError('contract.max_monthly_hours') }}
              </span>
            </label>
          </div>
        </section>

        <section class="form-section">
          <div>
            <h2 class="form-section__title">Availability</h2>
            <p class="form-section__description">Select available days and shifts.</p>
          </div>

          <div class="choice-grid">
            <fieldset class="choice-group">
              <legend>Days</legend>
              <label v-for="day in dayOptions" :key="day.value" class="check-field">
                <input v-model="form.availability_days" type="checkbox" :value="day.value" />
                <span>{{ day.label }}</span>
              </label>
              <span v-if="fieldError('availability.days')" class="field__error">
                {{ fieldError('availability.days') }}
              </span>
            </fieldset>

            <fieldset class="choice-group">
              <legend>Shifts</legend>
              <label v-for="shift in referenceData.shifts" :key="shift.id" class="check-field">
                <input v-model="form.availability_shifts" type="checkbox" :value="shift.id" />
                <span>{{ shift.code }} - {{ shift.label }} ({{ shift.start_time }}-{{ shift.end_time }})</span>
              </label>
              <span v-if="fieldError('availability.shifts')" class="field__error">
                {{ fieldError('availability.shifts') }}
              </span>
            </fieldset>
          </div>
        </section>

        <footer class="form-actions">
          <RouterLink class="button" :to="{ name: 'workers' }">Cancel</RouterLink>
          <button type="submit" class="button button--primary" :disabled="submitting">
            {{ submitLabel }}
          </button>
        </footer>
      </form>
    </section>
  </main>
</template>