<script setup>
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import {
  ArrowRight, CircleDot, Euro, FileText, Flame, Mail, MapPin, MessageSquare, Phone, Plus, Sparkles, Target, Trophy,
  UserRound, Users, Wand2,
} from 'lucide-vue-next'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatCard from '@/components/ui/StatCard.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import ScoreRing from '@/components/ui/ScoreRing.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import { dashboard as dashboardApi } from '@/api'
import { date, greeting, minutes, moneyShort, number, pct, timeAgo } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useUiStore } from '@/stores/ui'

// Charts are heavy: load the (tree-shaken) ApexCharts only on the dashboard.
const apexchart = defineAsyncComponent(() => import('@/lib/charts'))

const auth = useAuthStore()
const ui = useUiStore()
const toasts = useToastStore()
const data = ref(null)
const loading = ref(true)
const loadError = ref(null)

async function load() {
  loading.value = !data.value
  loadError.value = null
  try { data.value = await dashboardApi.get() } catch (e) { loadError.value = e.message; toasts.error('Could not load dashboard', e.message) } finally { loading.value = false }
}
onMounted(load)
watch(() => ui.leadsVersion, load)

const k = computed(() => data.value?.kpis ?? {})
const t = computed(() => k.value.trends ?? {})
const weeks = computed(() => data.value?.leads_per_week ?? [])
const dark = computed(() => ui.isDark())
const axis = computed(() => ({ colors: dark.value ? '#64748B' : '#94A3B8', fontSize: '11px' }))
const base = computed(() => ({
  chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif', background: 'transparent', animations: { speed: 600 }, zoom: { enabled: false } },
  tooltip: { theme: dark.value ? 'dark' : 'light' },
  grid: { borderColor: dark.value ? '#1E293B' : '#EEF0F3', strokeDashArray: 4, padding: { left: 6, right: 6 } },
  legend: { show: false },
  dataLabels: { enabled: false },
}))

// Area: leads per week, stacked by temperature
const area = computed(() => ({
  series: [
    { name: 'Hot', data: weeks.value.map((w) => w.hot) },
    { name: 'Warm', data: weeks.value.map((w) => w.warm) },
    { name: 'Cold', data: weeks.value.map((w) => w.cold) },
  ],
  options: {
    ...base.value,
    chart: { ...base.value.chart, type: 'area', stacked: true },
    colors: ['#F97316', '#F59E0B', dark.value ? '#64748B' : '#94A3B8'],
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 0, opacityFrom: 0.42, opacityTo: 0.04, stops: [0, 95] } },
    xaxis: { categories: weeks.value.map((w) => w.label), axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: axis.value }, tooltip: { enabled: false } },
    yaxis: { tickAmount: 4, labels: { style: axis.value, formatter: (v) => Math.round(v) } },
    tooltip: { ...base.value.tooltip, shared: true, y: { formatter: (v) => `${v} leads` } },
    markers: { size: 0, hover: { size: 4 } },
  },
}))

// Donut: leads by source
const SOURCE_COLORS = ['#F59E0B', '#0B1220', '#F97316', '#38BDF8', '#10B981', '#A78BFA']
const sources = computed(() => (data.value?.by_source ?? []).filter((s) => s.count > 0))
const sourceTotal = computed(() => sources.value.reduce((s, x) => s + x.count, 0))
const donutColors = computed(() => SOURCE_COLORS.map((c) => (dark.value && c === '#0B1220' ? '#CBD5E1' : c)))
const donut = computed(() => ({
  series: sources.value.map((s) => s.count),
  options: {
    ...base.value,
    chart: { ...base.value.chart, type: 'donut' },
    labels: sources.value.map((s) => s.label),
    colors: donutColors.value,
    stroke: { width: 3, colors: [dark.value ? '#0D1321' : '#FFFFFF'] },
    plotOptions: { pie: { donut: { size: '72%', labels: { show: true, name: { offsetY: 18, color: axis.value.colors, fontSize: '12px' }, value: { offsetY: -14, fontSize: '26px', fontWeight: 600, color: dark.value ? '#E8ECF3' : '#0F172A' }, total: { show: true, label: 'leads', color: axis.value.colors, formatter: () => sourceTotal.value } } } } },
    tooltip: { ...base.value.tooltip, y: { formatter: (v) => `${v} leads` } },
    states: { hover: { filter: { type: 'none' } } },
  },
}))

