<script setup>
defineProps({
  type: {
    type: String,
    default: 'text',
  },
  error: {
    type: Boolean,
    default: false,
  },
})

const model = defineModel({
  type: [String, Number],
  default: '',
})
</script>

<template>
  <input
    v-model="model"
    class="input"
    :class="{ 'input--error': error }"
    :type="type"
    v-bind="$attrs"
  >
</template>
