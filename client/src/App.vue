<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Button from '@/components/ui/Button.vue'

const auth = useAuthStore()
const router = useRouter()

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <header
    v-if="auth.isAuthenticated"
    class="app-nav"
  >
    <RouterLink
      class="app-nav__brand"
      :to="{ name: 'home' }"
    >
      Rostering System
    </RouterLink>
    <nav class="app-nav__links">
      <RouterLink
        class="app-nav__link"
        :to="{ name: 'rosters' }"
      >
        Rosters
      </RouterLink>
      <RouterLink
        class="app-nav__link"
        :to="{ name: 'workers' }"
      >
        Workers
      </RouterLink>
      <RouterLink
        class="app-nav__link"
        :to="{ name: 'rosters.benchmark' }"
      >
        Benchmark
      </RouterLink>
    </nav>
    <div class="app-nav__actions">
      <span
        v-if="auth.user"
        class="app-nav__user"
      >{{ auth.user.name }}</span>
      <Button @click="onLogout">
        Log out
      </Button>
    </div>
  </header>
  <router-view />
</template>

<style scoped>
.app-nav {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem clamp(1rem, 4vw, 2rem);
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
}

.app-nav__brand {
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
}

.app-nav__links {
  display: flex;
  gap: 0.25rem;
}

.app-nav__link {
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: #475569;
  border-radius: 0.5rem;
}

.app-nav__link:hover,
.app-nav__link.router-link-active {
  color: #2563eb;
  background: #eff6ff;
}

.app-nav__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-left: auto;
}

.app-nav__user {
  font-size: 0.875rem;
  font-weight: 600;
  color: #334155;
}
</style>
