<script setup>
defineProps({
  error: {
    type: Boolean,
    default: false,
  },
})

const model = defineModel({
  type: [String, Number, null],
  default: '',
})
</script>

<template>
  <select
    v-model="model"
    class="input"
    :class="{ 'input--error': error }"
    v-bind="$attrs"
  >
    <slot />
  </select>
</template>
