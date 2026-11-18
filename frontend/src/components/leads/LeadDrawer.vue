<script setup>
import { computed, ref, watch } from 'vue'
import {
  ArrowUpRight, Building2, CalendarClock, CalendarDays, CircleDot, Euro, FileText, Mail, MapPin, MessageSquare, Phone, Ruler,
  Sparkles, Target, Trophy, UserRound, Users, Wand2,
} from 'lucide-vue-next'
import SlideOver from '@/components/ui/SlideOver.vue'
import ActivityTimeline from '@/components/leads/ActivityTimeline.vue'
import ScoreRing from '@/components/ui/ScoreRing.vue'
import TemperatureBadge from '@/components/ui/TemperatureBadge.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Tabs from '@/components/ui/Tabs.vue'
import { leads as leadsApi } from '@/api'
import { budgetRange, date, dateTime, money, timeAgo } from '@/lib/format'
import { statusTone } from '@/lib/status'
import { useToastStore } from '@/stores/toasts'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const toasts = useToastStore()
const lead = ref(null)
const loading = ref(false)
const tab = ref('overview')

const open = computed({
  get: () => ui.leadDrawerId !== null,
  set: (v) => { if (!v) ui.leadDrawerId = null },
})

watch(() => ui.leadDrawerId, async (id) => {
  if (!id) return
  tab.value = 'overview'
  loading.value = true
  lead.value = null
  try {
    lead.value = await leadsApi.get(id)
  } catch (e) {
    toasts.error('Could not open lead', e.message)
    ui.leadDrawerId = null
  } finally {
    loading.value = false
  }
})

const FACTOR_LABELS = { budget: 'Budget', project_type: 'Project type', location: 'Location', urgency: 'Urgency', source: 'Source', completeness: 'Completeness' }
const factors = computed(() => Object.entries(lead.value?.score_breakdown?.rules ?? {}).map(([key, f]) => ({ key, label: FACTOR_LABELS[key] ?? key, ...f })))

</script>

