<script setup>
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
      <RouterLink
        class="button"
        :to="{ name: 'rosters.generate' }"
      >
        Generate roster
      </RouterLink>
    </div>
  </main>
</template>

<style scoped>
@import '@/assets/ui/button.css';

.home {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  min-height: 100vh;
  padding: 1.5rem;
  text-align: center;
}

.home h1,
.home p {
  margin: 0;
}

.home__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.75rem;
  margin-top: 0.5rem;
}
</style>
