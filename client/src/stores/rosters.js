import { isAxiosError } from 'axios'
import { defineStore } from 'pinia'
import {
  addAssignment,
  removeAssignment,
} from '@/api/rosterAssignments'
import {
  deleteRoster,
  generateRoster,
  getRoster,
  listRosters,
  saveRoster,
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

export const useRostersStore = defineStore('rosters', {
  state: () => {
    const now = new Date()

    return {
      rosters: [],
      roster: null,
      generatedRoster: null,
      selectedYear: now.getFullYear(),
      selectedMonth: now.getMonth() + 1,
      loading: false,
      generating: false,
      saving: false,
      deletingId: null,
      assignmentLoading: false,
      error: '',
      validationErrors: {},
    }
  },

  getters: {
    assignments(state) {
      return state.roster?.assignments ?? state.generatedRoster?.assignments ?? []
    },

    reports(state) {
      return state.roster?.reports ?? state.generatedRoster?.reports ?? emptyReports
    },

    summary(state) {
      return state.roster?.summary ?? state.generatedRoster?.summary ?? null
    },
  },

  actions: {
    clearErrors() {
      this.error = ''
      this.validationErrors = {}
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
        this.generatedRoster = null
        this.selectedYear = response.data.year
        this.selectedMonth = response.data.month
      } catch {
        this.error = 'Could not load roster. Please try again.'
      } finally {
        this.loading = false
      }
    },

    async generate(year, month) {
      this.generating = true
      this.clearErrors()

      try {
        const roster = await generateRoster({ year, month })
        this.generatedRoster = roster
        this.roster = null
        this.selectedYear = roster.year
        this.selectedMonth = roster.month

        return roster
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.year?.[0]
          ?? this.validationErrors.month?.[0]
          ?? 'Could not generate roster. Please try again.'
        return null
      } finally {
        this.generating = false
      }
    },

    async save(year, month) {
      this.saving = true
      this.clearErrors()

      try {
        const roster = await saveRoster({ year, month })
        this.roster = roster
        this.generatedRoster = null
        this.selectedYear = roster.year
        this.selectedMonth = roster.month

        return roster
      } catch (error) {
        this.validationErrors = extractValidationErrors(error)
        this.error = this.validationErrors.year?.[0]
          ?? this.validationErrors.month?.[0]
          ?? 'Could not save roster. Please try again.'
        return null
      } finally {
        this.saving = false
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

    applyRosterUpdate(roster) {
      if (this.generatedRoster?.id === roster.id) {
        this.generatedRoster = roster
      }

      if (this.roster?.id === roster.id) {
        this.roster = roster
      }
    },
  },
})
