<script setup>
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getLoginErrorMessage, useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const fieldErrors = reactive({ email: '', password: '' })
const serverError = ref('')
const submitting = ref(false)

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function validate() {
  fieldErrors.email = ''
  fieldErrors.password = ''
  serverError.value = ''

  let valid = true

  if (!email.value.trim()) {
    fieldErrors.email = 'Email is required.'
    valid = false
  } else if (!emailPattern.test(email.value.trim())) {
    fieldErrors.email = 'Enter a valid email address.'
    valid = false
  }

  if (!password.value) {
    fieldErrors.password = 'Password is required.'
    valid = false
  }

  return valid
}

const canSubmit = computed(() => !submitting.value)

async function onSubmit() {
  if (!validate()) {
    return
  }

  submitting.value = true
  serverError.value = ''

  try {
    await auth.login(email.value.trim(), password.value)
    await router.push({ name: 'home' })
  } catch (error) {
    serverError.value = getLoginErrorMessage(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="login">
    <section class="login__card">
      <h1 class="login__title">
        Sign in
      </h1>
      <p class="login__subtitle">
        Rostering System
      </p>

      <form
        class="login__form"
        novalidate
        @submit.prevent="onSubmit"
      >
        <div
          v-if="serverError"
          class="login__alert"
          role="alert"
        >
          {{ serverError }}
        </div>

        <label class="login__field">
          <span class="login__label">Email</span>
          <input
            v-model="email"
            type="email"
            name="email"
            autocomplete="email"
            class="login__input"
            :class="{ 'login__input--error': fieldErrors.email }"
            :aria-invalid="!!fieldErrors.email"
            :aria-describedby="fieldErrors.email ? 'email-error' : undefined"
          >
          <span
            v-if="fieldErrors.email"
            id="email-error"
            class="login__error"
          >
            {{ fieldErrors.email }}
          </span>
        </label>

        <label class="login__field">
          <span class="login__label">Password</span>
          <input
            v-model="password"
            type="password"
            name="password"
            autocomplete="current-password"
            class="login__input"
            :class="{ 'login__input--error': fieldErrors.password }"
            :aria-invalid="!!fieldErrors.password"
            :aria-describedby="fieldErrors.password ? 'password-error' : undefined"
          >
          <span
            v-if="fieldErrors.password"
            id="password-error"
            class="login__error"
          >
            {{ fieldErrors.password }}
          </span>
        </label>

        <button
          type="submit"
          class="login__submit"
          :disabled="!canSubmit"
        >
          {{ submitting ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
    </section>
  </main>
</template>
