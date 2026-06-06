import type {
  CoverageShortage,
  RosterAssignment,
  RosterPreviewAssignment,
  RosterReports,
} from '@/api/rosters'
import type { ReferenceRole, ShiftRoleRequirement, Worker, WorkerShift } from '@/api/workers'

export interface GridRoleDemand {
  roleId: number
  roleCode: string
  roleName: string
  required: number
  assigned: number
  shortage: number
}

export interface GridAssignment {
  assignmentId?: number
  workerId: number
  workerName: string
  roleCode: string
  roleName: string
  source: 'auto' | 'manual'
}

export interface GridShiftCell {
  shiftId: number
  shiftCode: string
  shiftLabel: string
  roles: GridRoleDemand[]
  assignments: GridAssignment[]
  isUnderstaffed: boolean
}

export interface GridDayRow {
  workDate: string
  dayLabel: string
  shifts: GridShiftCell[]
}

export interface RosterGridData {
  year: number
  month: number
  monthLabel: string
  rows: GridDayRow[]
}

type AnyAssignment = RosterAssignment | RosterPreviewAssignment

function padMonthDay(value: number): string {
  return String(value).padStart(2, '0')
}

export function getDatesInMonth(year: number, month: number): string[] {
  const daysInMonth = new Date(year, month, 0).getDate()
  const dates: string[] = []

  for (let day = 1; day <= daysInMonth; day++) {
    dates.push(`${year}-${padMonthDay(month)}-${padMonthDay(day)}`)
  }

  return dates
}

export function formatMonthYear(year: number, month: number): string {
  return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(
    new Date(year, month - 1, 1),
  )
}

export function formatWorkDateLabel(workDate: string): string {
  const date = new Date(`${workDate}T00:00:00`)

  return new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
  }).format(date)
}

function shortageKey(workDate: string, shiftId: number, roleId: number): string {
  return `${workDate}|${shiftId}|${roleId}`
}

function buildShortageMap(shortages: CoverageShortage[]): Map<string, CoverageShortage> {
  const map = new Map<string, CoverageShortage>()

  for (const shortage of shortages) {
    map.set(shortageKey(shortage.work_date, shortage.shift_id, shortage.role_id), shortage)
  }

  return map
}

function buildRequirementsByShift(
  requirements: ShiftRoleRequirement[],
  rolesById: Map<number, ReferenceRole>,
): Map<number, ShiftRoleRequirement[]> {
  const map = new Map<number, ShiftRoleRequirement[]>()

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

function countAssignmentsBySlotRole(
  assignments: AnyAssignment[],
  workersById: Map<number, Worker>,
): Map<string, number> {
  const counts = new Map<string, number>()

  for (const assignment of assignments) {
    const worker = workersById.get(assignment.worker_id)
    if (!worker?.role.id) {
      continue
    }

    const key = shortageKey(assignment.work_date, assignment.shift_id, worker.role.id)
    counts.set(key, (counts.get(key) ?? 0) + 1)
  }

  return counts
}

function buildAssignmentsForCell(
  assignments: AnyAssignment[],
  workDate: string,
  shiftId: number,
  workersById: Map<number, Worker>,
): GridAssignment[] {
  return assignments
    .filter((assignment) => assignment.work_date === workDate && assignment.shift_id === shiftId)
    .map((assignment) => {
      const worker = workersById.get(assignment.worker_id)

      const savedWorkerName = 'id' in assignment ? assignment.worker?.full_name : null

      return {
        assignmentId: 'id' in assignment ? assignment.id : undefined,
        workerId: assignment.worker_id,
        workerName: worker?.full_name ?? savedWorkerName ?? `Worker #${assignment.worker_id}`,
        roleCode: worker?.role.code ?? 'unknown',
        roleName: worker?.role.name ?? 'Unknown',
        source: assignment.source,
      }
    })
    .sort((left, right) => left.roleName.localeCompare(right.roleName) || left.workerName.localeCompare(right.workerName))
}

export function buildRosterGrid(params: {
  year: number
  month: number
  shifts: WorkerShift[]
  requirements: ShiftRoleRequirement[]
  roles: ReferenceRole[]
  assignments: AnyAssignment[]
  reports: RosterReports
  workersById: Map<number, Worker>
}): RosterGridData {
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

  const rows = getDatesInMonth(year, month).map((workDate): GridDayRow => {
    const shiftCells = sortedShifts.map((shift): GridShiftCell => {
      const shiftRequirements = requirementsByShift.get(shift.id) ?? []

      const roleDemands = shiftRequirements.map((requirement): GridRoleDemand => {
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

export function formatDemandSummary(roles: GridRoleDemand[]): string {
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