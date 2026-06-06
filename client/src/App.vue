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
        :to="{ name: 'workers' }"
      >
        Workers
      </RouterLink>
      <RouterLink
        class="app-nav__link"
        :to="{ name: 'rosters' }"
      >
        Rosters
      </RouterLink>
    </nav>
    <div class="app-nav__actions">
      <span
        v-if="auth.user"
        class="app-nav__user"
      >{{ auth.user.name }}</span>
      <button
        type="button"
        class="button"
        @click="onLogout"
      >
        Log out
      </button>
    </div>
  </header>
  <router-view />
</template>
