import { isAxiosError } from 'axios'

const MAX_READABLE_LENGTH = 200

const RAW_ERROR_PATTERN = /SQLSTATE|Exception|stack trace|#\d+\s|::\w+\(|\bat \/|\n/i

/**
 * Pull Laravel-style field validation errors (422) out of an axios error.
 *
 * @param {unknown} error
 * @returns {Record<string, string[]>}
 */
export function extractValidationErrors(error) {
  if (isAxiosError(error) && error.response?.status === 422) {
    return error.response.data?.errors ?? {}
  }

  return {}
}

/**
 * Resolve a short, human-readable message from an axios error.
 *
 * Server-provided messages are only trusted for client (4xx) responses and
 * only when they look like a real sentence, so raw 5xx stack traces and SQL
 * dumps never leak into the UI.
 *
 * @param {unknown} error
 * @param {string} fallback
 * @returns {string}
 */
export function resolveErrorMessage(error, fallback = 'Something went wrong. Please try again.') {
  if (!isAxiosError(error)) {
    return fallback
  }

  const response = error.response

  if (!response) {
    return 'Network error. Please check your connection and try again.'
  }

  const status = response.status
  const data = response.data ?? {}

  if (status === 422) {
    const firstField = data.errors ? Object.values(data.errors)[0] : null
    if (Array.isArray(firstField) && firstField.length > 0) {
      return String(firstField[0])
    }
  }

  if (status >= 400 && status < 500 && isReadableMessage(data.message)) {
    return String(data.message)
  }

  return fallback
}

/**
 * Decide whether a server message is safe to show directly to the user.
 *
 * @param {unknown} message
 * @returns {boolean}
 */
function isReadableMessage(message) {
  if (typeof message !== 'string') {
    return false
  }

  const trimmed = message.trim()

  if (trimmed === '' || trimmed.length > MAX_READABLE_LENGTH) {
    return false
  }

  return !RAW_ERROR_PATTERN.test(trimmed)
}
