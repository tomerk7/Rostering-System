import { isAxiosError } from 'axios'
import { defineStore } from 'pinia'
import {
  addAssignment,
  changeAssignment,
  removeAssignment,
  type AddAssignmentPayload,
} from '@/api/rosterAssignments'
import {
  createRoster,
  getRoster,
  listRosters,
  previewRoster,
  publishRoster,
  type Roster,
  type RosterAssignment,
  type RosterPreview,
  type RosterPreviewAssignment,
  type RosterReports,
  type RosterSummary,
} from '@/api/rosters'

const emptyReports: RosterReports = {
  coverage_shortages: [],
  hours_shortfalls: [],
}

function extractValidationErrors(error: unknown): Record<string, string[]> {
  if (isAxiosError(error) && error.response?.status === 422) {
    const data = error.response.data as { errors?: Record<string, string[]> }
    return data.errors ?? {}
  }

  return {}
}

function currentMonthYear(): { year: number; month: number } {
  const now = new Date()
  return { year: now.getFullYear(), month: now.getMonth() + 1 }
}

export const useRostersStore = defineStore('rosters', {
  state: () => {
    const { year, month } = currentMonthYear()

    return {
      rosters: [] as Roster[],
      roster: null as Roster | null,
      preview: null as RosterPreview | null,
      selectedYear: year,
      selectedMonth: month,
      loading: false,
      previewing: false,
      saving: false,
      publishing: false,
      assignmentLoading: false,
      error: '',
      validationErrors: {} as Record<string, string[]>,
    }
  },

  getters: {
    assignments(state): RosterAssignment[] | RosterPreviewAssignment[] {
      return state.roster?.assignments ?? state.preview?.assignments ?? []
    },

    reports(state): RosterReports {
      return state.roster?.reports ?? state.preview?.reports ?? emptyReports
    },

    alerts(): RosterReports {
      return this.reports
    },

    summary(state): RosterSummary | null {
      return state.roster?.summary ?? state.preview?.summary ?? null
    },
  },

  actions: {
    clearErrors() {
      this.error = ''
      this.validationErrors = {}
    },

    setSelectedMonth(year: number, month: number) {
      this.selectedYear = year
      this.selectedMonth = month
    },

    async fetchRosters() {
      this.loading = true
      this.clearErrors()

      try {
        const response = await listRosters()
        this.rosters = response.data
      } catch {
        this.error = 'Could not load rosters. Please try again.'
      } finally {
        this.loading = false
      }
    },

    async fetchRoster(rosterId: number) {
      this.loading = true
      this.clearErrors()

      try {
        const response = await getRoster(rosterId)
        this.roster = response.data
        this.preview = null
        this.selectedYear = response.data.year
        this.selectedMonth = response.data.month
      } catch {
        this.error = 'Could not load roster. Please try again.'
      } finally {
        this.loading = false
      }
    },

    async generatePreview(year?: number, month?: number) {
      const targetYear = year ?? this.selectedYear
      const targetMonth = month ?? this.selectedMonth

      this.previewing = true
      this.clearErrors()

      try {
        const response = await previewRoster({ year: targetYear, month: targetMonth })
        this.preview = response.data
        this.roster = null
        this.selectedYear = response.data.year
        this.selectedMonth = response.data.month
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.year?.[0]
          ?? this.validationErrors.month?.[0]
          ?? 'Could not generate roster preview. Please try again.'
      } finally {
        this.previewing = false
      }
    },

    async saveDraft(year?: number, month?: number) {
      const targetYear = year ?? this.selectedYear
      const targetMonth = month ?? this.selectedMonth

      this.saving = true
      this.clearErrors()

      try {
        const response = await createRoster({ year: targetYear, month: targetMonth })
        this.roster = response.data
        this.preview = null
        this.selectedYear = response.data.year
        this.selectedMonth = response.data.month
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.year?.[0]
          ?? this.validationErrors.month?.[0]
          ?? 'Could not save roster draft. Please try again.'
        return null
      } finally {
        this.saving = false
      }
    },

    async publish(rosterId?: number) {
      const id = rosterId ?? this.roster?.id

      if (!id) {
        this.error = 'No roster selected to publish.'
        return null
      }

      this.publishing = true
      this.clearErrors()

      try {
        const response = await publishRoster(id)
        this.roster = response.data
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.roster?.[0] ?? 'Could not publish roster. Please try again.'
        return null
      } finally {
        this.publishing = false
      }
    },

    async publishFromPreview() {
      this.publishing = true
      this.clearErrors()

      try {
        const response = await createRoster({
          year: this.selectedYear,
          month: this.selectedMonth,
          publish: true,
        })
        this.roster = response.data
        this.preview = null
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.year?.[0]
          ?? this.validationErrors.month?.[0]
          ?? this.validationErrors.roster?.[0]
          ?? 'Could not publish roster. Please try again.'
        return null
      } finally {
        this.publishing = false
      }
    },

    async addManualAssignment(rosterId: number, payload: AddAssignmentPayload) {
      this.assignmentLoading = true
      this.clearErrors()

      try {
        const response = await addAssignment(rosterId, payload)
        this.roster = response.data
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = 'Could not add assignment. Please check the form and try again.'
        return null
      } finally {
        this.assignmentLoading = false
      }
    },

    async changeManualAssignment(rosterId: number, assignmentId: number, workerId: number) {
      this.assignmentLoading = true
      this.clearErrors()

      try {
        const response = await changeAssignment(rosterId, assignmentId, { worker_id: workerId })
        this.roster = response.data
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = 'Could not change assignment. Please check the form and try again.'
        return null
      } finally {
        this.assignmentLoading = false
      }
    },

    async removeManualAssignment(rosterId: number, assignmentId: number) {
      this.assignmentLoading = true
      this.clearErrors()

      try {
        const response = await removeAssignment(rosterId, assignmentId)
        this.roster = response.data
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.roster?.[0] ?? 'Could not remove assignment. Please try again.'
        return null
      } finally {
        this.assignmentLoading = false
      }
    },

    clearPreview() {
      this.preview = null
    },

    clearRoster() {
      this.roster = null
    },
  },
})