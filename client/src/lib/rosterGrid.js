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
 * Build ISO date strings for every day in a calendar month.
 *
 * @param {number} year
 * @param {number} month
 * @returns {string[]}
 */
export function getDatesInMonth(year, month) {
  const daysInMonth = new Date(year, month, 0).getDate()
  const dates = []

  for (let day = 1; day <= daysInMonth; day++) {
    dates.push(`${year}-${padMonthDay(month)}-${padMonthDay(day)}`)
  }

  return dates
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
 * Index coverage shortages by date, shift, and role.
 *
 * @param {object[]} shortages
 * @returns {Map<string, object>}
 */
function buildShortageMap(shortages) {
  const map = new Map()

  for (const shortage of shortages) {
    map.set(shortageKey(shortage.work_date, shortage.shift_id, shortage.role_id), shortage)
  }

  return map
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
 * Derive a role code from a display name when worker metadata is missing.
 *
 * @param {string} roleName
 * @returns {string}
 */
function roleCodeFromName(roleName) {
  if (!roleName) {
    return 'unknown'
  }

  const normalized = roleName.toLowerCase()

  if (normalized.includes('general')) {
    return 'general_guard'
  }

  if (normalized.includes('screen')) {
    return 'screener'
  }

  if (normalized.includes('super')) {
    return 'supervisor'
  }

  return normalized.replace(/\s+/g, '_')
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
        roleCode: worker?.role.code ?? roleCodeFromName(assignment.role_name),
        roleName: worker?.role.name ?? assignment.role_name ?? 'Unknown',
        source: assignment.source,
      }
    })
    .sort((left, right) => left.roleName.localeCompare(right.roleName) || left.workerName.localeCompare(right.workerName))
}

/**
 * Build the full month grid model for roster display and editing.
 *
 * @param {{ year: number, month: number, shifts: object[], requirements: object[], roles: object[], assignments: object[], reports: object, workersById: Map<number|string, object> }} params
 * @returns {object}
 */
export function buildRosterGrid(params) {
  const {
    year,
    month,
    shifts,
    requirements,
    roles,
    assignments,
    reports,
    workersById,
  } = params

  const rolesById = new Map(roles.map((role) => [role.id, role]))
  const requirementsByShift = buildRequirementsByShift(requirements, rolesById)
  const shortageMap = buildShortageMap(reports.coverage_shortages)
  const assignmentCounts = countAssignmentsBySlotRole(assignments, workersById)
  const sortedShifts = [...shifts].sort((left, right) => left.code.localeCompare(right.code))

  const rows = getDatesInMonth(year, month).map((workDate) => {
    const shiftCells = sortedShifts.map((shift) => {
      const shiftRequirements = requirementsByShift.get(shift.id) ?? []

      const roleDemands = shiftRequirements.map((requirement) => {
        const key = shortageKey(workDate, shift.id, requirement.role_id)
        const shortage = shortageMap.get(key)
        const assigned = shortage?.assigned ?? assignmentCounts.get(key) ?? 0
        const required = shortage?.required ?? requirement.required_count

        return {
          roleId: requirement.role_id,
          roleCode: requirement.role.code,
          roleName: requirement.role.name,
          required,
          assigned,
          shortage: Math.max(required - assigned, 0),
        }
      })

      const isUnderstaffed = roleDemands.some((role) => role.shortage > 0)

      return {
        shiftId: shift.id,
        shiftCode: shift.code,
        shiftLabel: shift.label,
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
    year,
    month,
    monthLabel: formatMonthYear(year, month),
    rows,
  }
}

/**
 * Format role demand counts as a compact summary string.
 *
 * @param {object[]} roles
 * @returns {string}
 */
export function formatDemandSummary(roles) {
  return roles
    .map((role) => {
      const shortCode = role.roleCode === 'general_guard'
        ? 'G'
        : role.roleCode === 'screener'
          ? 'S'
          : role.roleCode === 'supervisor'
            ? 'Sup'
            : role.roleCode.slice(0, 3)

      return `${role.required} ${shortCode}`
    })
    .join(' / ')
}
