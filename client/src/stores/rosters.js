import { isAxiosError } from 'axios'
import { defineStore } from 'pinia'
import {
  addAssignment,
  changeAssignment,
  removeAssignment,
} from '@/api/rosterAssignments'
import {
  createRoster,
  deleteRoster,
  getRoster,
  listRosters,
  previewRoster,
  publishRoster,
} from '@/api/rosters'

const emptyReports = {
  coverage_shortages: [],
  hours_shortfalls: [],
}

function extractValidationErrors(error) {
  if (isAxiosError(error) && error.response?.status === 422) {
    const data = error.response.data
    return data.errors ?? {}
  }

  return {}
}

function currentMonthYear() {
  const now = new Date()
  return { year: now.getFullYear(), month: now.getMonth() + 1 }
}

export const useRostersStore = defineStore('rosters', {
  state: () => {
    const { year, month } = currentMonthYear()

    return {
      rosters: [],
      roster: null,
      preview: null,
      selectedYear: year,
      selectedMonth: month,
      loading: false,
      previewing: false,
      saving: false,
      publishing: false,
      deletingId: null,
      assignmentLoading: false,
      error: '',
      validationErrors: {},
    }
  },

  getters: {
    assignments(state) {
      return state.roster?.assignments ?? state.preview?.assignments ?? []
    },

    reports(state) {
      return state.roster?.reports ?? state.preview?.reports ?? emptyReports
    },

    alerts() {
      return this.reports
    },

    summary(state) {
      return state.roster?.summary ?? state.preview?.summary ?? null
    },
  },

  actions: {
    clearErrors() {
      this.error = ''
      this.validationErrors = {}
    },

    setSelectedMonth(year, month) {
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

    async fetchRoster(rosterId) {
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

    async generatePreview(year, month) {
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

    async saveDraft(year, month) {
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
          ?? 'Could not generate roster. Please try again.'
        return null
      } finally {
        this.saving = false
      }
    },

    async publish(rosterId) {
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

    async addManualAssignment(rosterId, payload) {
      this.assignmentLoading = true
      this.clearErrors()

      try {
        const response = await addAssignment(rosterId, payload)
        this.roster = response.data
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        if (isAxiosError(error) && error.response?.data?.message) {
          this.error = String(error.response.data.message)
        } else {
          this.error = 'Could not add assignment. Please check the form and try again.'
        }
        return null
      } finally {
        this.assignmentLoading = false
      }
    },

    async changeManualAssignment(rosterId, assignmentId, workerId) {
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

    async removeRoster(rosterId) {
      this.deletingId = rosterId
      this.clearErrors()

      try {
        await deleteRoster(rosterId)

        if (this.roster?.id === rosterId) {
          this.roster = null
        }

        this.rosters = this.rosters.filter((roster) => roster.id !== rosterId)
        return true
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.status?.[0] ?? 'Could not delete roster. Please try again.'
        return false
      } finally {
        this.deletingId = null
      }
    },

    async removeManualAssignment(rosterId, assignmentId) {
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