<template>
  <SlideOver v-model:open="open" width="max-w-[640px]">
    <template #header>
      <div v-if="lead" class="flex min-w-0 items-center gap-3">
        <Avatar :name="lead.contact_name" size="lg" />
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h2 class="truncate text-[17px] font-semibold tracking-[-0.01em] text-ink">{{ lead.contact_name }}</h2>
            <TemperatureBadge :temperature="lead.temperature" size="sm" />
          </div>
          <p class="mt-0.5 flex items-center gap-1.5 truncate text-[13px] text-muted">
            <span class="num font-medium text-faint">{{ lead.reference }}</span>
            <span>·</span><span class="truncate">{{ lead.company || 'Private client' }}</span>
          </p>
        </div>
      </div>
      <div v-else class="flex items-center gap-3"><Skeleton class="h-10 w-10 !rounded-full" /><div class="space-y-2"><Skeleton class="h-4 w-40" /><Skeleton class="h-3 w-28" /></div></div>
    </template>

    <div v-if="loading || !lead" class="space-y-4 p-6">
      <Skeleton class="h-36 w-full !rounded-card" /><Skeleton class="h-24 w-full !rounded-card" /><Skeleton class="h-48 w-full !rounded-card" />
    </div>

    <div v-else class="p-6">
      <!-- Score hero -->
      <div class="relative overflow-hidden rounded-card border border-line bg-gradient-to-br from-subtle/80 to-surface p-5">
        <div class="flex items-center gap-5">
          <ScoreRing :score="lead.score" :temperature="lead.temperature" :size="104" show-max />
          <div class="min-w-0 flex-1">
            <p class="eyebrow">Lead score</p>
            <p class="mt-1 text-[13px] text-muted">
              <span class="num font-semibold text-ink">{{ lead.rule_score ?? '–' }}</span> rules
              <template v-if="lead.ai_score !== null"> · <span class="num font-semibold text-ink">{{ lead.ai_score }}</span> AI ({{ Math.round((lead.score_breakdown?.ai_weight ?? 0) * 100) }}% weight)</template>
            </p>
            <div class="mt-3 flex flex-wrap gap-1.5">
              <BaseBadge :tone="statusTone(lead.status)" dot>{{ lead.status_label }}</BaseBadge>
              <BaseBadge v-if="lead.owner" tone="gray"><UserRound class="h-3 w-3" />{{ lead.owner.name }}</BaseBadge>
              <BaseBadge tone="gray">{{ lead.source_label }}</BaseBadge>
            </div>
          </div>
        </div>
        <div v-if="lead.ai_next_action" class="mt-4 flex items-start gap-2.5 rounded-xl bg-brand/10 p-3 ring-1 ring-inset ring-brand/25">
          <Wand2 class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
          <div class="text-[13px]">
            <p class="font-semibold text-ink">Next best action</p>
            <p class="text-muted">{{ lead.ai_next_action }}</p>
          </div>
        </div>
      </div>

      <Tabs v-model="tab" class="mt-6" variant="underline" :tabs="[{ value: 'overview', label: 'Overview' }, { value: 'score', label: 'Score breakdown' }, { value: 'activity', label: 'Activity', count: lead.activities?.length }]" />

      <!-- Overview -->
      <div v-if="tab === 'overview'" class="mt-5 space-y-5 animate-fade-up">
        <div v-if="lead.ai_summary" class="flex gap-2.5 text-[13.5px] text-ink">
          <Sparkles class="mt-0.5 h-4 w-4 shrink-0 text-brand" /><p>{{ lead.ai_summary }}</p>
        </div>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
          <div v-for="row in [
            { icon: Mail, label: 'Email', value: lead.email, href: lead.email && `mailto:${lead.email}` },
            { icon: Phone, label: 'Phone', value: lead.phone, href: lead.phone && `tel:${lead.phone.replace(/\s/g, '')}` },
            { icon: Building2, label: 'Project', value: lead.project_type_label },
            { icon: Euro, label: 'Budget', value: budgetRange(lead.budget), num: true },
            { icon: CalendarClock, label: 'Timeline', value: lead.timeline_label },
            { icon: Ruler, label: 'Area', value: lead.area_sqm ? `${lead.area_sqm} m²` : null, num: true },
            { icon: MapPin, label: 'Site', value: lead.address?.full },
            { icon: Trophy, label: 'Est. value', value: lead.estimated_value ? money(lead.estimated_value) : null, num: true },
            { icon: CalendarDays, label: 'Received', value: dateTime(lead.created_at) },
          ]" :key="row.label" class="flex min-w-0 items-start gap-3">
            <component :is="row.icon" class="mt-0.5 h-4 w-4 shrink-0 text-faint" />
            <div class="min-w-0">
              <dt class="text-[12px] text-muted">{{ row.label }}</dt>
              <dd class="truncate text-[13.5px] font-medium text-ink" :class="row.num && 'num'">
                <a v-if="row.href" :href="row.href" class="hover:text-brand-600 hover:underline">{{ row.value }}</a>
                <template v-else>{{ row.value || '—' }}</template>
              </dd>
            </div>
          </div>
        </dl>
        <div v-if="lead.description" class="rounded-xl border border-line bg-subtle/40 p-4">
          <p class="eyebrow mb-1.5">Enquiry</p>
          <p class="whitespace-pre-line text-[13.5px] leading-relaxed text-ink">{{ lead.description }}</p>
        </div>
        <div v-if="lead.quotes?.length" class="space-y-2">
          <p class="eyebrow">Quotes</p>
          <div v-for="q in lead.quotes" :key="q.id" class="flex items-center justify-between rounded-xl border border-line px-4 py-2.5 text-[13px]">
            <span class="flex items-center gap-2"><FileText class="h-4 w-4 text-faint" /><span class="num font-medium">{{ q.number }}</span></span>
            <span class="flex items-center gap-3"><span class="num font-semibold">{{ money(q.total) }}</span><BaseBadge :tone="{ accepted: 'green', sent: 'blue', declined: 'red', draft: 'gray', expired: 'orange' }[q.status]" size="sm">{{ q.status_label }}</BaseBadge></span>
          </div>
        </div>
      </div>

      <!-- Score breakdown -->
      <div v-else-if="tab === 'score'" class="mt-5 space-y-4 animate-fade-up">
        <div v-for="f in factors" :key="f.key">
          <div class="flex items-baseline justify-between gap-3 text-[13px]">
            <span class="font-medium text-ink">{{ f.label }}</span>
            <span class="num text-muted"><span class="font-semibold text-ink">{{ f.points }}</span> / {{ f.max }}</span>
          </div>
          <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-subtle">
            <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-brand transition-all duration-700" :style="{ width: `${(f.points / f.max) * 100}%` }" />
          </div>
          <p class="mt-1 text-[12px] text-muted">{{ f.note }}</p>
        </div>
        <div v-if="lead.score_breakdown?.ai" class="rounded-xl border border-line p-4">
          <p class="flex items-center gap-2 text-[13px] font-semibold text-ink"><Sparkles class="h-4 w-4 text-brand" /> AI assessment · {{ lead.score_breakdown.ai.score }}/100 <span class="font-normal text-faint">({{ lead.score_breakdown.model }})</span></p>
          <ul class="mt-2 space-y-1.5">
            <li v-for="r in lead.score_breakdown.ai.reasons" :key="r" class="flex gap-2 text-[13px] text-muted"><span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-faint" />{{ r }}</li>
          </ul>
        </div>
        <p v-else class="rounded-xl bg-subtle p-3 text-[12.5px] text-muted">Scored by rules only (no OpenAI key configured or the AI call failed).</p>
      </div>

      <!-- Activity timeline -->
      <div v-else class="mt-5"><ActivityTimeline :activities="lead.activities" /></div>
    </div>

    <template v-if="lead" #footer>
      <a v-if="lead.phone" :href="`tel:${lead.phone.replace(/\s/g, '')}`" class="focus-ring inline-flex h-8 items-center gap-2 rounded-lg bg-brand px-3 text-[13px] font-medium text-navy shadow-sm hover:bg-brand-hover hover:text-white"><Phone class="h-4 w-4" />Call</a>
      <a v-if="lead.email" :href="`mailto:${lead.email}`" class="focus-ring inline-flex h-8 items-center gap-2 rounded-lg border border-line bg-surface px-3 text-[13px] font-medium text-ink shadow-sm hover:bg-subtle"><Mail class="h-4 w-4" />Email</a>
      <RouterLink :to="{ name: 'lead', params: { id: lead.id } }" class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-[13px] font-medium text-ink transition hover:bg-subtle">Open full page <ArrowUpRight class="h-4 w-4" /></RouterLink>
    </template>
  </SlideOver>
</template>
