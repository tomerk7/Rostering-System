import { defineStore } from 'pinia'
import api from '@/lib/axios'
import { isAxiosError } from 'axios'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    ready: false,
  }),

  getters: {
    /**
     * Whether a user session is currently loaded.
     *
     * @param {object} state
     * @returns {boolean}
     */
    isAuthenticated: (state) => state.user !== null,
  },

  actions: {
    /**
     * Fetch the Sanctum CSRF cookie before authenticated requests.
     *
     * @returns {Promise<void>}
     */
    async csrf() {
      await api.get('/sanctum/csrf-cookie')
    },

    /**
     * Authenticate with email and password, then load the current user.
     *
     * @param {string} email
     * @param {string} password
     * @returns {Promise<void>}
     */
    async login(email, password) {
      await this.csrf()
      await api.post('/login', { email, password })
      await this.fetchUser()
    },

    /**
     * Load the authenticated user, or clear the session on failure.
     *
     * @returns {Promise<void>}
     */
    async fetchUser() {
      try {
        const { data } = await api.get('/api/user')
        this.user = data
      } catch {
        this.user = null
      }
    },

    /**
     * Log out the current user and clear local session state.
     *
     * @returns {Promise<void>}
     */
    async logout() {
      try {
        await api.post('/logout')
      } finally {
        this.user = null
      }
    },
  },
})

/**
 * Resolve a user-facing login error message from an API failure.
 *
 * @param {unknown} error
 * @returns {string}
 */
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
