<script setup lang="ts">
import { ref, watch } from 'vue'
import type { Worker, WorkerShift } from '@/api/workers'

const props = defineProps<{
  show: boolean
  workers: Worker[]
  shifts: WorkerShift[]
  initialDate?: string
  saving?: boolean
  error?: string
}>()

const emit = defineEmits<{
  close: []
  submit: [payload: { worker_id: number; shift_id: number; work_date: string }]
}>()

const workerId = ref<number | ''>('')
const shiftId = ref<number | ''>('')
const workDate = ref('')

watch(
  () => props.show,
  (isOpen) => {
    if (!isOpen) {
      return
    }

    workerId.value = ''
    shiftId.value = ''
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
  <div v-if="show" class="modal" role="dialog" aria-modal="true" @click.self="emit('close')">
    <div class="modal__card">
      <header class="modal__header">
        <h2 class="modal__title">Add assignment</h2>
        <button type="button" class="button" @click="emit('close')">Close</button>
      </header>

      <p class="modal__hint">
        Manually assign an active worker to a shift. The same hard constraints as auto-generation
        apply (availability, max 2 shifts/day, unique slot, max monthly hours).
      </p>

      <form class="assignment-form" @submit.prevent="onSubmit">
        <label class="field">
          <span class="field__label">Worker</span>
          <select v-model="workerId" class="input" required>
            <option value="">Select worker</option>
            <option v-for="worker in workers" :key="worker.id" :value="worker.id">
              {{ worker.full_name }} ({{ worker.role.name ?? worker.role.code }})
            </option>
          </select>
        </label>

        <label class="field">
          <span class="field__label">Shift</span>
          <select v-model="shiftId" class="input" required>
            <option value="">Select shift</option>
            <option v-for="shift in shifts" :key="shift.id" :value="shift.id">
              {{ shift.code }} — {{ shift.label }}
            </option>
          </select>
        </label>

        <label class="field">
          <span class="field__label">Date</span>
          <input v-model="workDate" class="input" type="date" required />
        </label>

        <div v-if="error" class="alert" role="alert">{{ error }}</div>

        <footer class="modal__footer">
          <button type="button" class="button" :disabled="saving" @click="emit('close')">
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
</style>
