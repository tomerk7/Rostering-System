<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

const props = defineProps({
  variant: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'primary', 'danger'].includes(value),
  },
  type: {
    type: String,
    default: 'button',
  },
  to: {
    type: [String, Object],
    default: undefined,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
})

defineEmits(['click'])

const classes = computed(() => {
  const list = ['button']

  if (props.variant === 'primary') {
    list.push('button--primary')
  }

  if (props.variant === 'danger') {
    list.push('button--danger')
  }

  return list
})

const isLink = computed(() => props.to != null)
</script>

<template>
  <RouterLink
    v-if="isLink"
    :to="to"
    :class="classes"
  >
    <slot />
  </RouterLink>
  <button
    v-else
    :type="type"
    :class="classes"
    :disabled="disabled"
    v-bind="$attrs"
    @click="$emit('click', $event)"
  >
    <slot />
  </button>
</template>
