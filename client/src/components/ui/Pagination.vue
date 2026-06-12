<script setup>
import { computed } from 'vue'
import Button from '@/components/ui/Button.vue'

const props = defineProps({
  from: {
    type: Number,
    default: 0,
  },
  to: {
    type: Number,
    default: 0,
  },
  total: {
    type: Number,
    default: 0,
  },
  currentPage: {
    type: Number,
    default: 1,
  },
  lastPage: {
    type: Number,
    default: 1,
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

defineEmits(['previous', 'next'])

const canGoBack = computed(() => props.currentPage > 1)
const canGoForward = computed(() => props.currentPage < props.lastPage)
</script>

<template>
  <footer class="pagination">
    <p>
      Showing {{ from }}-{{ to }} of {{ total }}
    </p>
    <div class="pagination__actions">
      <Button
        :disabled="!canGoBack || loading"
        @click="$emit('previous')"
      >
        Previous
      </Button>
      <span>Page {{ currentPage }} of {{ lastPage }}</span>
      <Button
        :disabled="!canGoForward || loading"
        @click="$emit('next')"
      >
        Next
      </Button>
    </div>
  </footer>
</template>
