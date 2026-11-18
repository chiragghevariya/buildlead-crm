<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Building2, MapPin, Sparkles, User } from 'lucide-vue-next'
import Modal from '@/components/ui/Modal.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import { leads as leadsApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useMetaStore } from '@/stores/meta'
import { useToastStore } from '@/stores/toasts'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const auth = useAuthStore()
const meta = useMetaStore()
const toasts = useToastStore()
const router = useRouter()

const blank = () => ({
  contact_name: '', company: '', email: '', phone: '',
  source: 'phone_call', project_type: '', timeline: '',
  budget_min: '', budget_max: '', area_sqm: '',
  street: '', postcode: '', city: 'Berlin', description: '',
  assigned_to: '', consent_given: true,
})
const form = reactive(blank())
const errors = ref({})
const saving = ref(false)
const phase = ref('') // '', 'saving', 'scoring'

watch(() => ui.newLeadOpen, (open) => {
  if (open) { meta.load(); Object.assign(form, blank()); errors.value = {} }
})

const payload = computed(() => Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null)))
const err = (k) => errors.value[k]

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/** Real API scores in a queued job: poll briefly so we can show the result. */
async function waitForScore(lead) {
  if (lead.scored_at) return lead
  phase.value = 'scoring'
  for (let i = 0; i < 10; i++) {
    await sleep(900)
    const fresh = await leadsApi.get(lead.id)
    if (fresh.scored_at) return fresh
  }
  return lead
}

function announce(lead) {
  const open = { label: 'Open lead', run: () => router.push({ name: 'lead', params: { id: lead.id } }) }
  if (!lead.scored_at) {
    toasts.push({ type: 'info', title: `Lead ${lead.reference} created`, description: 'Scoring is queued — run the queue worker to score it.', action: open })
    return
  }
  const label = { hot: 'Hot 🔥', warm: 'Warm', cold: 'Cold' }[lead.temperature]
  toasts.push({
    type: lead.temperature === 'hot' ? 'hot' : lead.temperature === 'warm' ? 'success' : 'info',
    title: `Lead scored ${lead.score}/100 — ${label}`,
    description: `${lead.contact_name}${lead.owner ? ` · assigned to ${lead.owner.name}` : ''}`,
    action: open,
    duration: 7000,
  })
}

async function submit() {
  saving.value = true
  phase.value = 'saving'
  errors.value = {}
  try {
    const created = await leadsApi.create(payload.value)
    const lead = await waitForScore(created)
    ui.newLeadOpen = false
    ui.leadsVersion++
    announce(lead)
  } catch (e) {
    errors.value = e.errors ?? {}
    if (!Object.keys(errors.value).length) toasts.error('Could not create lead', e.message)
  } finally {
    saving.value = false
    phase.value = ''
  }
}

function fillExample() {
  Object.assign(form, {
    contact_name: 'Clara Neumann', company: 'Nordwind Architekten GmbH', email: 'clara.neumann@example.com', phone: '+49 30 4445556',
    source: 'referral', project_type: 'commercial_fit_out', timeline: 'asap', budget_min: 350000, budget_max: 480000, area_sqm: 650,
    street: 'Friedrichstraße 100', postcode: '10117', city: 'Berlin',
    description: 'Ausbau unserer neuen Bürofläche (650 m²) in Mitte, Mietvertrag unterschrieben, Einzug im März.',
  })
}
</script>

