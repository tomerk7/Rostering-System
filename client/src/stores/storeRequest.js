import { extractValidationErrors, resolveErrorMessage } from '@/lib/apiError'

/**
 * Run a store async request with shared loading toggling and error handling.
 *
 * @param {{ clearErrors: () => void, error: string, validationErrors: object }} store
 * @param {{ loadingKey: string, loadingValue?: *, idleValue?: *, request: () => Promise<*>, fallback: string, failureValue?: * }} options
 * @returns {Promise<*>}
 */
export async function runStoreRequest(store, options) {
  const {
    loadingKey,
    loadingValue = true,
    idleValue = false,
    request,
    fallback,
    failureValue = null,
  } = options

  store[loadingKey] = loadingValue
  store.clearErrors()

  try {
    return await request()
  } catch (error) {
    store.validationErrors = extractValidationErrors(error)
    store.error = resolveErrorMessage(error, fallback)
    return failureValue
  } finally {
    store[loadingKey] = idleValue
  }
}
