import axios from 'axios'

/** localStorage key for the JWT issued by /api/auth/login. */
export const TOKEN_KEY = 'auth_token'

/** Axios instance for the API. Auth is a Bearer JWT (no cookies/CSRF). */
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.set('Authorization', `Bearer ${token}`)
  }
  return config
})

export default api
