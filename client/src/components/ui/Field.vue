<script setup>
defineProps({
  label: {
    type: String,
    default: undefined,
  },
  error: {
    type: String,
    default: undefined,
  },
  hint: {
    type: String,
    default: undefined,
  },
})
</script>

<template>
  <label class="field">
    <span
      v-if="label"
      class="field__label"
    >{{ label }}</span>
    <slot />
    <span
      v-if="error"
      class="field__error"
    >{{ error }}</span>
    <span
      v-else-if="hint"
      class="field__hint"
    >{{ hint }}</span>
  </label>
</template>
