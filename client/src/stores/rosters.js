import { isAxiosError } from 'axios'
import { defineStore } from 'pinia'
import {
  addAssignment,
  changeAssignment,
  removeAssignment,
} from '@/api/rosterAssignments'
import {
  deleteRoster,
  generateRosterDraft,
  getRoster,
  listRosters,
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
      generatedDraft: null,
      selectedYear: year,
      selectedMonth: month,
      loading: false,
      generating: false,
      publishing: false,
      deletingId: null,
      assignmentLoading: false,
      error: '',
      validationErrors: {},
    }
  },

  getters: {
    assignments(state) {
      return state.roster?.assignments ?? state.generatedDraft?.assignments ?? []
    },

    reports(state) {
      return state.roster?.reports ?? state.generatedDraft?.reports ?? emptyReports
    },

    alerts() {
      return this.reports
    },

    summary(state) {
      return state.roster?.summary ?? state.generatedDraft?.summary ?? null
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
        this.generatedDraft = null
        this.selectedYear = response.data.year
        this.selectedMonth = response.data.month
      } catch {
        this.error = 'Could not load roster. Please try again.'
      } finally {
        this.loading = false
      }
    },

    async generateDraft(year, month) {
      const targetYear = year ?? this.selectedYear
      const targetMonth = month ?? this.selectedMonth
      const previousDraftId = this.generatedDraft?.status === 'draft'
        ? this.generatedDraft.id
        : null

      this.generating = true
      this.clearErrors()

      try {
        const draft = await generateRosterDraft({ year: targetYear, month: targetMonth })
        this.generatedDraft = draft
        this.roster = null
        this.selectedYear = draft.year
        this.selectedMonth = draft.month

        if (previousDraftId && previousDraftId !== draft.id) {
          try {
            await deleteRoster(previousDraftId)
          } catch {
            this.error = 'The new draft was generated, but the previous draft could not be removed.'
          }
        }

        return draft
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        const fallback = !isAxiosError(error) && error?.message
          ? error.message
          : 'Could not generate roster draft. Please try again.'
        this.error = this.validationErrors.year?.[0]
          ?? this.validationErrors.month?.[0]
          ?? fallback
        return null
      } finally {
        this.generating = false
      }
    },

    async publish(rosterId) {
      const id = rosterId ?? this.roster?.id ?? this.generatedDraft?.id

      if (!id) {
        this.error = 'No roster selected to publish.'
        return null
      }

      this.publishing = true
      this.clearErrors()

      try {
        const response = await publishRoster(id)
        this.applyRosterUpdate(response.data)
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.roster?.[0] ?? 'Could not publish roster. Please try again.'
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
        this.applyRosterUpdate(response.data)
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
        this.applyRosterUpdate(response.data)
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
        this.applyRosterUpdate(response.data)
        return response.data
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.roster?.[0] ?? 'Could not remove assignment. Please try again.'
        return null
      } finally {
        this.assignmentLoading = false
      }
    },

    clearGeneratedDraft() {
      this.generatedDraft = null
    },

    applyRosterUpdate(roster) {
      if (this.generatedDraft?.id === roster.id) {
        this.generatedDraft = roster
      }

      if (this.roster?.id === roster.id) {
        this.roster = roster
      }
    },

    clearRoster() {
      this.roster = null
    },
  },
})
