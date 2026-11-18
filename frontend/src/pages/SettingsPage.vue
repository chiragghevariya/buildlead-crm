<script setup>
import ShortcutsList from '@/components/ShortcutsList.vue'
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Bot, Gauge, Keyboard, Monitor, Moon, Palette, RotateCcw, Sun, UserRound, Users } from 'lucide-vue-next'
import TeamSettings from '@/components/settings/TeamSettings.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { scoringSettings } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()

const tabs = computed(() => [
  { value: 'profile', label: 'Profile', icon: UserRound },
  { value: 'appearance', label: 'Appearance', icon: Palette },
  ...(auth.isAdmin ? [{ value: 'scoring', label: 'AI scoring', icon: Gauge }] : []),
  ...(auth.canSeeAll ? [{ value: 'team', label: 'Team', icon: Users }] : []),
  { value: 'shortcuts', label: 'Shortcuts', icon: Keyboard },
])
const tab = ref(tabs.value.some((t) => t.value === route.query.tab) ? route.query.tab : 'profile')
watch(tab, (t) => router.replace({ query: { tab: t } }))
watch(() => route.query.tab, (t) => { if (t && t !== tab.value && tabs.value.some((x) => x.value === t)) tab.value = t })

// ---- Scoring (admin) --------------------------------------------------------
const FACTORS = [
  { key: 'budget', label: 'Budget', hint: 'Higher budgets score higher' },
  { key: 'project_type', label: 'Project type', hint: 'New builds & fit-outs rank highest' },
  { key: 'location', label: 'Location', hint: 'Core Berlin postcodes get full points' },
  { key: 'urgency', label: 'Urgency', hint: 'ASAP beats "just researching"' },
  { key: 'source', label: 'Source', hint: 'Referrals close best' },
  { key: 'completeness', label: 'Completeness', hint: 'Phone, email, budget, area…' },
]
// Reactive proxies can't be structuredClone()d: copy via JSON.
const plainCopy = (v) => JSON.parse(JSON.stringify(v))
const settings = ref(null)
const form = reactive({ weights: {}, bands: { hot: 75, warm: 50 }, ai_weight: 0.6, ai_enabled: true })
const saving = ref(false)
const loadingSettings = ref(false)

async function loadSettings() {
  loadingSettings.value = true
  try {
    settings.value = await scoringSettings.get()
    Object.assign(form, plainCopy({ weights: settings.value.weights, bands: settings.value.bands, ai_weight: settings.value.ai_weight, ai_enabled: settings.value.ai_enabled }))
  } catch (e) { toasts.error('Could not load scoring settings', e.message) } finally { loadingSettings.value = false }
}
watch(tab, (t) => { if (t === 'scoring' && !settings.value) loadSettings() }, { immediate: true })

const weightSum = computed(() => Object.values(form.weights).reduce((s, v) => s + Number(v), 0))
const valid = computed(() => weightSum.value === 100 && Number(form.bands.hot) > Number(form.bands.warm))

async function save(rescore = false) {
  saving.value = true
  try {
    const res = await scoringSettings.update({ ...form, rescore })
    settings.value = res
    toasts.success('Scoring settings saved', rescore ? `${res.rescore_queued} open leads queued for re-scoring.` : 'New leads will be scored with these settings.')
    ui.leadsVersion++
  } catch (e) { toasts.error('Could not save', e.message) } finally { saving.value = false }
}
async function reset() {
  saving.value = true
  try {
    settings.value = await scoringSettings.reset()
    Object.assign(form, plainCopy({ weights: settings.value.weights, bands: settings.value.bands, ai_weight: settings.value.ai_weight, ai_enabled: settings.value.ai_enabled }))
    toasts.info('Restored default scoring settings')
  } catch (e) { toasts.error('Could not reset', e.message) } finally { saving.value = false }
}

const themes = [
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'dark', label: 'Dark', icon: Moon },
  { value: 'auto', label: 'System', icon: Monitor },
]
</script>

