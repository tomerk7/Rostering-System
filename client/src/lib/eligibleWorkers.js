export const MAX_SHIFTS_PER_DAY = 2

function dayOfWeekForDate(workDate) {
  return new Date(`${workDate}T00:00:00`).getDay()
}

function isAvailableForSlot(worker, workDate, shiftId) {
  const dayOfWeek = dayOfWeekForDate(workDate)

  return (worker.contract?.availability ?? []).some(
    (slot) => slot.day_of_week === dayOfWeek && slot.shift.id === shiftId,
  )
}

function assignedHoursForWorker(assignments, workerId, shiftsById) {
  return assignments
    .filter((assignment) => assignment.worker_id === workerId)
    .reduce((total, assignment) => {
      const shift = shiftsById.get(assignment.shift_id)

      return total + (shift?.duration_hours ?? 0)
    }, 0)
}

function shiftsOnDateForWorker(assignments, workerId, workDate) {
  return assignments.filter(
    (assignment) => assignment.worker_id === workerId && assignment.work_date === workDate,
  ).length
}

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

export function filterEligibleWorkers(workers, options) {
  return workers.filter((worker) => isWorkerEligibleForAssignment(worker, options))
}
