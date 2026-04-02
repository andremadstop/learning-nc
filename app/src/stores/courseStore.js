import { defineStore, getActivePinia } from 'pinia'

export const useCourseStore = defineStore('course', {
  state: () => ({
    currentCourseId: null,
    currentTab: null,
    currentSubTab: null,
  }),
  actions: {
    setTab(tabId) {
      this.currentTab = tabId
    },
    setSubTab(subTabId) {
      this.currentSubTab = subTabId
    },
    setCourse(courseId) {
      this.currentCourseId = courseId
    },
  },
})

export function useOptionalCourseStore() {
  const pinia = getActivePinia()
  return pinia ? useCourseStore(pinia) : null
}
