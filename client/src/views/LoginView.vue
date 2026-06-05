<script setup lang="ts">
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

function validate(): boolean {
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
      <h1 class="login__title">Sign in</h1>
      <p class="login__subtitle">Rostering System</p>

      <form class="login__form" novalidate @submit.prevent="onSubmit">
        <div v-if="serverError" class="login__alert" role="alert">
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
          />
          <span v-if="fieldErrors.email" id="email-error" class="login__error">
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
          />
          <span v-if="fieldErrors.password" id="password-error" class="login__error">
            {{ fieldErrors.password }}
          </span>
        </label>

        <button type="submit" class="login__submit" :disabled="!canSubmit">
          {{ submitting ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
    </section>
  </main>
</template>

<style scoped lang="scss">
.login {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  margin: 0;
  padding: 1.5rem;
  font-family: system-ui, sans-serif;
  background: #f4f6f8;
}

.login__card {
  width: 100%;
  max-width: 24rem;
  padding: 2rem;
  background: #fff;
  border-radius: 0.75rem;
  box-shadow: 0 4px 24px rgb(15 23 42 / 8%);
}

.login__title {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
  font-weight: 600;
  color: #0f172a;
}

.login__subtitle {
  margin: 0 0 1.5rem;
  font-size: 0.875rem;
  color: #64748b;
}

.login__form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.login__alert {
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  color: #991b1b;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 0.5rem;
}

.login__field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.login__label {
  font-size: 0.875rem;
  font-weight: 500;
  color: #334155;
}

.login__input {
  padding: 0.625rem 0.75rem;
  font-size: 1rem;
  color: #0f172a;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 0.5rem;
  transition: border-color 0.15s ease;

  &:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgb(59 130 246 / 20%);
  }

  &--error {
    border-color: #f87171;
  }
}

.login__error {
  font-size: 0.8125rem;
  color: #dc2626;
}

.login__submit {
  margin-top: 0.5rem;
  padding: 0.625rem 1rem;
  font-size: 1rem;
  font-weight: 500;
  color: #fff;
  cursor: pointer;
  background: #2563eb;
  border: none;
  border-radius: 0.5rem;
  transition: background 0.15s ease;

  &:hover:not(:disabled) {
    background: #1d4ed8;
  }

  &:disabled {
    cursor: not-allowed;
    opacity: 0.6;
  }
}
</style>
