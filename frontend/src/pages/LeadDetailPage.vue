<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft, Building2, CalendarClock, CalendarDays, Check, ChevronDown, Download, Euro, FilePlus2, FileText, Mail, MapPin,
  MessageSquare, Phone, RefreshCw, Ruler, ShieldCheck, Sparkles, Trophy, UserRound, Users, Wand2,
} from 'lucide-vue-next'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import Avatar from '@/components/ui/Avatar.vue'
import ScoreRing from '@/components/ui/ScoreRing.vue'
import TemperatureBadge from '@/components/ui/TemperatureBadge.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Dropdown from '@/components/ui/Dropdown.vue'
import StatusMenu from '@/components/leads/StatusMenu.vue'
import ActivityTimeline from '@/components/leads/ActivityTimeline.vue'
import LocationCard from '@/components/leads/LocationCard.vue'
import { leads as leadsApi, quotes as quotesApi } from '@/api'
import { DEMO_MODE } from '@/api/http'
import { budgetRange, date, dateTime, money } from '@/lib/format'
import { quoteTone } from '@/lib/status'
import { useAuthStore } from '@/stores/auth'
import { useMetaStore } from '@/stores/meta'
import { useToastStore } from '@/stores/toasts'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const meta = useMetaStore()
const toasts = useToastStore()
const ui = useUiStore()

const lead = ref(null)
const loading = ref(true)
const notFound = ref(false)
const loadError = ref(null)
const tab = ref('activity')

async function load(silent = false) {
  if (!silent) loading.value = true
  notFound.value = false
  loadError.value = null
  try {
    lead.value = await leadsApi.get(route.params.id)
    document.title = `${lead.value.contact_name} · BuildLead`
  } catch (e) {
    if ([403, 404].includes(e.status)) notFound.value = true
    else { if (!silent) loadError.value = e.message; toasts.error('Could not load lead', e.message) }
  } finally {
    loading.value = false
  }
}
watch(() => route.params.id, (id) => { if (id) load() }, { immediate: true })
meta.load()

// ---- header actions ---------------------------------------------------------
async function changeStatus(status, lostReason) {
  const before = lead.value.status
  try {
    lead.value = await leadsApi.setStatus(lead.value.id, { status, lost_reason: lostReason })
    ui.leadsVersion++
    toasts.success(`Moved to ${lead.value.status_label}`, before === 'new' ? 'First response time recorded.' : null)
  } catch (e) { toasts.error('Could not change status', e.message) }
}

async function reassign(rep, close) {
  close()
  try {
    await leadsApi.update(lead.value.id, { assigned_to: rep.id })
    await load(true)
    ui.leadsVersion++
    toasts.success(`Assigned to ${rep.name}`)
  } catch (e) { toasts.error('Could not reassign', e.message) }
}

// ---- re-score -----------------------------------------------------------------
const rescoring = ref(false)
async function rescore() {
  rescoring.value = true
  const before = { score: lead.value.score, at: lead.value.scored_at }
  try {
    await leadsApi.rescore(lead.value.id)
    for (let i = 0; i < 12; i++) {
      await new Promise((r) => setTimeout(r, i === 0 ? 400 : 900))
      const fresh = await leadsApi.get(lead.value.id)
      if (fresh.scored_at !== before.at) {
        lead.value = fresh
        toasts.push({ type: fresh.temperature === 'hot' ? 'hot' : 'success', title: `Re-scored: ${before.score ?? '–'} → ${fresh.score}/100`, description: `${fresh.temperature[0].toUpperCase()}${fresh.temperature.slice(1)} · ${fresh.score_breakdown?.method === 'hybrid' ? 'rules + AI' : 'rules only'}` })
        ui.leadsVersion++
        return
      }
    }
    toasts.info('Re-scoring queued', 'The queue worker will update the score shortly.')
  } catch (e) { toasts.error('Could not re-score', e.message) } finally { rescoring.value = false }
}

