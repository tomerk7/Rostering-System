<script setup>
import { computed, ref, watch } from 'vue'
import { filterEligibleWorkers } from '@/lib/eligibleWorkers'
import { shiftLabel } from '@/lib/shifts'
import Button from '@/components/ui/Button.vue'

const props = defineProps({
  show: { type: Boolean, required: true },
  workers: { type: Array, required: true },
  shifts: { type: Array, required: true },
  assignments: { type: Array, default: () => [] },
  assignedHoursByWorker: { type: Object, default: () => ({}) },
  roles: { type: Array, default: undefined },
  initialDate: { type: String, default: undefined },
  initialShiftId: { type: Number, default: undefined },
  initialRoleId: { type: Number, default: undefined },
  roleRequired: { type: Number, default: undefined },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['close', 'submit'])

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
 * Role for the selected slot.
 *
 * @returns {object|null}
 */
const selectedRole = computed(() =>
  props.initialRoleId ? rolesById.value.get(props.initialRoleId) : null,
)

/**
 * Shift for the selected slot.
 *
 * @returns {object|null}
 */
const selectedShift = computed(() => {
  const id = Number(shiftId.value)

  return Number.isFinite(id) ? shiftsById.value.get(id) : null
})

/**
 * Whether the slot context is fully specified.
 *
 * @returns {boolean}
 */
const hasSlotSelection = computed(
  () => workDate.value !== '' && shiftId.value !== '' && selectedRole.value != null,
)

/**
 * Workers eligible for the selected date, shift, and role.
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
    roleRequired: props.roleRequired ?? null,
    assignments: props.assignments,
    shiftsById: shiftsById.value,
    assignedHoursByWorker: props.assignedHoursByWorker,
  })
})

/**
 * Formatted date label for the slot.
 *
 * @returns {string}
 */
const slotDateLabel = computed(() => {
  if (!workDate.value) {
    return ''
  }

  return new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
  }).format(new Date(`${workDate.value}T00:00:00`))
})

/**
 * Formatted shift label for the slot.
 *
 * @returns {string}
 */
const slotShiftLabel = computed(() => {
  if (!selectedShift.value) {
    return ''
  }

  return `Shift ${selectedShift.value.code} — ${shiftLabel(selectedShift.value)}`
})

watch(
  () => props.show,
  (isOpen) => {
    if (!isOpen) {
      return
    }

    shiftId.value = props.initialShiftId ?? ''
    workDate.value = props.initialDate ?? ''
  },
)

/**
 * Assign the chosen worker to the prefilled slot.
 *
 * @param {object} worker
 * @returns {void}
 */
function selectWorker(worker) {
  if (props.saving || !hasSlotSelection.value) {
    return
  }

  emit('submit', {
    worker_id: worker.israeli_id,
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
          Add {{ selectedRole?.name ?? 'worker' }}
        </h2>
        <Button @click="emit('close')">
          Close
        </Button>
      </header>

      <p class="modal__hint">
        Choose an eligible worker for this role, shift, and day. The same hard
        constraints as auto-generation apply.
      </p>

      <dl
        v-if="hasSlotSelection"
        class="assignment-slot"
      >
        <div class="assignment-slot__item">
          <dt>Date</dt>
          <dd>{{ slotDateLabel }}</dd>
        </div>
        <div class="assignment-slot__item">
          <dt>Shift</dt>
          <dd>{{ slotShiftLabel }}</dd>
        </div>
        <div class="assignment-slot__item">
          <dt>Role</dt>
          <dd>{{ selectedRole.name }}</dd>
        </div>
      </dl>

      <p
        v-if="hasSlotSelection && filteredWorkers.length === 0"
        class="assignment-empty"
      >
        No workers are eligible for this slot. The role may already be fully staffed.
      </p>

      <ul
        v-else-if="hasSlotSelection"
        class="worker-picker"
      >
        <li
          v-for="worker in filteredWorkers"
          :key="worker.israeli_id"
        >
          <button
            type="button"
            class="worker-picker__option"
            :disabled="saving"
            @click="selectWorker(worker)"
          >
            <span class="worker-picker__name">{{ worker.full_name }}</span>
            <span class="worker-picker__meta">
              {{ worker.role.name ?? worker.role.code }}
            </span>
          </button>
        </li>
      </ul>

      <div
        v-if="error"
        class="alert"
        role="alert"
      >
        {{ error }}
      </div>

      <footer class="modal__footer">
        <Button
          :disabled="saving"
          @click="emit('close')"
        >
          Cancel
        </Button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
@import '@/assets/ui/modal.css';

.assignment-slot {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 0.75rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
}

.assignment-slot__item {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-width: 0;
}

.assignment-slot__item dt {
  font-size: 0.6875rem;
  font-weight: 700;
  color: #64748b;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.assignment-slot__item dd {
  margin: 0;
  font-size: 0.875rem;
  font-weight: 600;
  color: #0f172a;
}

.assignment-empty {
  margin: 0;
  padding: 1rem;
  font-size: 0.875rem;
  color: #64748b;
  text-align: center;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 0.75rem;
}

.worker-picker {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-height: min(24rem, 50vh);
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.worker-picker__option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  padding: 0.75rem 1rem;
  font: inherit;
  text-align: left;
  color: #0f172a;
  cursor: pointer;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  transition:
    border-color 0.15s ease,
    background 0.15s ease,
    box-shadow 0.15s ease;
}

.worker-picker__option:hover:not(:disabled) {
  background: #eff6ff;
  border-color: #bfdbfe;
  box-shadow: 0 0 0 3px rgb(37 99 235 / 10%);
}

.worker-picker__option:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.worker-picker__name {
  font-size: 0.9375rem;
  font-weight: 600;
}

.worker-picker__meta {
  font-size: 0.8125rem;
  color: #64748b;
  white-space: nowrap;
}

@media (max-width: 640px) {
  .assignment-slot {
    grid-template-columns: 1fr;
  }
}
</style>