<template>
  <div>
    <PageHeader title="Settings" description="Your account, appearance and how leads are scored." />
    <Tabs v-model="tab" :tabs="tabs" variant="underline" class="mb-6" />

    <!-- Profile -->
    <BaseCard v-if="tab === 'profile'" class="max-w-2xl animate-fade-up">
      <div class="flex items-center gap-4">
        <Avatar :name="auth.user?.name" size="xl" />
        <div>
          <p class="text-lg font-semibold text-ink">{{ auth.user?.name }}</p>
          <p class="text-[13px] text-muted">{{ auth.user?.email }}</p>
          <BaseBadge tone="amber" class="mt-2">{{ auth.user?.role_label }}</BaseBadge>
        </div>
      </div>
      <dl class="mt-6 grid gap-4 border-t border-line pt-6 sm:grid-cols-2">
        <div><dt class="text-[12px] text-muted">Phone</dt><dd class="font-medium text-ink">{{ auth.user?.phone || '—' }}</dd></div>
        <div><dt class="text-[12px] text-muted">Company</dt><dd class="font-medium text-ink">BuildLead Bau GmbH, Berlin</dd></div>
        <template v-if="auth.user?.role === 'sales'">
          <div><dt class="text-[12px] text-muted">Regions (postcode prefixes)</dt><dd class="flex flex-wrap gap-1.5 pt-1"><BaseBadge v-for="r in auth.user.regions" :key="r">{{ r }}…</BaseBadge></dd></div>
          <div><dt class="text-[12px] text-muted">Capacity</dt><dd class="font-medium text-ink">{{ auth.user.max_open_leads }} open leads</dd></div>
        </template>
      </dl>
    </BaseCard>

    <!-- Appearance -->
    <BaseCard v-else-if="tab === 'appearance'" class="max-w-2xl animate-fade-up" title="Theme" subtitle="Remembered on this device.">
      <div class="grid grid-cols-3 gap-3">
        <button
          v-for="t in themes" :key="t.value" type="button"
          class="focus-ring group overflow-hidden rounded-xl border text-left transition"
          :class="ui.theme === t.value ? 'border-brand ring-2 ring-brand/25' : 'border-line hover:border-faint'"
          @click="ui.theme = t.value"
        >
          <div class="flex h-20 gap-1.5 p-2" :class="t.value === 'dark' ? 'bg-navy-950' : t.value === 'light' ? 'bg-[#F7F8FA]' : 'bg-gradient-to-r from-[#F7F8FA] from-50% to-navy-950 to-50%'">
            <div class="w-5 rounded-md bg-navy" />
            <div class="flex-1 space-y-1.5 rounded-md p-1.5" :class="t.value === 'dark' ? 'bg-navy-800' : 'bg-white shadow-sm'">
              <div class="h-1.5 w-1/2 rounded-full bg-brand" /><div class="h-1.5 w-3/4 rounded-full" :class="t.value === 'dark' ? 'bg-white/15' : 'bg-slate-200'" /><div class="h-1.5 w-2/3 rounded-full" :class="t.value === 'dark' ? 'bg-white/10' : 'bg-slate-100'" />
            </div>
          </div>
          <div class="flex items-center gap-2 px-3 py-2.5 text-[13px] font-medium text-ink"><component :is="t.icon" class="h-4 w-4 text-muted" />{{ t.label }}</div>
        </button>
      </div>
    </BaseCard>

    <!-- Scoring -->
    <div v-else-if="tab === 'scoring'" class="grid max-w-5xl grid-cols-1 gap-4 animate-fade-up lg:grid-cols-5">
      <BaseCard class="lg:col-span-3" title="Factor weights" subtitle="How the rule score (0–100) is built. Must total 100.">
        <template #actions>
          <span class="num rounded-lg px-2 py-1 text-[13px] font-semibold" :class="weightSum === 100 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400' : 'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-400'">{{ weightSum }} / 100</span>
        </template>
        <div v-if="loadingSettings" class="space-y-5"><Skeleton v-for="i in 6" :key="i" class="h-10" /></div>
        <div v-else class="space-y-5">
          <div v-for="f in FACTORS" :key="f.key">
            <div class="mb-1.5 flex items-baseline justify-between">
              <label :for="`w-${f.key}`" class="text-[13.5px] font-medium text-ink">{{ f.label }} <span class="ml-1 text-[12px] font-normal text-faint">{{ f.hint }}</span></label>
              <span class="num text-[13px] font-semibold text-ink">{{ form.weights[f.key] }}</span>
            </div>
            <input :id="`w-${f.key}`" v-model.number="form.weights[f.key]" v-range type="range" min="0" max="50" step="1" class="w-full" />
          </div>
        </div>
      </BaseCard>

      <div class="space-y-4 lg:col-span-2">
        <BaseCard :padded="true">
          <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl" :class="settings?.ai_available && form.ai_enabled ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-400/10 dark:text-emerald-400' : 'bg-subtle text-faint'"><Bot class="h-5 w-5" /></span>
            <div class="min-w-0 flex-1">
              <p class="flex items-center gap-2 text-[14px] font-semibold text-ink">OpenAI
                <BaseBadge :tone="settings?.ai_available && form.ai_enabled ? 'green' : 'gray'" dot size="sm">{{ !settings?.ai_available ? 'No API key' : form.ai_enabled ? 'On' : 'Off' }}</BaseBadge>
              </p>
              <p class="truncate text-[12.5px] text-muted">Model {{ settings?.ai_model ?? '—' }} · JSON mode · no email/phone sent</p>
            </div>
          </div>
        </BaseCard>
        <BaseCard title="Temperature bands" subtitle="Final score thresholds">
          <div class="space-y-4">
            <div>
              <div class="mb-1 flex justify-between text-[13px]"><label for="b-hot" class="font-medium text-ink">🔥 Hot from</label><span class="num font-semibold text-ink">{{ form.bands.hot }}</span></div>
              <input id="b-hot" v-model.number="form.bands.hot" v-range type="range" min="50" max="100" class="w-full range-hot" />
            </div>
            <div>
              <div class="mb-1 flex justify-between text-[13px]"><label for="b-warm" class="font-medium text-ink">Warm from</label><span class="num font-semibold text-ink">{{ form.bands.warm }}</span></div>
              <input id="b-warm" v-model.number="form.bands.warm" v-range type="range" min="20" max="95" class="w-full" />
            </div>
            <div class="flex h-2.5 overflow-hidden rounded-full text-[0]">
              <div class="bg-slate-300 dark:bg-slate-600" :style="{ width: `${form.bands.warm}%` }" /><div class="bg-brand" :style="{ width: `${Math.max(0, form.bands.hot - form.bands.warm)}%` }" /><div class="bg-gradient-to-r from-orange-500 to-red-500" :style="{ width: `${100 - form.bands.hot}%` }" />
            </div>
            <p class="flex justify-between text-[11.5px] text-muted"><span>Cold &lt; {{ form.bands.warm }}</span><span>Warm {{ form.bands.warm }}–{{ form.bands.hot - 1 }}</span><span>Hot ≥ {{ form.bands.hot }}</span></p>
          </div>
          <p v-if="form.bands.hot <= form.bands.warm" class="field-error">Hot must be higher than warm.</p>
        </BaseCard>
        <BaseCard title="AI blend" :subtitle="`Final = AI × ${form.ai_weight} + rules × ${(1 - form.ai_weight).toFixed(2)}`">
          <input v-model.number="form.ai_weight" v-range type="range" min="0" max="1" step="0.05" class="w-full" aria-label="AI weight" />
          <div class="mt-1 flex justify-between text-[12px] text-muted"><span>Rules only</span><span class="num font-semibold text-ink">{{ Math.round(form.ai_weight * 100) }}% AI</span><span>AI only</span></div>
          <label class="mt-4 flex items-center justify-between gap-3 rounded-xl bg-subtle p-3 text-[13px]">
            <span><span class="font-medium text-ink">Use OpenAI</span><br><span class="text-muted">{{ settings?.ai_available ? 'Turn off to score with rules only' : 'Add OPENAI_API_KEY to enable — rules only for now' }}</span></span>
            <input v-model="form.ai_enabled" type="checkbox" class="form-checkbox h-5 w-5 rounded border-line text-brand focus:ring-brand/30" />
          </label>
        </BaseCard>
        <div class="flex flex-wrap gap-2">
          <BaseButton :disabled="!valid" :loading="saving" @click="save(false)">Save</BaseButton>
          <BaseButton variant="dark" :disabled="!valid || saving" @click="save(true)">Save & re-score {{ settings?.open_leads ?? '' }} open leads</BaseButton>
          <BaseButton variant="ghost" :icon="RotateCcw" :disabled="saving" @click="reset">Defaults</BaseButton>
        </div>
      </div>
    </div>

    <TeamSettings v-else-if="tab === 'team'" />

    <!-- Shortcuts -->
    <BaseCard v-else class="max-w-2xl animate-fade-up" title="Keyboard shortcuts" subtitle="Work faster without the mouse." :padded="false">
      <ShortcutsList />
    </BaseCard>
  </div>
</template>