// ---- composer ------------------------------------------------------------------
const TYPES = [
  { value: 'note', label: 'Note', icon: MessageSquare, title: 'Note added' },
  { value: 'call', label: 'Call', icon: Phone, title: 'Call logged' },
  { value: 'meeting', label: 'Meeting', icon: Users, title: 'Meeting' },
  { value: 'email', label: 'Email', icon: Mail, title: 'Email sent' },
  { value: 'site_visit', label: 'Site visit', icon: MapPin, title: 'Site visit' },
]
const composer = ref({ type: 'note', body: '', due_at: '', schedule: false })
const posting = ref(false)
async function postActivity() {
  const t = TYPES.find((x) => x.value === composer.value.type)
  const scheduled = composer.value.schedule && composer.value.due_at
  posting.value = true
  try {
    await leadsApi.addActivity(lead.value.id, {
      type: t.value,
      title: scheduled ? `Follow-up ${t.label.toLowerCase()} scheduled` : t.title,
      body: composer.value.body || null,
      due_at: scheduled ? new Date(composer.value.due_at).toISOString() : null,
    })
    composer.value = { type: composer.value.type, body: '', due_at: '', schedule: false }
    await load(true)
    toasts.success(scheduled ? 'Follow-up scheduled' : `${t.label} logged`)
  } catch (e) { toasts.error('Could not save', e.message) } finally { posting.value = false }
}

// ---- derived -------------------------------------------------------------------
const FACTORS = { budget: 'Budget', project_type: 'Project type', location: 'Location', urgency: 'Urgency', source: 'Source', completeness: 'Completeness' }
const factors = computed(() => Object.entries(lead.value?.score_breakdown?.rules ?? {}).map(([k, f]) => ({ key: k, label: FACTORS[k] ?? k, ...f })))
const breakdown = computed(() => lead.value?.score_breakdown)
const tel = computed(() => lead.value?.phone && `tel:${lead.value.phone.replace(/[^\d+]/g, '')}`)
const tabs = computed(() => [{ value: 'activity', label: 'Activity', count: lead.value?.activities?.length }, { value: 'quotes', label: 'Quotes', count: lead.value?.quotes?.length }])
const factorTone = (f) => (f.fraction >= 0.75 ? 'from-emerald-400 to-emerald-500' : f.fraction >= 0.4 ? 'from-amber-300 to-brand' : 'from-slate-300 to-slate-400 dark:from-slate-600 dark:to-slate-500')

async function openPdf(q) {
  try { await quotesApi.openPdf(q.id) } catch (e) { toasts.error('Could not open PDF', e.message) }
}


</script>

