/** Maximum shifts a worker may be assigned on a single day. */
export const MAX_SHIFTS_PER_DAY = 2

/**
 * Get the day-of-week index (0–6) for an ISO date string.
 *
 * @param {string} workDate
 * @returns {number}
 */
function dayOfWeekForDate(workDate) {
  return new Date(`${workDate}T00:00:00`).getDay()
}

/**
 * Check whether a worker is available for a date and shift.
 *
 * @param {object} worker
 * @param {string} workDate
 * @param {number} shiftId
 * @returns {boolean}
 */
function isAvailableForSlot(worker, workDate, shiftId) {
  const dayOfWeek = dayOfWeekForDate(workDate)

  return (worker.contract?.availability ?? []).some(
    (slot) => slot.day_of_week === dayOfWeek && slot.shift.id === shiftId,
  )
}

/**
 * Sum assigned hours for a worker across all roster assignments.
 *
 * @param {object[]} assignments
 * @param {number|string} workerId
 * @param {Map<number, object>} shiftsById
 * @returns {number}
 */
function assignedHoursForWorker(assignments, workerId, shiftsById) {
  return assignments
    .filter((assignment) => assignment.worker_id === workerId)
    .reduce((total, assignment) => {
      const shift = shiftsById.get(assignment.shift_id)

      return total + (shift?.duration_hours ?? 0)
    }, 0)
}

/**
 * Count how many shifts a worker is assigned on a given date.
 *
 * @param {object[]} assignments
 * @param {number|string} workerId
 * @param {string} workDate
 * @returns {number}
 */
function shiftsOnDateForWorker(assignments, workerId, workDate) {
  return assignments.filter(
    (assignment) => assignment.worker_id === workerId && assignment.work_date === workDate,
  ).length
}

/**
 * Check whether a worker may be manually assigned to a slot.
 *
 * @param {object} worker
 * @param {{ workDate: string, shiftId: number, roleId?: number, assignments: object[], shiftsById: Map<number, object> }} options
 * @returns {boolean}
 */
export function isWorkerEligibleForAssignment(worker, { workDate, shiftId, roleId, assignments, shiftsById }) {
  if (!workDate || !shiftId || !worker.contract) {
    return false
  }

  if (roleId && worker.role.id !== roleId) {
    return false
  }

  if (!isAvailableForSlot(worker, workDate, shiftId)) {
    return false
  }

  if (assignments.some(
    (assignment) => assignment.worker_id === worker.israeli_id
      && assignment.work_date === workDate
      && assignment.shift_id === shiftId,
  )) {
    return false
  }

  if (shiftsOnDateForWorker(assignments, worker.israeli_id, workDate) >= MAX_SHIFTS_PER_DAY) {
    return false
  }

  const shift = shiftsById.get(shiftId)
  const maxHours = worker.contract.max_monthly_hours ?? 0
  const nextHours = assignedHoursForWorker(assignments, worker.israeli_id, shiftsById) + (shift?.duration_hours ?? 0)

  return nextHours <= maxHours
}

/**
 * Filter workers to those eligible for a manual assignment slot.
 *
 * @param {object[]} workers
 * @param {{ workDate: string, shiftId: number, roleId?: number, assignments: object[], shiftsById: Map<number, object> }} options
 * @returns {object[]}
 */
export function filterEligibleWorkers(workers, options) {
  return workers.filter((worker) => isWorkerEligibleForAssignment(worker, options))
}