<template>
  <Modal v-model:open="ui.newLeadOpen" size="lg" :dismissible="!saving">
    <template #header>
      <div class="flex items-center gap-3">
        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand/15 text-brand-600 ring-1 ring-inset ring-brand/25 dark:text-brand-400"><Sparkles class="h-[18px] w-[18px]" /></span>
        <div>
          <h2 class="text-base font-semibold tracking-[-0.01em] text-ink">New lead</h2>
          <p class="text-[13px] text-muted">Scored instantly by rules + AI and routed to the best rep.</p>
        </div>
      </div>
    </template>

    <form id="new-lead-form" class="space-y-6" @submit.prevent="submit">
      <section>
        <p class="eyebrow mb-3 flex items-center gap-1.5"><User class="h-3.5 w-3.5" /> Contact</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormField label="Contact name" for="nl-name" :error="err('contact_name')">
            <input id="nl-name" v-model="form.contact_name" class="field-input" :class="err('contact_name') && 'is-invalid'" placeholder="Lena Hartmann" autofocus />
          </FormField>
          <FormField label="Company" for="nl-company" optional>
            <input id="nl-company" v-model="form.company" class="field-input" placeholder="Private client if empty" />
          </FormField>
          <FormField label="Email" for="nl-email" :error="err('email')">
            <input id="nl-email" v-model="form.email" type="email" class="field-input" :class="err('email') && 'is-invalid'" placeholder="name@example.com" />
          </FormField>
          <FormField label="Phone" for="nl-phone" :error="err('phone')">
            <input id="nl-phone" v-model="form.phone" type="tel" class="field-input" :class="err('phone') && 'is-invalid'" placeholder="+49 30 …" />
          </FormField>
        </div>
      </section>

      <section>
        <p class="eyebrow mb-3 flex items-center gap-1.5"><Building2 class="h-3.5 w-3.5" /> Project</p>
        <div class="grid gap-4 sm:grid-cols-3">
          <FormField label="Source" for="nl-source" :error="err('source')">
            <select id="nl-source" v-model="form.source" class="field-input">
              <option v-for="o in meta.sources" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </FormField>
          <FormField label="Project type" for="nl-type" :error="err('project_type')">
            <select id="nl-type" v-model="form.project_type" class="field-input">
              <option value="">Not sure yet</option>
              <option v-for="o in meta.projectTypes" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </FormField>
          <FormField label="Timeline" for="nl-timeline">
            <select id="nl-timeline" v-model="form.timeline" class="field-input">
              <option value="">Unknown</option>
              <option v-for="o in meta.timelines" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </FormField>
          <FormField label="Budget from" for="nl-bmin" :error="err('budget_min')">
            <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint">€</span>
              <input id="nl-bmin" v-model="form.budget_min" type="number" min="0" step="1000" class="field-input num pl-7" placeholder="80.000" /></div>
          </FormField>
          <FormField label="Budget to" for="nl-bmax" :error="err('budget_max')">
            <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint">€</span>
              <input id="nl-bmax" v-model="form.budget_max" type="number" min="0" step="1000" class="field-input num pl-7" :class="err('budget_max') && 'is-invalid'" placeholder="120.000" /></div>
          </FormField>
          <FormField label="Area" for="nl-area" :error="err('area_sqm')">
            <div class="relative"><input id="nl-area" v-model="form.area_sqm" type="number" min="1" class="field-input num pr-10" placeholder="120" />
              <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-faint">m²</span></div>
          </FormField>
          <FormField label="Description" for="nl-desc" class="sm:col-span-3" hint="Detailed descriptions (40+ characters) score higher.">
            <textarea id="nl-desc" v-model="form.description" rows="3" class="field-input resize-none" placeholder="What should be built or renovated? Plans, permits, constraints…" />
          </FormField>
        </div>
      </section>

      <section>
        <p class="eyebrow mb-3 flex items-center gap-1.5"><MapPin class="h-3.5 w-3.5" /> Site address</p>
        <div class="grid gap-4 sm:grid-cols-6">
          <FormField label="Street" for="nl-street" class="sm:col-span-6"><input id="nl-street" v-model="form.street" class="field-input" placeholder="Kastanienallee 7" /></FormField>
          <FormField label="Postcode" for="nl-pc" class="sm:col-span-2" :error="err('postcode')">
            <input id="nl-pc" v-model="form.postcode" inputmode="numeric" maxlength="5" class="field-input num" :class="err('postcode') && 'is-invalid'" placeholder="10437" />
          </FormField>
          <FormField label="City" for="nl-city" class="sm:col-span-4"><input id="nl-city" v-model="form.city" class="field-input" /></FormField>
        </div>
      </section>

      <section class="grid gap-4 sm:grid-cols-2">
        <FormField v-if="auth.canSeeAll" label="Owner" for="nl-owner" :error="err('assigned_to')">
          <select id="nl-owner" v-model="form.assigned_to" class="field-input">
            <option value="">✨ Auto-assign (recommended)</option>
            <option v-for="r in meta.salesReps" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </FormField>
        <label class="flex items-start gap-3 rounded-xl border border-line bg-subtle/50 p-3 text-[13px] text-muted sm:col-span-1" :class="!auth.canSeeAll && 'sm:col-span-2'">
          <input v-model="form.consent_given" type="checkbox" class="form-checkbox mt-0.5 h-4 w-4 rounded border-line text-brand focus:ring-brand/30" />
          <span><span class="font-medium text-ink">GDPR consent given</span><br>The contact agreed to be contacted about this enquiry.</span>
        </label>
      </section>
    </form>

    <template #footer>
      <button type="button" class="mr-auto text-[13px] font-medium text-muted hover:text-ink" @click="fillExample">Fill example</button>
      <BaseButton variant="secondary" :disabled="saving" @click="ui.newLeadOpen = false">Cancel</BaseButton>
      <BaseButton type="submit" form="new-lead-form" :loading="saving">
        {{ phase === 'scoring' ? 'Scoring…' : 'Create & score lead' }}
      </BaseButton>
    </template>
  </Modal>
</template>
