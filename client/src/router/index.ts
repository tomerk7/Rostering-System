import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '@/views/HomeView.vue'
import LoginView from '@/views/LoginView.vue'
import RosterDetailsView from '@/views/RosterDetailsView.vue'
import RosterGenerateView from '@/views/RosterGenerateView.vue'
import RostersView from '@/views/RostersView.vue'
import WorkerFormView from '@/views/WorkerFormView.vue'
import WorkersView from '@/views/WorkersView.vue'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
      meta: { requiresAuth: true },
    },
    {
      path: '/workers',
      name: 'workers',
      component: WorkersView,
      meta: { requiresAuth: true },
    },
    {
      path: '/workers/create',
      name: 'workers.create',
      component: WorkerFormView,
      meta: { requiresAuth: true },
    },
    {
      path: '/workers/:id/edit',
      name: 'workers.edit',
      component: WorkerFormView,
      meta: { requiresAuth: true },
    },
    {
      path: '/rosters/generate',
      name: 'rosters.generate',
      component: RosterGenerateView,
      meta: { requiresAuth: true },
    },
    {
      path: '/rosters',
      name: 'rosters',
      component: RostersView,
      meta: { requiresAuth: true },
    },
    {
      path: '/rosters/:id',
      name: 'rosters.show',
      component: RosterDetailsView,
      meta: { requiresAuth: true },
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { requiresGuest: true },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      redirect: { name: 'home' },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // Ensure the session is hydrated before guarding. `ready` is set after the
  // first fetch, so this only fetches once and won't double-fetch on startup.
  if (!auth.ready) {
    await auth.fetchUser()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.requiresGuest && auth.isAuthenticated) {
    return { name: 'home' }
  }
})

export default router
