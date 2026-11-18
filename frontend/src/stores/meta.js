import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { meta as metaApi } from '@/api'

/** Option lists (statuses, sources, types, timelines, reps) loaded once per session. */
export const useMetaStore = defineStore('meta', () => {
  const data = ref(null)
  let pending = null

  const load = () => (data.value ? Promise.resolve(data.value) : (pending ??= metaApi.get().then((d) => (data.value = d)).finally(() => { pending = null })))

  const options = (key) => computed(() => data.value?.[key] ?? [])
  const labelOf = (key, value) => data.value?.[key]?.find((o) => o.value === value)?.label ?? value

  return {
    data,
    load,
    statuses: options('statuses'),
    sources: options('sources'),
    projectTypes: options('project_types'),
    timelines: options('timelines'),
    temperatures: options('temperatures'),
    salesReps: options('sales_reps'),
    labelOf,
  }
})
