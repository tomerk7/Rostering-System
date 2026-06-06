<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  show: { type: Boolean, required: true },
  workers: { type: Array, required: true },
  shifts: { type: Array, required: true },
  roles: { type: Array, default: undefined },
  initialDate: { type: String, default: undefined },
  initialShiftId: { type: Number, default: undefined },
  initialRoleId: { type: Number, default: undefined },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['close', 'submit'])

const workerId = ref('')
const shiftId = ref('')
const workDate = ref('')

const rolesById = computed(() => new Map((props.roles ?? []).map((role) => [role.id, role])))

const suggestedRole = computed(() =>
  props.initialRoleId ? rolesById.value.get(props.initialRoleId) : null,
)

const filteredWorkers = computed(() => {
  if (!props.initialRoleId) {
    return props.workers
  }

  return props.workers.filter((worker) => worker.role.id === props.initialRoleId)
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

function onSubmit() {
  if (workerId.value === '' || shiftId.value === '' || workDate.value === '') {
    return
  }

  emit('submit', {
    worker_id: Number(workerId.value),
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
          >
            <option value="">Select worker</option>
            <option
              v-for="worker in filteredWorkers"
              :key="worker.id"
              :value="worker.id"
            >
              {{ worker.full_name }} ({{ worker.role.name ?? worker.role.code }})
            </option>
          </select>
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
              {{ shift.code }} — {{ shift.label }}
            </option>
          </select>
        </label>

        <label class="field">
          <span class="field__label">Date</span>
          <input
            v-model="workDate"
            class="input"
            type="date"
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
.assignment-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.modal__hint--emphasis {
  color: #c2410c;
  font-weight: 600;
}
</style>