<template>
  <div>
    <button class="mb-4 inline-flex items-center gap-1.5 text-[13px] font-medium text-muted transition hover:text-ink" @click="router.back()">
      <ArrowLeft class="h-4 w-4" /> Back
    </button>

    <!-- Loading -->
    <div v-if="loading" class="space-y-5">
      <Skeleton class="h-28 w-full !rounded-card" />
      <div class="grid gap-5 lg:grid-cols-2 xl:grid-cols-12">
        <Skeleton class="h-[520px] !rounded-card xl:col-span-3" /><Skeleton class="h-[520px] !rounded-card xl:col-span-5" /><Skeleton class="h-[520px] !rounded-card lg:col-span-2 xl:col-span-4" />
      </div>
    </div>

    <div v-else-if="notFound" class="card">
      <EmptyState illustration="search" title="Lead not found" description="It may have been deleted, or it's assigned to another sales rep.">
        <BaseButton :to="{ name: 'leads' }">Back to leads</BaseButton>
      </EmptyState>
    </div>

    <div v-else-if="loadError" class="card"><ErrorState title="Could not load this lead" :message="loadError" @retry="load()" /></div>
    <template v-else-if="lead">
      <!-- Header -->
      <header class="card relative overflow-hidden p-5 sm:p-6">
        <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full blur-3xl" :class="lead.temperature === 'hot' ? 'bg-orange-500/10' : lead.temperature === 'warm' ? 'bg-amber-400/10' : 'bg-slate-400/10'" />
        <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
          <div class="flex min-w-0 items-center gap-4">
            <Avatar :name="lead.contact_name" size="xl" />
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h1 class="truncate text-[22px] font-semibold tracking-[-0.025em] text-ink">{{ lead.contact_name }}</h1>
                <TemperatureBadge :temperature="lead.temperature" :score="lead.score" />
              </div>
              <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13.5px] text-muted">
                <span class="inline-flex items-center gap-1.5"><Building2 class="h-3.5 w-3.5" />{{ lead.company || 'Private client' }}</span>
                <span class="text-faint">·</span><span class="num font-medium text-faint">{{ lead.reference }}</span>
                <span class="text-faint">·</span><span>{{ lead.source_label }}</span>
              </p>
              <div class="mt-3 flex flex-wrap items-center gap-2">
                <StatusMenu :status="lead.status" :label="lead.status_label" @change="changeStatus" />
                <Dropdown v-if="auth.canSeeAll" align="left" width="w-60">
                  <template #trigger="{ toggle }">
                    <button class="focus-ring inline-flex h-8 items-center gap-2 rounded-full border border-line bg-surface pl-1 pr-2 text-[13px] font-medium text-ink shadow-sm hover:border-faint/60" @click="toggle">
                      <Avatar v-if="lead.owner" :name="lead.owner.name" size="sm" /><UserRound v-else class="ml-1.5 h-4 w-4 text-faint" />
                      {{ lead.owner?.name ?? 'Unassigned' }}<ChevronDown class="h-3.5 w-3.5 text-faint" />
                    </button>
                  </template>
                  <template #default="{ close }">
                    <p class="eyebrow px-2.5 pb-1 pt-1.5">Assign to</p>
                    <button v-for="r in meta.salesReps" :key="r.id" class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13.5px] text-ink hover:bg-subtle" @click="reassign(r, close)">
                      <Avatar :name="r.name" size="sm" /><span class="flex-1">{{ r.name }}</span><Check v-if="lead.owner?.id === r.id" class="h-4 w-4 text-brand" />
                    </button>
                  </template>
                </Dropdown>
                <span v-else-if="lead.owner" class="inline-flex h-8 items-center gap-2 rounded-full border border-line pl-1 pr-3 text-[13px] text-ink"><Avatar :name="lead.owner.name" size="sm" />{{ lead.owner.name }}</span>
              </div>
            </div>
          </div>
          <div class="flex flex-wrap gap-2">
            <a v-if="tel" :href="tel" class="focus-ring inline-flex h-9 items-center gap-2 rounded-[10px] bg-brand px-3.5 text-[14px] font-medium text-navy shadow-sm transition hover:bg-brand-hover hover:text-white"><Phone class="h-4 w-4" />Call</a>
            <a v-if="lead.email" :href="`mailto:${lead.email}`" class="focus-ring inline-flex h-9 items-center gap-2 rounded-[10px] border border-line bg-surface px-3.5 text-[14px] font-medium text-ink shadow-sm transition hover:bg-subtle"><Mail class="h-4 w-4" />Email</a>
            <BaseButton variant="dark" :icon="FilePlus2" :to="{ name: 'quote-new', query: { lead: lead.id } }">New quote</BaseButton>
          </div>
        </div>
      </header>

      <div class="mt-5 grid gap-5 lg:grid-cols-2 xl:grid-cols-12">
        <!-- Left: details -->
        <div class="space-y-5 xl:col-span-3">
          <BaseCard title="Contact">
            <dl class="space-y-3.5">
              <div v-for="row in [
                { icon: Mail, label: 'Email', value: lead.email, href: lead.email && `mailto:${lead.email}` },
                { icon: Phone, label: 'Phone', value: lead.phone, href: tel },
                { icon: Building2, label: 'Company', value: lead.company || 'Private client' },
                { icon: CalendarDays, label: 'Received', value: dateTime(lead.created_at) },
                { icon: ShieldCheck, label: 'GDPR consent', value: lead.consent_given ? `Given · ${date(lead.consent_at)}` : 'Not recorded' },
              ]" :key="row.label" class="flex min-w-0 items-start gap-3">
                <component :is="row.icon" class="mt-0.5 h-4 w-4 shrink-0 text-faint" />
                <div class="min-w-0"><dt class="text-[12px] text-muted">{{ row.label }}</dt>
                  <dd class="truncate text-[13.5px] font-medium text-ink"><a v-if="row.href" :href="row.href" class="hover:text-brand-700 hover:underline dark:hover:text-brand-400">{{ row.value }}</a><template v-else>{{ row.value || '—' }}</template></dd></div>
              </div>
            </dl>
          </BaseCard>

          <BaseCard title="Project">
            <div class="mb-4 rounded-xl bg-subtle/60 p-3">
              <p class="text-[12px] text-muted">Budget</p>
              <p class="num text-[18px] font-semibold tracking-tight text-ink">{{ budgetRange(lead.budget) }}</p>
              <p v-if="lead.estimated_value" class="num mt-0.5 text-[12px] text-muted">Est. deal value {{ money(lead.estimated_value) }}</p>
            </div>
            <dl class="space-y-3.5">
              <div v-for="row in [
                { icon: Building2, label: 'Type', value: lead.project_type_label },
                { icon: CalendarClock, label: 'Timeline', value: lead.timeline_label },
                { icon: Ruler, label: 'Area', value: lead.area_sqm ? `${lead.area_sqm} m²` : null },
                { icon: Trophy, label: 'Project', value: lead.project ? `${lead.project.code} · ${lead.project.progress}%` : null },
              ].filter((r) => r.label !== 'Project' || r.value)" :key="row.label" class="flex items-start gap-3">
                <component :is="row.icon" class="mt-0.5 h-4 w-4 shrink-0 text-faint" />
                <div><dt class="text-[12px] text-muted">{{ row.label }}</dt><dd class="text-[13.5px] font-medium text-ink">{{ row.value || '—' }}</dd></div>
              </div>
            </dl>
            <p v-if="lead.description" class="mt-4 whitespace-pre-line border-t border-line pt-4 text-[13px] leading-relaxed text-muted">{{ lead.description }}</p>
          </BaseCard>

          <LocationCard :address="lead.address" />
        </div>

        <!-- Centre: AI score -->
        <div class="xl:col-span-5">
          <BaseCard :padded="false" class="overflow-hidden">
            <div class="relative border-b border-line bg-gradient-to-br from-subtle/80 via-surface to-surface p-6">
              <div class="flex items-start justify-between gap-3">
                <p class="flex items-center gap-2 text-[15px] font-semibold text-ink"><Sparkles class="h-4 w-4 text-brand" /> AI score</p>
                <BaseButton variant="secondary" size="sm" :icon="RefreshCw" :loading="rescoring" @click="rescore">Re-score</BaseButton>
              </div>
              <div class="mt-4 flex flex-col items-center gap-6 sm:flex-row">
                <ScoreRing :key="lead.scored_at" :score="lead.score" :temperature="lead.temperature" :size="148" show-max />
                <div class="w-full min-w-0 flex-1">
                  <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-surface p-3 ring-1 ring-inset ring-line"><p class="text-[12px] text-muted">Rules</p><p class="num text-[20px] font-semibold text-ink">{{ lead.rule_score ?? '–' }}</p></div>
                    <div class="rounded-xl bg-surface p-3 ring-1 ring-inset ring-line"><p class="text-[12px] text-muted">AI <span v-if="breakdown?.ai_weight">· {{ Math.round(breakdown.ai_weight * 100) }}%</span></p><p class="num text-[20px] font-semibold text-ink">{{ lead.ai_score ?? '–' }}</p></div>
                  </div>
                  <p class="mt-3 text-[12px] text-muted">
                    {{ breakdown?.method === 'hybrid' ? `Blended with ${breakdown.model}` : 'Scored by rules only' }} · {{ date(lead.scored_at) }}
                  </p>
                </div>
              </div>
            </div>

            <div class="space-y-5 p-6">
              <div v-if="lead.ai_summary">
                <p class="eyebrow mb-1.5">Summary</p>
                <p class="text-[14px] leading-relaxed text-ink">{{ lead.ai_summary }}</p>
              </div>

              <div v-if="breakdown?.ai?.reasons?.length">
                <p class="eyebrow mb-2">Why this score</p>
                <ul class="space-y-2">
                  <li v-for="r in breakdown.ai.reasons" :key="r" class="flex gap-2.5 text-[13.5px] text-ink"><span class="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full bg-brand" />{{ r }}</li>
                </ul>
              </div>

              <div v-if="lead.ai_next_action" class="flex items-start gap-3 rounded-xl bg-brand/10 p-4 ring-1 ring-inset ring-brand/25">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand text-navy"><Wand2 class="h-4 w-4" /></span>
                <div class="text-[13.5px]"><p class="font-semibold text-ink">Next best action</p><p class="text-muted">{{ lead.ai_next_action }}</p></div>
              </div>

              <div>
                <p class="eyebrow mb-3">Score factors</p>
                <div class="space-y-3.5">
                  <div v-for="f in factors" :key="f.key">
                    <div class="flex items-baseline justify-between gap-3 text-[13px]">
                      <span class="font-medium text-ink">{{ f.label }}</span>
                      <span class="num text-muted"><span class="font-semibold text-ink">{{ f.points }}</span> / {{ f.max }}</span>
                    </div>
                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-subtle">
                      <div class="h-full rounded-full bg-gradient-to-r transition-all duration-700" :class="factorTone(f)" :style="{ width: `${f.max ? (f.points / f.max) * 100 : 0}%` }" />
                    </div>
                    <p class="mt-1 text-[12px] text-muted">{{ f.note }}</p>
                  </div>
                </div>
              </div>
            </div>
          </BaseCard>
        </div>

        <!-- Right: activity & quotes -->
        <div class="lg:col-span-2 xl:col-span-4">
          <BaseCard :padded="false">
            <div class="px-5 pt-4"><Tabs v-model="tab" :tabs="tabs" variant="underline" /></div>

            <div v-if="tab === 'activity'" class="p-5">
              <!-- Composer -->
              <form class="mb-6 rounded-xl border border-line bg-subtle/40 p-3" @submit.prevent="postActivity">
                <div class="mb-2 flex flex-wrap gap-1">
                  <button v-for="t in TYPES" :key="t.value" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[12.5px] font-medium transition" :class="composer.type === t.value ? 'bg-surface text-ink shadow-card ring-1 ring-line' : 'text-muted hover:text-ink'" @click="composer.type = t.value">
                    <component :is="t.icon" class="h-3.5 w-3.5" />{{ t.label }}
                  </button>
                </div>
                <textarea v-model="composer.body" rows="3" class="field-input resize-none bg-surface" :placeholder="composer.type === 'call' ? 'What did you discuss?' : composer.type === 'note' ? 'Add a note for the team…' : 'Details…'" @keydown.meta.enter="postActivity" @keydown.ctrl.enter="postActivity" />
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                  <label class="flex items-center gap-2 text-[12.5px] text-muted">
                    <input v-model="composer.schedule" type="checkbox" class="form-checkbox h-4 w-4 rounded border-line text-brand focus:ring-brand/30" />Schedule as follow-up
                    <input v-if="composer.schedule" v-model="composer.due_at" type="datetime-local" class="field-input h-8 w-auto py-0 text-[12.5px]" />
                  </label>
                  <BaseButton type="submit" size="sm" :loading="posting" :disabled="(!composer.body && composer.type === 'note') || (composer.schedule && !composer.due_at)">
                    {{ composer.schedule ? 'Schedule' : 'Log' }} {{ TYPES.find((t) => t.value === composer.type).label.toLowerCase() }}
                  </BaseButton>
                </div>
              </form>
              <ActivityTimeline :activities="lead.activities" />
            </div>

            <div v-else class="p-5">
              <div v-if="lead.quotes.length" class="space-y-2.5">
                <div v-for="q in lead.quotes" :key="q.id" class="flex cursor-pointer items-center gap-3 rounded-xl border border-line p-3.5 transition hover:shadow-card" @click="router.push({ name: 'quote', params: { id: q.id } })">
                  <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-subtle ring-1 ring-inset ring-line"><FileText class="h-4 w-4 text-muted" /></span>
                  <div class="min-w-0 flex-1">
                    <p class="num text-[13.5px] font-semibold text-ink">{{ q.number }}</p>
                    <p class="text-[12px] text-muted">Valid until {{ date(q.valid_until) }}<template v-if="q.sent_at"> · sent {{ date(q.sent_at) }}</template></p>
                  </div>
                  <div class="text-right">
                    <p class="num text-[13.5px] font-semibold text-ink">{{ money(q.total) }}</p>
                    <BaseBadge :tone="quoteTone(q.status)" size="sm">{{ q.status_label }}</BaseBadge>
                  </div>
                  <button v-if="!DEMO_MODE" type="button" class="focus-ring rounded-lg p-1.5 text-faint hover:bg-subtle hover:text-ink" title="Open PDF" aria-label="Open PDF" @click.stop="openPdf(q)"><Download class="h-4 w-4" /></button>
                </div>
              </div>
              <EmptyState v-else compact illustration="inbox" title="No quotes yet" description="Create a quote after the site visit — line items, VAT and PDF are handled for you.">
                <BaseButton size="sm" :icon="FilePlus2" :to="{ name: 'quote-new', query: { lead: lead.id } }">New quote</BaseButton>
              </EmptyState>
              <BaseButton v-if="lead.quotes.length" class="mt-4" variant="secondary" size="sm" :icon="FilePlus2" block :to="{ name: 'quote-new', query: { lead: lead.id } }">New quote</BaseButton>
            </div>
          </BaseCard>
        </div>
      </div>

    </template>
  </div>
</template>
