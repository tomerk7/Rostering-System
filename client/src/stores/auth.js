import { defineStore } from 'pinia'
import api, { TOKEN_KEY } from '@/lib/axios'
import { isAxiosError } from 'axios'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    ready: false,
    token: localStorage.getItem(TOKEN_KEY) || null,
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
     * Persist (or clear) the JWT in state + localStorage.
     *
     * @param {string|null} token
     * @returns {void}
     */
    setToken(token) {
      this.token = token
      if (token) {
        localStorage.setItem(TOKEN_KEY, token)
      } else {
        localStorage.removeItem(TOKEN_KEY)
      }
    },

    /**
     * Authenticate with email and password (JWT), storing the token + user.
     *
     * @param {string} email
     * @param {string} password
     * @returns {Promise<void>}
     */
    async login(email, password) {
      const { data } = await api.post('/api/auth/login', { email, password })
      this.setToken(data.token)
      this.user = data.user
      this.ready = true
    },

    /**
     * Load the current user from the stored token, or clear it on failure.
     *
     * @returns {Promise<void>}
     */
    async fetchUser() {
      if (!this.token) {
        this.user = null
        this.ready = true
        return
      }

      try {
        const { data } = await api.get('/api/auth/me')
        this.user = data.user
      } catch {
        this.setToken(null)
        this.user = null
      } finally {
        this.ready = true
      }
    },

    /**
     * Log out: clear the token and local session state (JWT is stateless).
     *
     * @returns {Promise<void>}
     */
    async logout() {
      this.setToken(null)
      this.user = null
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