// Funnel: stage reach as a centred horizontal bar funnel
const funnel = computed(() => ({
  series: [{ name: 'Leads', data: (data.value?.funnel ?? []).map((s) => s.count) }],
  options: {
    ...base.value,
    chart: { ...base.value.chart, type: 'bar' },
    plotOptions: { bar: { horizontal: true, isFunnel: true, borderRadius: 5, barHeight: '78%', distributed: true } },
    colors: ['#FDE68A', '#FCD34D', '#FBBF24', '#F59E0B', '#D97706', '#10B981'],
    // Counts on the bars, stage names on the axis: narrow bottom bars stay readable.
    dataLabels: { enabled: true, formatter: (v) => v, style: { fontSize: '11.5px', fontWeight: 700, colors: ['#0B1220'] }, dropShadow: { enabled: false } },
    xaxis: { categories: (data.value?.funnel ?? []).map((s) => s.label), labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { show: true, style: { colors: dark.value ? '#94A3B8' : '#64748B', fontSize: '12px', fontWeight: 500 }, maxWidth: 110 } },
    grid: { show: false, padding: { left: 0, right: 0 } },
    tooltip: { ...base.value.tooltip, y: { formatter: (v, o) => `${v} leads · ${pct(data.value.funnel[o.dataPointIndex].pct_of_total)} of all` } },
  },
}))

const me = computed(() => data.value?.leaderboard?.find((r) => r.is_me))
const maxWinRate = computed(() => Math.max(1, ...(data.value?.leaderboard ?? []).map((r) => r.win_rate ?? 0)))
const ACT_ICONS = { note: MessageSquare, call: Phone, email: Mail, meeting: Users, site_visit: MapPin, status_change: CircleDot, assignment: UserRound, quote: FileText, created: Sparkles, score: Target }
const tel = (p) => `tel:${p.replace(/[^\d+]/g, '')}`
const today = date(new Date())
</script>

