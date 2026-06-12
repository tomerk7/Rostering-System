<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useRoute, useRouter } from 'vue-router'
import {
  createWorker,
  getReferenceData,
  getWorker,
  updateWorker,
} from '@/api/workers'

const route = useRoute()
const router = useRouter()

const workerIsraeliId = computed(() => {
  const id = String(route.params.id ?? '').trim()

  return /^\d{9}$/.test(id) ? id : null
})
const isEdit = computed(() => workerIsraeliId.value !== null)

const referenceData = ref({ roles: [], shifts: [], shift_role_requirements: [] })
const loading = ref(false)
const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref({})

const form = reactive({
  full_name: '',
  israeli_id: '',
  role_id: null,
  is_active: true,
  hourly_cost: '',
  min_monthly_hours: '',
  max_monthly_hours: '',
})

const availabilitySelections = reactive({})

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

function slotKey(dayOfWeek, shiftId) {
  return `${dayOfWeek}:${shiftId}`
}

function isSlotSelected(dayOfWeek, shiftId) {
  return availabilitySelections[slotKey(dayOfWeek, shiftId)] === true
}

function setSlotSelected(dayOfWeek, shiftId, checked) {
  const key = slotKey(dayOfWeek, shiftId)

  if (checked) {
    availabilitySelections[key] = true
    return
  }

  delete availabilitySelections[key]
}

function clearAvailabilitySelections() {
  Object.keys(availabilitySelections).forEach((key) => {
    delete availabilitySelections[key]
  })
}

function loadAvailabilitySelections(slots = []) {
  clearAvailabilitySelections()

  slots.forEach((slot) => {
    availabilitySelections[slotKey(slot.day_of_week, slot.shift.id)] = true
  })
}

async function loadForm() {
  loading.value = true
  formError.value = ''

  try {
    const referenceResponse = await getReferenceData()
    referenceData.value = referenceResponse.data

    if (workerIsraeliId.value !== null) {
      const workerResponse = await getWorker(workerIsraeliId.value)
      const worker = workerResponse.data

      form.full_name = worker.full_name
      form.israeli_id = worker.israeli_id
      form.role_id = worker.role.id
      form.is_active = worker.is_active
      form.hourly_cost = worker.contract?.hourly_cost?.toString() ?? ''
      form.min_monthly_hours = worker.contract?.min_monthly_hours.toString() ?? ''
      form.max_monthly_hours = worker.contract?.max_monthly_hours.toString() ?? ''
      loadAvailabilitySelections(worker.contract?.availability ?? [])
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

    if (workerIsraeliId.value !== null) {
      await updateWorker(workerIsraeliId.value, payload)
    } else {
      const response = await createWorker(payload)
      await router.push({ name: 'workers.edit', params: { id: response.data.israeli_id } })
    }
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 422) {
      const data = error.response.data
      fieldErrors.value = data.errors ?? {}
      formError.value = data.message ?? 'Please fix the highlighted fields.'
    } else {
      formError.value = 'Could not save worker. Please try again.'
    }
  } finally {
    submitting.value = false
  }
}

function buildAvailabilityPayload() {
  return Object.keys(availabilitySelections).map((key) => {
    const [dayOfWeek, shiftId] = key.split(':')

    return {
      day_of_week: Number(dayOfWeek),
      shift_id: Number(shiftId),
    }
  })
}

function buildPayload() {
  const payload = {
    full_name: form.full_name.trim(),
    role_id: form.role_id,
    is_active: form.is_active,
    contract: {
      hourly_cost: form.hourly_cost === '' ? null : Number(form.hourly_cost),
      min_monthly_hours: form.min_monthly_hours === '' ? null : Number(form.min_monthly_hours),
      max_monthly_hours: form.max_monthly_hours === '' ? null : Number(form.max_monthly_hours),
    },
    availability: buildAvailabilityPayload(),
  }

  if (!isEdit.value) {
    payload.israeli_id = form.israeli_id.trim()
  }

  return payload
}

function fieldError(field) {
  return fieldErrors.value[field]?.[0] ?? ''
}
</script>

<template>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="page__eyebrow">
          Workers
        </p>
        <h1 class="page__title">
          {{ pageTitle }}
        </h1>
        <p class="page__description">
          Manage personal details, contract limits, and availability.
        </p>
      </div>
      <RouterLink
        class="button"
        :to="{ name: 'workers' }"
      >
        Back to workers
      </RouterLink>
    </header>

    <section class="panel">
      <div
        v-if="formError"
        class="alert"
        role="alert"
      >
        {{ formError }}
      </div>

      <div
        v-if="loading"
        class="empty-state"
      >
        Loading form...
      </div>

      <form
        v-else
        class="worker-form"
        novalidate
        @submit.prevent="submitForm"
      >
        <section class="form-section">
          <div>
            <h2 class="form-section__title">
              Worker Details
            </h2>
            <p class="form-section__description">
              Identity, role, and active status.
            </p>
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
              >
              <span
                v-if="fieldError('full_name')"
                class="field__error"
              >
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
                :disabled="isEdit"
              >
              <span
                v-if="fieldError('israeli_id')"
                class="field__error"
              >
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
                <option
                  v-for="role in referenceData.roles"
                  :key="role.id"
                  :value="role.id"
                >
                  {{ role.name }}
                </option>
              </select>
              <span
                v-if="fieldError('role_id')"
                class="field__error"
              >
                {{ fieldError('role_id') }}
              </span>
            </label>

            <label class="check-field">
              <input
                v-model="form.is_active"
                type="checkbox"
              >
              <span>Worker is active</span>
            </label>
          </div>
        </section>

        <section class="form-section">
          <div>
            <h2 class="form-section__title">
              Contract
            </h2>
            <p class="form-section__description">
              Hourly cost and monthly hour boundaries.
            </p>
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
              >
              <span
                v-if="fieldError('contract.hourly_cost')"
                class="field__error"
              >
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
              >
              <span
                v-if="fieldError('contract.min_monthly_hours')"
                class="field__error"
              >
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
              >
              <span
                v-if="fieldError('contract.max_monthly_hours')"
                class="field__error"
              >
                {{ fieldError('contract.max_monthly_hours') }}
              </span>
            </label>
          </div>
        </section>

        <section class="form-section">
          <div>
            <h2 class="form-section__title">
              Availability
            </h2>
            <p class="form-section__description">
              Select which shifts the worker can take on each weekday.
            </p>
          </div>

          <div>
            <div class="choice-grid">
              <fieldset
                v-for="day in dayOptions"
                :key="day.value"
                class="choice-group"
              >
                <legend>{{ day.label }}</legend>
                <label
                  v-for="shift in referenceData.shifts"
                  :key="`${day.value}-${shift.id}`"
                  class="check-field"
                >
                  <input
                    type="checkbox"
                    :checked="isSlotSelected(day.value, shift.id)"
                    @change="setSlotSelected(day.value, shift.id, $event.target.checked)"
                  >
                  <span>{{ shift.code }} - {{ shift.label }}</span>
                </label>
              </fieldset>
            </div>
            <span
              v-if="fieldError('availability')"
              class="field__error"
            >
              {{ fieldError('availability') }}
            </span>
          </div>
        </section>

        <footer class="form-actions">
          <RouterLink
            class="button"
            :to="{ name: 'workers' }"
          >
            Cancel
          </RouterLink>
          <button
            type="submit"
            class="button button--primary"
            :disabled="submitting"
          >
            {{ submitLabel }}
          </button>
        </footer>
      </form>
    </section>
  </main>
</template>
