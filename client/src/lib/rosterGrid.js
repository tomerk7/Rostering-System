/**
 * Pad a month or day number to two digits.
 *
 * @param {number|string} value
 * @returns {string}
 */
function padMonthDay(value) {
  return String(value).padStart(2, '0')
}

/**
 * Convert a local Date to an ISO calendar date.
 *
 * @param {Date} date
 * @returns {string}
 */
function toIsoDate(date) {
  return [
    date.getFullYear(),
    padMonthDay(date.getMonth() + 1),
    padMonthDay(date.getDate()),
  ].join('-')
}

/**
 * Add calendar days to an ISO date.
 *
 * @param {string} isoDate
 * @param {number} days
 * @returns {string}
 */
export function addDays(isoDate, days) {
  const date = new Date(`${isoDate}T00:00:00`)
  date.setDate(date.getDate() + days)

  return toIsoDate(date)
}

/**
 * Return the first and last dates of a roster month.
 *
 * @param {number} year
 * @param {number} month
 * @returns {{ startDate: string, endDate: string }}
 */
export function getMonthRange(year, month) {
  return {
    startDate: toIsoDate(new Date(year, month - 1, 1)),
    endDate: toIsoDate(new Date(year, month, 0)),
  }
}

/**
 * Return the seven-day roster page containing an anchor, starting from day one.
 *
 * @param {number} year
 * @param {number} month
 * @param {string} anchorDate
 * @returns {{ startDate: string, endDate: string }}
 */
export function getRosterWeekRange(year, month, anchorDate) {
  const monthRange = getMonthRange(year, month)
  const monthStart = new Date(`${monthRange.startDate}T00:00:00`)
  const monthEnd = new Date(`${monthRange.endDate}T00:00:00`)
  let anchor = new Date(`${anchorDate}T00:00:00`)

  if (anchor < monthStart || anchor > monthEnd) {
    anchor = monthStart
  }

  const dayOffset = Math.floor((anchor.getDate() - 1) / 7) * 7
  const weekStart = new Date(monthStart)
  weekStart.setDate(weekStart.getDate() + dayOffset)
  const weekEnd = new Date(weekStart)
  weekEnd.setDate(weekEnd.getDate() + 6)

  return {
    startDate: toIsoDate(weekStart),
    endDate: toIsoDate(weekEnd > monthEnd ? monthEnd : weekEnd),
  }
}

/**
 * Build ISO date strings for an inclusive date range.
 *
 * @param {string} startDate
 * @param {string} endDate
 * @returns {string[]}
 */
export function getDatesBetween(startDate, endDate) {
  const dates = []
  const current = new Date(`${startDate}T00:00:00`)
  const end = new Date(`${endDate}T00:00:00`)

  while (current <= end) {
    dates.push(toIsoDate(current))
    current.setDate(current.getDate() + 1)
  }

  return dates
}

/**
 * Format an inclusive date range for the roster heading.
 *
 * @param {string} startDate
 * @param {string} endDate
 * @returns {string}
 */
export function formatDateRange(startDate, endDate) {
  const start = new Date(`${startDate}T00:00:00`)
  const end = new Date(`${endDate}T00:00:00`)
  const sameYear = start.getFullYear() === end.getFullYear()

  const startLabel = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: sameYear ? undefined : 'numeric',
  }).format(start)
  const endLabel = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(end)

  return `${startLabel} - ${endLabel}`
}

/**
 * Format a year and month as a human-readable label.
 *
 * @param {number} year
 * @param {number} month
 * @returns {string}
 */
export function formatMonthYear(year, month) {
  return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(
    new Date(year, month - 1, 1),
  )
}

/**
 * Format a work date as a short weekday and calendar label.
 *
 * @param {string} workDate
 * @returns {string}
 */
export function formatWorkDateLabel(workDate) {
  const date = new Date(`${workDate}T00:00:00`)

  return new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
  }).format(date)
}

/**
 * Build a unique key for a date/shift/role coverage slot.
 *
 * @param {string} workDate
 * @param {number} shiftId
 * @param {number} roleId
 * @returns {string}
 */
function shortageKey(workDate, shiftId, roleId) {
  return `${workDate}|${shiftId}|${roleId}`
}


/**
 * Group staffing requirements by shift with resolved role metadata.
 *
 * @param {object[]} requirements
 * @param {Map<number, object>} rolesById
 * @returns {Map<number, object[]>}
 */
