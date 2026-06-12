const LABELS_BY_CODE = {
  A: 'morning',
  B: 'day',
  C: 'evening',
}

/**
 * Human-readable shift label derived from the stable shift code.
 *
 * @param {{ code?: string }|null|undefined} shift
 * @returns {string}
 */
export function shiftLabel(shift) {
  if (!shift?.code) {
    return ''
  }

  return LABELS_BY_CODE[shift.code] ?? shift.code
}
