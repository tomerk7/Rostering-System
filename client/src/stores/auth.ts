import { defineStore } from 'pinia'
import api from '@/lib/axios'
import { isAxiosError } from 'axios'

export interface User {
  id: number
  name: string
  email: string
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as User | null,
  }),

  getters: {
    isAuthenticated: (state) => state.user !== null,
  },

  actions: {
    async csrf() {
      await api.get('/sanctum/csrf-cookie')
    },

    async login(email: string, password: string) {
      await this.csrf()
      await api.post('/login', { email, password })
      await this.fetchUser()
    },

    async fetchUser() {
      try {
        const { data } = await api.get<User>('/api/user')
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

export function getLoginErrorMessage(error: unknown): string {
  if (isAxiosError(error)) {
    const data = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined

    if (data?.errors?.email?.[0]) {
      return data.errors.email[0]
    }

    if (data?.message) {
      return data.message
    }
  }

  return 'Login failed. Please check your credentials and try again.'
}