<template>
  <div>
    <PageHeader :title="`${greeting()}, ${auth.firstName}`">
      <template #description>{{ today }} · <template v-if="k.open_hot_leads">You have <span class="font-medium text-ink">{{ k.open_hot_leads }} hot leads</span> waiting for a call.</template><template v-else>Here's what's happening at BuildLead Bau.</template></template>
      <template #actions>
        <BaseButton variant="secondary" :to="{ name: 'pipeline' }" :icon-right="ArrowRight">Pipeline</BaseButton>
        <BaseButton :icon="Plus" @click="ui.newLeadOpen = true">New lead</BaseButton>
      </template>
    </PageHeader>

    <div v-if="loadError && !data" class="card"><ErrorState title="Could not load the dashboard" :message="loadError" @retry="load" /></div>
    <template v-else>
    <!-- KPIs -->
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
      <StatCard label="New leads · 30 days" :value="number(k.new_leads_30d?.value)" :delta="t.new_leads_pct" :icon="Users" tone="blue" :trend="weeks.map((w) => w.total)" :loading="loading" />
      <StatCard label="Open hot leads" :value="number(k.open_hot_leads)" :delta="t.hot_leads_pct" delta-label="new hot vs. prev. 30 days" :icon="Flame" tone="red" :trend="weeks.map((w) => w.hot)" :loading="loading" />
      <StatCard label="Pipeline value" :value="moneyShort(k.pipeline_value)" :delta="t.pipeline_value_pct" delta-label="added vs. prev. 30 days" :icon="Euro" tone="amber" :trend="weeks.map((w) => w.value)" :loading="loading" />
      <StatCard
        label="Win rate · 90 days" :value="pct(k.win_rate?.value, 1)" :delta="t.win_rate_pts" delta-suffix=" pts" delta-label="vs. previous 45 days"
        :hint="k.win_rate ? `${k.win_rate.won} won of ${k.win_rate.closed} closed` : null" :icon="Trophy" tone="green" :loading="loading"
      />
    </div>

    <!-- Leads per week + sources -->
    <div class="mt-4 grid gap-4 sm:mt-5 lg:grid-cols-3">
      <BaseCard class="lg:col-span-2" title="Leads per week" subtitle="Last 12 weeks, stacked by temperature">
        <template #actions>
          <div class="hidden items-center gap-3 text-[12px] text-muted sm:flex">
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-orange-500" />Hot</span>
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-brand" />Warm</span>
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-400" />Cold</span>
          </div>
        </template>
        <Skeleton v-if="loading" class="h-[340px] w-full" />
        <apexchart v-else :key="`a${dark}`" type="area" height="340" :options="area.options" :series="area.series" />
      </BaseCard>

      <BaseCard title="Leads by source" subtitle="Where enquiries come from">
        <Skeleton v-if="loading" class="mx-auto h-[200px] w-[200px] !rounded-full" />
        <template v-else-if="sources.length">
          <apexchart :key="`d${dark}`" type="donut" height="210" :options="donut.options" :series="donut.series" />
          <ul class="mt-3 space-y-1.5">
            <li v-for="(s, i) in sources" :key="s.source" class="flex items-center gap-2.5 text-[12.5px]">
              <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ background: donutColors[i] }" />
              <span class="flex-1 truncate text-ink">{{ s.label }}</span>
              <span class="num text-muted">{{ s.count }}</span>
              <span class="num w-16 text-right text-[11.5px] text-faint" title="Average score">avg {{ Math.round(s.avg_score) }}</span>
            </li>
          </ul>
        </template>
        <EmptyState v-else compact illustration="chart" title="No leads yet" />
      </BaseCard>
    </div>

    <!-- Call now + funnel -->
    <div class="mt-4 grid gap-4 sm:mt-5 lg:grid-cols-3">
      <BaseCard class="lg:col-span-2" :padded="false">
        <template #header>
          <div>
            <h3 class="flex items-center gap-2 text-[15px] font-semibold tracking-[-0.01em] text-ink">
              <span class="flex h-6 w-6 items-center justify-center rounded-md bg-gradient-to-br from-red-500 to-orange-500"><Flame class="h-3.5 w-3.5 text-white" :stroke-width="2.5" /></span>
              Hot leads to call now
            </h3>
            <p class="mt-0.5 text-[13px] text-muted">Highest score first — speed wins construction deals.</p>
          </div>
        </template>
        <template #actions><RouterLink :to="{ name: 'leads', query: { temperature: 'hot' } }" class="text-[13px] font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">All hot leads</RouterLink></template>

        <div v-if="loading" class="space-y-4 p-5"><div v-for="i in 4" :key="i" class="flex items-center gap-3"><Skeleton class="h-11 w-11 !rounded-full" /><div class="flex-1 space-y-2"><Skeleton class="h-3 w-1/2" /><Skeleton class="h-2.5 w-3/4" /></div><Skeleton class="h-8 w-20" /></div></div>
        <ul v-else-if="data?.top_hot_leads?.length" class="divide-y divide-line px-2 pb-2 pt-3">
          <li v-for="lead in data.top_hot_leads" :key="lead.id" class="group flex items-center gap-3 rounded-xl px-3 py-3 transition hover:bg-subtle/70">
            <button class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="ui.openLead(lead.id)">
              <ScoreRing :score="lead.score" :temperature="lead.temperature" :size="44" />
              <span class="min-w-0 flex-1">
                <span class="flex items-baseline gap-2">
                  <span class="truncate text-[14px] font-medium text-ink">{{ lead.company || lead.contact_name }}</span>
                  <span class="num hidden shrink-0 text-[12.5px] font-semibold text-ink sm:inline">{{ moneyShort(lead.estimated_value) }}</span>
                </span>
                <span class="block truncate text-[12px] text-muted">{{ lead.company ? `${lead.contact_name} · ` : '' }}{{ lead.project_type_label }} · {{ lead.city }}</span>
                <span v-if="lead.ai_next_action" class="mt-1 flex min-w-0 items-center gap-1.5 text-[12px] text-amber-700 dark:text-amber-400"><Wand2 class="h-3 w-3 shrink-0" /><span class="truncate">{{ lead.ai_next_action }}</span></span>
              </span>
            </button>
            <Avatar v-if="lead.owner" :name="lead.owner.name" size="sm" class="hidden sm:inline-flex" />
            <a v-if="lead.phone" :href="tel(lead.phone)" class="focus-ring inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-brand px-3 text-[13px] font-medium text-navy shadow-sm transition hover:bg-brand-hover hover:text-white"><Phone class="h-3.5 w-3.5" /><span class="hidden sm:inline">Call</span></a>
          </li>
        </ul>
        <EmptyState v-else compact title="No hot leads right now" description="New hot leads show up here — and in your notifications — instantly." />
      </BaseCard>

      <BaseCard title="Conversion funnel" subtitle="Leads that reached each stage">
        <Skeleton v-if="loading" class="h-[300px] w-full" />
        <apexchart v-else :key="`f${dark}`" type="bar" height="300" :options="funnel.options" :series="funnel.series" />
      </BaseCard>
    </div>

    <!-- Team + activity -->
    <div class="mt-4 grid gap-4 sm:mt-5 lg:grid-cols-2">
      <BaseCard title="Team leaderboard" subtitle="Won value · win rate · first response" :padded="false">
        <div v-if="loading" class="space-y-4 p-5"><Skeleton v-for="i in 6" :key="i" class="h-9 w-full" /></div>
        <ol v-else class="px-2 pb-2 pt-3">
          <li v-for="r in data.leaderboard" :key="r.user.id" class="flex items-center gap-3 rounded-xl px-3 py-2.5" :class="r.is_me && 'bg-brand/10 ring-1 ring-inset ring-brand/25'">
            <span class="num w-5 text-center text-[12px] font-bold" :class="r.rank === 1 ? 'text-brand' : 'text-faint'">{{ r.rank === 1 ? '🏆' : r.rank }}</span>
            <Avatar :name="r.user.name" size="md" />
            <div class="min-w-0 flex-1">
              <div class="flex items-baseline justify-between gap-2">
                <span class="truncate text-[13.5px] font-medium text-ink">{{ r.user.name }}<span v-if="r.is_me" class="ml-1.5 text-[11px] font-semibold text-brand-700 dark:text-brand-400">You</span></span>
                <span class="num shrink-0 text-[13px] font-semibold text-ink">{{ moneyShort(r.won_value) }}</span>
              </div>
              <div class="mt-1.5 flex items-center gap-2">
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-subtle"><div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-500" :style="{ width: `${((r.win_rate ?? 0) / maxWinRate) * 100}%` }" /></div>
                <span class="num w-24 shrink-0 text-right text-[11.5px] text-muted">{{ r.win_rate === null ? 'no closes yet' : `${pct(r.win_rate)} win rate` }}</span>
              </div>
              <p class="num mt-1 text-[11.5px] text-faint">{{ r.won }} won · {{ r.open_leads }} open<template v-if="r.hot_open"> · <span class="text-orange-500">{{ r.hot_open }} hot</span></template> · first response {{ minutes(r.avg_first_response_minutes) }}</p>
            </div>
          </li>
        </ol>
      </BaseCard>

      <BaseCard title="Recent activity" subtitle="Across your leads" :padded="false">
        <div v-if="loading" class="space-y-4 p-5"><Skeleton v-for="i in 7" :key="i" class="h-9 w-full" /></div>
        <ul v-else-if="data.recent_activity?.length" class="px-2 pb-2 pt-3">
          <li v-for="a in data.recent_activity" :key="a.id">
            <button class="flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-subtle/70" @click="ui.openLead(a.lead.id)">
              <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg" :class="a.title.includes('🔥') ? 'bg-gradient-to-br from-red-500 to-orange-500 text-white' : 'bg-subtle text-muted'">
                <component :is="ACT_ICONS[a.type] ?? CircleDot" class="h-3.5 w-3.5" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-[13.5px] font-medium text-ink">{{ a.title }}</span>
                <span class="block truncate text-[12px] text-muted">{{ a.lead.name }} · {{ a.lead.reference }}</span>
              </span>
              <span class="shrink-0 text-right text-[11.5px] text-faint">{{ timeAgo(a.created_at) }}<span class="block">{{ a.user ? a.user.name.split(' ')[0] : 'BuildLead AI' }}</span></span>
            </button>
          </li>
        </ul>
        <EmptyState v-else compact illustration="inbox" title="Nothing yet" description="Calls, notes and status changes will appear here." />
      </BaseCard>
    </div>

    <p v-if="me" class="mt-5 text-center text-[12.5px] text-muted">You're <span class="font-semibold text-ink">#{{ me.rank }}</span> on the team with <span class="font-semibold text-ink">{{ moneyShort(me.won_value) }}</span> won — keep it up.</p>
    </template>
  </div>
</template>
