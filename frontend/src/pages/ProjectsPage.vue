<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { CalendarDays, MapPin } from 'lucide-vue-next'
import PageHeader from '@/components/ui/PageHeader.vue'
import Tabs from '@/components/ui/Tabs.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import { projects as projectsApi } from '@/api'
import { date, moneyShort } from '@/lib/format'
import { projectTone } from '@/lib/status'
import { useToastStore } from '@/stores/toasts'

const router = useRouter()
const toasts = useToastStore()
const items = ref([])
const loading = ref(true)
const loadError = ref(null)
const status = ref('')

async function load() {
  loading.value = true
  loadError.value = null
  try { items.value = (await projectsApi.list({ status: status.value || undefined, per_page: 50 })).data } catch (e) { loadError.value = e.message; toasts.error('Could not load projects', e.message) } finally { loading.value = false }
}
onMounted(load)
watch(status, load)

const tabs = [{ value: '', label: 'All' }, { value: 'planning', label: 'Planning' }, { value: 'in_progress', label: 'In progress' }, { value: 'on_hold', label: 'On hold' }, { value: 'completed', label: 'Completed' }]
</script>

<template>
  <div>
    <PageHeader title="Projects" description="Won quotes turned into jobs — progress, budget and billing at a glance.">
      <template #actions><Tabs v-model="status" :tabs="tabs" /></template>
    </PageHeader>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><Skeleton v-for="i in 6" :key="i" class="h-56 !rounded-card" /></div>
    <div v-else-if="loadError" class="card"><ErrorState :message="loadError" @retry="load" /></div>
    <div v-else-if="items.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="p in items" :key="p.id" class="card group flex cursor-pointer flex-col p-5 transition hover:-translate-y-0.5 hover:shadow-lift" @click="router.push({ name: 'project', params: { id: p.id } })">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="num text-[12px] font-medium text-faint">{{ p.code }}</p>
            <h3 class="mt-0.5 line-clamp-2 text-[15px] font-semibold leading-snug text-ink">{{ p.name }}</h3>
          </div>
          <BaseBadge :tone="projectTone(p.status)" dot>{{ p.status_label }}</BaseBadge>
        </div>
        <p class="mt-2 flex items-center gap-1.5 truncate text-[12.5px] text-muted"><MapPin class="h-3.5 w-3.5 shrink-0" />{{ p.site_address || '—' }}</p>

        <div class="mt-4">
          <div class="mb-1.5 flex justify-between text-[12px]"><span class="text-muted">Progress</span><span class="num font-semibold text-ink">{{ p.progress }}%</span></div>
          <div class="h-2 overflow-hidden rounded-full bg-subtle"><div class="h-full rounded-full transition-all duration-700" :class="p.status === 'completed' ? 'bg-emerald-500' : p.status === 'on_hold' ? 'bg-orange-400' : 'bg-gradient-to-r from-amber-300 to-brand'" :style="{ width: `${p.progress}%` }" /></div>
        </div>

        <div class="mt-3">
          <div class="mb-1.5 flex justify-between text-[12px]"><span class="text-muted">Budget vs. spent</span><span class="num text-muted"><span class="font-semibold text-ink">{{ moneyShort(p.spent) }}</span> / {{ moneyShort(p.budget) }}</span></div>
          <div class="h-2 overflow-hidden rounded-full bg-subtle"><div class="h-full rounded-full" :class="p.budget_used_pct > 100 ? 'bg-red-500' : p.budget_used_pct > p.progress + 10 ? 'bg-orange-400' : 'bg-navy dark:bg-slate-300'" :style="{ width: `${Math.min(100, p.budget_used_pct)}%` }" /></div>
        </div>

        <dl class="mt-4 grid grid-cols-3 gap-2 border-t border-line pt-4 text-center">
          <div><dt class="text-[11px] text-muted">Budget</dt><dd class="num text-[13px] font-semibold text-ink">{{ moneyShort(p.budget) }}</dd></div>
          <div><dt class="text-[11px] text-muted">Spent</dt><dd class="num text-[13px] font-semibold" :class="p.budget_used_pct > p.progress + 10 ? 'text-red-600 dark:text-red-400' : 'text-ink'">{{ p.budget_used_pct }}%</dd></div>
          <div><dt class="text-[11px] text-muted">Invoiced</dt><dd class="num text-[13px] font-semibold text-ink">{{ Math.round(p.invoiced_pct) }}%</dd></div>
        </dl>

        <div class="mt-4 flex items-center justify-between text-[12px] text-muted">
          <span class="flex items-center gap-2"><Avatar v-if="p.manager" :name="p.manager.name" size="sm" />{{ p.manager?.name ?? 'No manager' }}</span>
          <span class="flex items-center gap-1" :class="p.is_late && 'font-medium text-red-600 dark:text-red-400'"><CalendarDays class="h-3.5 w-3.5" />{{ date(p.due_date) }}</span>
        </div>
      </article>
    </div>
    <EmptyState v-else class="card" illustration="board" title="No projects here" description="Accept a quote and the project appears here automatically." />
  </div>
</template>