function buildRequirementsByShift(requirements, rolesById) {
  const map = new Map()

  for (const requirement of requirements) {
    const existing = map.get(requirement.shift_id) ?? []
    existing.push({
      ...requirement,
      role: requirement.role ?? rolesById.get(requirement.role_id) ?? {
        id: requirement.role_id,
        code: 'unknown',
        name: 'Unknown',
      },
    })
    map.set(requirement.shift_id, existing)
  }

  for (const [, shiftRequirements] of map) {
    shiftRequirements.sort((left, right) => left.role.name.localeCompare(right.role.name))
  }

  return map
}

/**
 * Resolve the role id for an assignment from its data or worker lookup.
 *
 * @param {object} assignment
 * @param {Map<number|string, object>} workersById
 * @returns {number|null}
 */
function resolveRoleId(assignment, workersById) {
  return assignment.role_id ?? workersById.get(assignment.worker_id)?.role.id ?? null
}

/**
 * Count assignments grouped by date, shift, and role.
 *
 * @param {object[]} assignments
 * @param {Map<number|string, object>} workersById
 * @returns {Map<string, number>}
 */
function countAssignmentsBySlotRole(assignments, workersById) {
  const counts = new Map()

  for (const assignment of assignments) {
    const roleId = resolveRoleId(assignment, workersById)
    if (!roleId) {
      continue
    }

    const key = shortageKey(assignment.work_date, assignment.shift_id, roleId)
    counts.set(key, (counts.get(key) ?? 0) + 1)
  }

  return counts
}

/**
 * Build display-ready assignment rows for a date and shift cell.
 *
 * @param {object[]} assignments
 * @param {string} workDate
 * @param {number} shiftId
 * @param {Map<number|string, object>} workersById
 * @returns {object[]}
 */
function buildAssignmentsForCell(assignments, workDate, shiftId, workersById) {
  return assignments
    .filter((assignment) => assignment.work_date === workDate && assignment.shift_id === shiftId)
    .map((assignment) => {
      const worker = workersById.get(assignment.worker_id)

      return {
        assignmentId: assignment.id ?? undefined,
        workerId: assignment.worker_id,
        workerName:
          worker?.full_name
          ?? assignment.worker_name
          ?? `Worker #${assignment.worker_id}`,
        roleName: worker?.role.name ?? assignment.role_name ?? 'Unknown',
        source: assignment.source,
      }
    })
    .sort((left, right) => left.roleName.localeCompare(right.roleName) || left.workerName.localeCompare(right.workerName))
}

/**
 * Build the visible date-range grid model for roster display and editing.
 *
 * @param {{ startDate: string, endDate: string, shifts: object[], requirements: object[], roles: object[], assignments: object[], workersById: Map<number|string, object> }} params
 * @returns {object}
 */
export function buildRosterGrid(params) {
  const {
    startDate,
    endDate,
    shifts,
    requirements,
    roles,
    assignments,
    workersById,
  } = params

  const rolesById = new Map(roles.map((role) => [role.id, role]))
  const requirementsByShift = buildRequirementsByShift(requirements, rolesById)
  const assignmentCounts = countAssignmentsBySlotRole(assignments, workersById)
  const sortedShifts = [...shifts].sort((left, right) => left.code.localeCompare(right.code))

  const rows = getDatesBetween(startDate, endDate).map((workDate) => {
    const shiftCells = sortedShifts.map((shift) => {
      const shiftRequirements = requirementsByShift.get(shift.id) ?? []

      const roleDemands = shiftRequirements.map((requirement) => {
        const key = shortageKey(workDate, shift.id, requirement.role_id)
        const required = requirement.required_count
        // Count live assignments rather than a server snapshot, so a slot's
        // filled count is always current after an add/remove.
        const assigned = assignmentCounts.get(key) ?? 0

        return {
          roleId: requirement.role_id,
          roleName: requirement.role.name,
          required,
          assigned,
          shortage: Math.max(required - assigned, 0),
        }
      })

      const isUnderstaffed = roleDemands.some((role) => role.shortage > 0)

      return {
        shiftId: shift.id,
        roles: roleDemands,
        assignments: buildAssignmentsForCell(assignments, workDate, shift.id, workersById),
        isUnderstaffed,
      }
    })

    return {
      workDate,
      dayLabel: formatWorkDateLabel(workDate),
      shifts: shiftCells,
    }
  })

  return {
    rangeLabel: formatDateRange(startDate, endDate),
    rows,
  }
}
