<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Clock, Eye, EyeOff, Flame, Trophy, UserRound } from 'lucide-vue-next'
import Logo from '@/components/ui/Logo.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import ScoreRing from '@/components/ui/ScoreRing.vue'
import Avatar from '@/components/ui/Avatar.vue'
import { DEMO_MODE } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const show = ref(false)
const loading = ref(false)
const error = ref('')

const demoUsers = [
  { role: 'Admin', email: 'admin@buildlead.test', name: 'Katrin Hoffmann' },
  { role: 'Manager', email: 'manager@buildlead.test', name: 'Thomas Becker' },
  { role: 'Sales', email: 'anna.weber@buildlead.test', name: 'Anna Weber' },
]

function useDemo(u) {
  email.value = u.email
  password.value = 'password'
  error.value = ''
}

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.replace(route.query.redirect || { name: 'dashboard' })
  } catch (e) {
    error.value = e.errors?.email?.[0] || e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen bg-surface">
    <!-- Brand panel -->
    <aside class="relative hidden w-[52%] overflow-hidden bg-navy lg:flex lg:flex-col">
      <div class="absolute inset-0 bg-[radial-gradient(80%_60%_at_15%_0%,rgb(245_158_11/0.22),transparent_60%),radial-gradient(60%_50%_at_100%_100%,rgb(56_189_248/0.10),transparent_60%),linear-gradient(160deg,#0B1220_0%,#0E1830_55%,#0B1220_100%)]" />
      <!-- subtle grid -->
      <div class="absolute inset-0 opacity-[0.35] [background-image:linear-gradient(rgb(255_255_255/0.06)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255/0.06)_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(70%_70%_at_40%_40%,black,transparent)]" />

      <div class="relative z-10 flex flex-1 flex-col px-12 py-10 xl:px-16">
        <Logo tone="light" :size="34" />

        <div class="my-auto max-w-xl pt-10">
          <p class="mb-5 inline-flex items-center gap-2 rounded-full bg-white/[0.06] px-3 py-1 text-[12px] font-medium text-slate-300 ring-1 ring-inset ring-white/10">
            <span class="h-1.5 w-1.5 rounded-full bg-brand" /> AI lead scoring for construction
          </p>
          <h1 class="text-[44px] font-semibold leading-[1.08] tracking-[-0.035em] text-white xl:text-[52px]">
            Score every lead.<br />
            <span class="bg-gradient-to-r from-amber-300 via-brand to-orange-400 bg-clip-text text-transparent">Win the right projects.</span>
          </h1>
          <p class="mt-5 max-w-md text-[15px] leading-relaxed text-slate-400">
            Every enquiry from your website, inbox and phone is scored 0–100, routed to the right rep and tracked from first call to final invoice.
          </p>

          <!-- Product preview -->
          <div class="relative mt-10 max-w-md">
            <div class="absolute -inset-4 rounded-3xl bg-brand/10 blur-2xl" />
            <div class="relative rounded-2xl border border-white/10 bg-white/[0.06] p-5 shadow-2xl backdrop-blur-xl">
              <div class="flex items-center gap-4">
                <div class="rounded-full bg-navy/60 p-1"><ScoreRing :score="92" temperature="hot" :size="64" class="[&_span]:!text-white" /></div>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-2">
                    <p class="truncate text-[15px] font-semibold text-white">Nordwind Architekten GmbH</p>
                    <span class="inline-flex items-center gap-1 rounded-full bg-gradient-to-r from-red-500 to-orange-500 px-2 py-0.5 text-[11px] font-semibold text-white"><Flame class="h-3 w-3" />Hot</span>
                  </div>
                  <p class="mt-0.5 text-[13px] text-slate-400">Commercial fit-out · Berlin Mitte · € 480.000</p>
                </div>
              </div>
              <div class="mt-4 flex items-center gap-2 rounded-xl bg-black/20 px-3 py-2.5 text-[12.5px] text-slate-300">
                <Avatar name="Anna Weber" size="sm" />
                Assigned to <span class="font-medium text-white">Anna Weber</span> · best win rate in region
                <span class="ml-auto text-slate-500">2m ago</span>
              </div>
            </div>
            <div class="absolute -right-6 -top-6 hidden items-center gap-2 rounded-xl border border-white/10 bg-navy-800/90 px-3 py-2 text-[12px] text-slate-300 shadow-xl backdrop-blur xl:flex">
              <Clock class="h-3.5 w-3.5 text-brand" /> First response <span class="font-semibold text-white">55 min</span>
            </div>
            <div class="absolute -bottom-5 -left-6 hidden items-center gap-2 rounded-xl border border-white/10 bg-navy-800/90 px-3 py-2 text-[12px] text-slate-300 shadow-xl backdrop-blur xl:flex">
              <Trophy class="h-3.5 w-3.5 text-emerald-400" /> Win rate <span class="font-semibold text-white">37,5 %</span>
            </div>
          </div>
        </div>

        <p class="text-[12px] text-slate-500">© {{ new Date().getFullYear() }} BuildLead Bau GmbH · Berlin & Brandenburg</p>
      </div>
    </aside>

    <!-- Form -->
    <main class="flex flex-1 items-center justify-center px-6 py-12 sm:px-10">
      <div class="w-full max-w-[380px] animate-fade-up">
        <Logo class="mb-10 lg:hidden" :size="34" />
        <h2 class="text-[26px] font-semibold tracking-[-0.03em] text-ink">Welcome back</h2>
        <p class="mt-1.5 text-[14px] text-muted">Sign in to your BuildLead workspace.</p>

        <form class="mt-8 space-y-4" @submit.prevent="submit">
          <div>
            <label for="email" class="field-label">Work email</label>
            <input id="email" v-model="email" type="email" autocomplete="username" required class="field-input h-11" :class="error && 'is-invalid'" placeholder="you@buildlead.test" autofocus />
          </div>
          <div>
            <div class="flex items-center justify-between">
              <label for="password" class="field-label">Password</label>
              <span class="mb-1.5 text-[12.5px] text-faint">Forgot it? Ask your admin.</span>
            </div>
            <div class="relative">
              <input id="password" v-model="password" :type="show ? 'text' : 'password'" autocomplete="current-password" required class="field-input h-11 pr-11" :class="error && 'is-invalid'" placeholder="••••••••" />
              <button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-faint hover:text-ink" :aria-label="show ? 'Hide password' : 'Show password'" @click="show = !show">
                <EyeOff v-if="show" class="h-4 w-4" /><Eye v-else class="h-4 w-4" />
              </button>
            </div>
          </div>
          <Transition name="fade"><p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-[13px] text-red-700 ring-1 ring-inset ring-red-600/15 dark:bg-red-400/10 dark:text-red-300" role="alert">{{ error }}</p></Transition>
          <BaseButton type="submit" size="lg" block :loading="loading" :icon-right="ArrowRight">Sign in</BaseButton>
        </form>

        <div class="mt-8 rounded-card border border-dashed border-line bg-subtle/50 p-4">
          <p class="flex items-center gap-2 text-[12.5px] font-semibold text-ink">
            <UserRound class="h-3.5 w-3.5 text-brand" /> Demo accounts <span class="font-normal text-muted">· password <code class="rounded bg-surface px-1 py-px font-mono text-[12px] text-ink ring-1 ring-line">password</code></span>
          </p>
          <div class="mt-3 grid grid-cols-3 gap-2">
            <button
              v-for="u in demoUsers" :key="u.role" type="button"
              class="focus-ring group rounded-xl border border-line bg-surface px-2 py-2.5 text-center transition hover:border-brand/50 hover:shadow-card"
              :class="email === u.email && 'border-brand ring-2 ring-brand/20'"
              @click="useDemo(u)"
            >
              <Avatar :name="u.name" size="sm" class="mx-auto" />
              <span class="mt-1.5 block text-[12.5px] font-semibold text-ink">{{ u.role }}</span>
              <span class="block truncate text-[11px] text-faint">{{ u.name.split(' ')[0] }}</span>
            </button>
          </div>
          <p v-if="DEMO_MODE" class="mt-3 text-[11.5px] text-muted">Demo mode: running on sample data, no backend required.</p>
        </div>
      </div>
    </main>
  </div>
</template>
