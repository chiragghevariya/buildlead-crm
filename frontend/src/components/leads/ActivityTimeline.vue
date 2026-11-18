<script setup>
import { CircleDot, FileText, Mail, MapPin, MessageSquare, Phone, Sparkles, Target, UserRound, Users } from 'lucide-vue-next'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { date, dateTime, timeAgo } from '@/lib/format'

defineProps({ activities: { type: Array, default: () => [] } })

const ICONS = { note: MessageSquare, call: Phone, email: Mail, meeting: Users, site_visit: MapPin, status_change: CircleDot, score: Target, assignment: UserRound, quote: FileText, created: Sparkles }
const tone = (a) => (a.title.includes('🔥')
  ? 'bg-gradient-to-br from-red-500 to-orange-500 text-white'
  : { quote: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-400/10 dark:text-emerald-400', score: 'bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400', call: 'bg-sky-50 text-sky-600 dark:bg-sky-400/10 dark:text-sky-400', meeting: 'bg-violet-50 text-violet-600 dark:bg-violet-400/10 dark:text-violet-400', site_visit: 'bg-violet-50 text-violet-600 dark:bg-violet-400/10 dark:text-violet-400' }[a.type] ?? 'bg-subtle text-muted')
const upcoming = (a) => a.due_at && new Date(a.due_at) > new Date()
const overdue = (a) => a.due_at && new Date(a.due_at) <= new Date() && ['call', 'email', 'meeting'].includes(a.type) && new Date(a.created_at) < new Date(a.due_at)
</script>

<template>
  <ol v-if="activities.length" class="relative space-y-5 before:absolute before:bottom-2 before:left-[15px] before:top-2 before:w-px before:bg-line">
    <li v-for="a in activities" :key="a.id" class="relative flex gap-3 animate-fade-up">
      <span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-surface" :class="tone(a)">
        <component :is="ICONS[a.type] ?? CircleDot" class="h-3.5 w-3.5" />
      </span>
      <div class="min-w-0 flex-1 pt-1">
        <p class="text-[13.5px] font-medium text-ink">
          {{ a.title }}
          <BaseBadge v-if="upcoming(a)" tone="amber" size="sm" class="ml-1">Due {{ date(a.due_at) }}</BaseBadge>
          <BaseBadge v-else-if="overdue(a)" tone="red" size="sm" class="ml-1">Overdue</BaseBadge>
        </p>
        <p v-if="a.body" class="mt-0.5 whitespace-pre-line text-[13px] text-muted">{{ a.body }}</p>
        <p class="mt-1 text-[11.5px] text-faint" :title="dateTime(a.created_at)">{{ a.user?.name ?? 'BuildLead AI' }} · {{ timeAgo(a.created_at) }}</p>
      </div>
    </li>
  </ol>
  <EmptyState v-else compact illustration="inbox" title="No activity yet" description="Log a call or add a note to start the timeline." />
</template>
