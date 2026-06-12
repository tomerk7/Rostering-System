<script setup>
import { useAuthStore } from '@/stores/auth'
import Button from '@/components/ui/Button.vue'

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
      <Button
        variant="primary"
        :to="{ name: 'rosters.generate' }"
      >
        Generate roster
      </Button>
      <Button :to="{ name: 'workers' }">
        Manage workers
      </Button>
    </div>
  </main>
</template>

<style scoped>
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
