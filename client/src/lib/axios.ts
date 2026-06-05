import axios from 'axios'

function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))
  return match?.[2] ? decodeURIComponent(match[2]) : null
}

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = getCookie('XSRF-TOKEN')
  if (token) {
    config.headers.set('X-XSRF-TOKEN', token)
  }
  return config
})

export default api
