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
    <p v-if="auth.user">Welcome, {{ auth.user.name }}.</p>
    <p v-else>Vue client is running.</p>
    <button type="button" class="home__logout" @click="onLogout">Log out</button>
  </main>
</template>

<style scoped lang="scss">
.home {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  min-height: 100vh;
  margin: 0;
  font-family: system-ui, sans-serif;
}

.home__logout {
  margin-top: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  color: #334155;
  cursor: pointer;
  background: transparent;
  border: 1px solid #cbd5e1;
  border-radius: 0.5rem;

  &:hover {
    background: #f1f5f9;
  }
}
</style>
