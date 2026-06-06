function padMonthDay(value) {
  return String(value).padStart(2, '0')
}

export function getDatesInMonth(year, month) {
  const daysInMonth = new Date(year, month, 0).getDate()
  const dates = []

  for (let day = 1; day <= daysInMonth; day++) {
    dates.push(`${year}-${padMonthDay(month)}-${padMonthDay(day)}`)
  }

  return dates
}

export function formatMonthYear(year, month) {
  return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(
    new Date(year, month - 1, 1),
  )
}

export function formatWorkDateLabel(workDate) {
  const date = new Date(`${workDate}T00:00:00`)

  return new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
  }).format(date)
}

function shortageKey(workDate, shiftId, roleId) {
  return `${workDate}|${shiftId}|${roleId}`
}

function buildShortageMap(shortages) {
  const map = new Map()

  for (const shortage of shortages) {
    map.set(shortageKey(shortage.work_date, shortage.shift_id, shortage.role_id), shortage)
  }

  return map
}

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

function resolveRoleId(assignment, workersById) {
  return assignment.role_id ?? workersById.get(assignment.worker_id)?.role.id ?? null
}

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
