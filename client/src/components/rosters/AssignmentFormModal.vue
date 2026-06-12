<script setup>
import { computed, ref, watch } from 'vue'
import { filterEligibleWorkers } from '@/lib/eligibleWorkers'
import { shiftLabel } from '@/lib/shifts'

const props = defineProps({
  show: { type: Boolean, required: true },
  workers: { type: Array, required: true },
  shifts: { type: Array, required: true },
  assignments: { type: Array, default: () => [] },
  assignedHoursByWorker: { type: Object, default: () => ({}) },
  roles: { type: Array, default: undefined },
  initialDate: { type: String, default: undefined },
  minDate: { type: String, default: undefined },
  maxDate: { type: String, default: undefined },
  initialShiftId: { type: Number, default: undefined },
  initialRoleId: { type: Number, default: undefined },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['close', 'submit'])

const workerId = ref('')
const shiftId = ref('')
const workDate = ref('')

/**
 * Roles indexed by id for quick lookup.
 *
 * @returns {Map<number, object>}
 */
const rolesById = computed(() => new Map((props.roles ?? []).map((role) => [role.id, role])))

/**
 * Shifts indexed by id for quick lookup.
 *
 * @returns {Map<number, object>}
 */
const shiftsById = computed(() => new Map(props.shifts.map((shift) => [shift.id, shift])))

/**
 * Role suggested from the clicked grid cell, if any.
 *
 * @returns {object|null}
 */
const suggestedRole = computed(() =>
  props.initialRoleId ? rolesById.value.get(props.initialRoleId) : null,
)

/**
 * Whether both work date and shift are selected.
 *
 * @returns {boolean}
 */
const hasSlotSelection = computed(() => workDate.value !== '' && shiftId.value !== '')

/**
 * Workers eligible for the selected date, shift, and constraints.
 *
 * @returns {object[]}
 */
const filteredWorkers = computed(() => {
  if (!hasSlotSelection.value) {
    return []
  }

  return filterEligibleWorkers(props.workers, {
    workDate: workDate.value,
    shiftId: Number(shiftId.value),
    roleId: props.initialRoleId ?? null,
    assignments: props.assignments,
    shiftsById: shiftsById.value,
    assignedHoursByWorker: props.assignedHoursByWorker,
  })
})

watch(
  () => props.show,
  (isOpen) => {
    if (!isOpen) {
      return
    }

    workerId.value = ''
    shiftId.value = props.initialShiftId ?? ''
    workDate.value = props.initialDate ?? ''
  },
)

watch([workDate, shiftId, filteredWorkers], () => {
  if (workerId.value === '') {
    return
  }

  const selectedId = String(workerId.value)
  const stillEligible = filteredWorkers.value.some(
    (worker) => String(worker.israeli_id) === selectedId,
  )

  if (!stillEligible) {
    workerId.value = ''
  }
})

/**
 * Emit a submit event when the form is valid.
 *
 * @returns {void}
 */
function onSubmit() {
  if (workerId.value === '' || shiftId.value === '' || workDate.value === '') {
    return
  }

  emit('submit', {
    worker_id: workerId.value,
    shift_id: Number(shiftId.value),
    work_date: workDate.value,
  })
}
</script>

<template>
  <div
    v-if="show"
    class="modal"
    role="dialog"
    aria-modal="true"
    @click.self="emit('close')"
  >
    <div class="modal__card">
      <header class="modal__header">
        <h2 class="modal__title">
          Add assignment
        </h2>
        <button
          type="button"
          class="button"
          @click="emit('close')"
        >
          Close
        </button>
      </header>

      <p class="modal__hint">
        Manually assign an active worker to fill a coverage gap. The same hard constraints as
        auto-generation apply (availability, max 2 shifts/day, unique slot, max monthly hours).
      </p>

      <p
        v-if="suggestedRole"
        class="modal__hint modal__hint--emphasis"
      >
        Suggested role: {{ suggestedRole.name }}
      </p>

      <form
        class="assignment-form"
        @submit.prevent="onSubmit"
      >
        <label class="field">
          <span class="field__label">Worker</span>
          <select
            v-model="workerId"
            class="input"
            required
            :disabled="!hasSlotSelection"
          >
            <option value="">
              {{ hasSlotSelection ? 'Select worker' : 'Choose date and shift first' }}
            </option>
            <option
              v-for="worker in filteredWorkers"
              :key="worker.israeli_id"
              :value="worker.israeli_id"
            >
              {{ worker.full_name }} ({{ worker.role.name ?? worker.role.code }})
            </option>
          </select>
          <span
            v-if="hasSlotSelection && filteredWorkers.length === 0"
            class="field__hint"
          >
            No workers are eligible for this date and shift.
          </span>
        </label>

        <label class="field">
          <span class="field__label">Shift</span>
          <select
            v-model="shiftId"
            class="input"
            required
          >
            <option value="">Select shift</option>
            <option
              v-for="shift in shifts"
              :key="shift.id"
              :value="shift.id"
            >
              {{ shift.code }} — {{ shiftLabel(shift) }}
            </option>
          </select>
        </label>

        <label class="field">
          <span class="field__label">Date</span>
          <input
            v-model="workDate"
            class="input"
            type="date"
            :min="minDate"
            :max="maxDate"
            required
          >
        </label>

        <div
          v-if="error"
          class="alert"
          role="alert"
        >
          {{ error }}
        </div>

        <footer class="modal__footer">
          <button
            type="button"
            class="button"
            :disabled="saving"
            @click="emit('close')"
          >
            Cancel
          </button>
          <button
            type="submit"
            class="button button--primary"
            :disabled="saving || workerId === '' || shiftId === '' || workDate === ''"
          >
            {{ saving ? 'Adding...' : 'Add assignment' }}
          </button>
        </footer>
      </form>
    </div>
  </div>
</template>

<style scoped>
@import '@/assets/ui/button.css';
@import '@/assets/ui/forms.css';
@import '@/assets/ui/modal.css';

.assignment-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.modal__hint--emphasis {
  color: #c2410c;
  font-weight: 600;
}

.field__hint {
  color: #9a3412;
  font-size: 0.875rem;
}
</style>
