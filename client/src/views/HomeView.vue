<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <main class="home">
    <h1>Rostering System</h1>
    <p v-if="auth.user">
      Welcome, {{ auth.user.name }}.
    </p>
    <p v-else>
      Vue client is running.
    </p>
    <div class="home__actions">
      <RouterLink
        class="button button--primary"
        :to="{ name: 'workers' }"
      >
        Manage workers
      </RouterLink>
      <button
        type="button"
        class="button"
        @click="onLogout"
      >
        Log out
      </button>
    </div>
  </main>
</template>
