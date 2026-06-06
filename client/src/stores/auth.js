import { defineStore } from 'pinia'
import api from '@/lib/axios'
import { isAxiosError } from 'axios'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    ready: false,
  }),

  getters: {
    isAuthenticated: (state) => state.user !== null,
  },

  actions: {
    async csrf() {
      await api.get('/sanctum/csrf-cookie')
    },

    async login(email, password) {
      await this.csrf()
      await api.post('/login', { email, password })
      await this.fetchUser()
    },

    async fetchUser() {
      try {
        const { data } = await api.get('/api/user')
        this.user = data
      } catch {
        this.user = null
      }
    },

    async logout() {
      try {
        await api.post('/logout')
      } finally {
        this.user = null
      }
    },
  },
})

export function getLoginErrorMessage(error) {
  if (isAxiosError(error)) {
    const data = error.response?.data

    if (data?.errors?.email?.[0]) {
      return data.errors.email[0]
    }

    if (data?.message) {
      return data.message
    }
  }

  return 'Login failed. Please check your credentials and try again.'
}
