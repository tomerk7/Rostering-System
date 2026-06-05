<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
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
    </div>
  </main>
</template>
