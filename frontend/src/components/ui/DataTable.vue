<script setup>
import { computed } from 'vue'
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, ChevronsUpDown } from 'lucide-vue-next'
import Skeleton from './Skeleton.vue'
import EmptyState from './EmptyState.vue'
import ErrorState from './ErrorState.vue'

/**
 * columns: [{ key, label, sortable?, align?: 'left'|'right'|'center', width?, class?, hideBelow?: 'sm'|'md'|'lg' }]
 * Cell override: <template #cell-<key>="{ row, value }">
 * Sort: v-model:sort ("score" asc, "-score" desc). Pagination: :meta (Laravel) + @page.
 */
const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  rowKey: { type: String, default: 'id' },
  loading: Boolean,
  sort: { type: String, default: null },
  meta: { type: Object, default: null },
  clickable: Boolean,
  skeletonRows: { type: Number, default: 8 },
  emptyTitle: { type: String, default: 'Nothing here yet' },
  emptyDescription: { type: String, default: null },
  emptyIllustration: { type: String, default: 'search' },
  /** Checkbox column + v-model:selected (array of row keys). */
  selectable: Boolean,
  selected: { type: Array, default: () => [] },
  /** Load error message: replaces the empty state with a retry prompt. */
  error: { type: String, default: null },
})
const emit = defineEmits(['update:sort', 'row-click', 'page', 'update:selected', 'retry'])

const slots = defineSlots()
const pageKeys = computed(() => props.rows.map((r) => r[props.rowKey]))
const allOnPage = computed(() => pageKeys.value.length > 0 && pageKeys.value.every((k) => props.selected.includes(k)))
const someOnPage = computed(() => !allOnPage.value && pageKeys.value.some((k) => props.selected.includes(k)))
function toggleAll() {
  const keys = new Set(props.selected)
  pageKeys.value.forEach((k) => (allOnPage.value ? keys.delete(k) : keys.add(k)))
  emit('update:selected', [...keys])
}
function toggleRow(key) {
  emit('update:selected', props.selected.includes(key) ? props.selected.filter((k) => k !== key) : [...props.selected, key])
}

const sortKey = computed(() => props.sort?.replace(/^-/, ''))
const sortDesc = computed(() => props.sort?.startsWith('-'))
const hide = { sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell', xl: 'hidden xl:table-cell', '2xl': 'hidden 2xl:table-cell' }
const alignClass = (c) => ({ right: 'text-right', center: 'text-center' }[c.align] ?? 'text-left')

function toggleSort(col) {
  if (!col.sortable) return
  // first click: descending (most useful for scores, money, dates)
  emit('update:sort', sortKey.value === col.key ? (sortDesc.value ? col.key : `-${col.key}`) : `-${col.key}`)
}

const pages = computed(() => {
  if (!props.meta || props.meta.last_page <= 1) return []
  const { current_page: c, last_page: l } = props.meta
  const set = new Set([1, l, c - 1, c, c + 1].filter((p) => p >= 1 && p <= l))
  const sorted = [...set].sort((a, b) => a - b)
  return sorted.flatMap((p, i) => (i && p - sorted[i - 1] > 1 ? ['…', p] : [p]))
})
</script>

<template>
  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full min-w-full text-[13.5px]">
        <thead>
          <tr class="border-b border-line bg-subtle/60">
            <th v-if="selectable" scope="col" class="w-10 pl-5 pr-1">
              <input
                type="checkbox" class="form-checkbox h-4 w-4 rounded border-line text-brand focus:ring-brand/30"
                :checked="allOnPage" :indeterminate="someOnPage" aria-label="Select all on this page" @change="toggleAll"
              />
            </th>
            <th
              v-for="col in columns" :key="col.key" scope="col"
              class="whitespace-nowrap px-4 py-2.5 text-[11.5px] font-semibold uppercase tracking-[0.05em] text-muted first:pl-5 last:pr-5"
              :class="[alignClass(col), hide[col.hideBelow], col.sortable && 'cursor-pointer select-none hover:text-ink']"
              :style="col.width ? { width: col.width } : null"
              :aria-sort="sortKey === col.key ? (sortDesc ? 'descending' : 'ascending') : undefined"
              @click="toggleSort(col)"
            >
              <span class="inline-flex items-center gap-1" :class="col.align === 'right' && 'flex-row-reverse'">
                {{ col.label }}
                <template v-if="col.sortable">
                  <ArrowDown v-if="sortKey === col.key && sortDesc" class="h-3 w-3 text-brand" />
                  <ArrowUp v-else-if="sortKey === col.key" class="h-3 w-3 text-brand" />
                  <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                </template>
              </span>
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <template v-if="loading && !rows.length">
            <tr v-for="i in skeletonRows" :key="`sk-${i}`">
              <td v-if="selectable" class="pl-5 pr-1"><Skeleton class="h-4 w-4" /></td>
              <td v-for="(col, ci) in columns" :key="col.key" class="px-4 py-3.5 first:pl-5 last:pr-5" :class="hide[col.hideBelow]">
                <div v-if="ci === 0" class="flex items-center gap-3"><Skeleton class="h-8 w-8 !rounded-full" /><div class="space-y-1.5"><Skeleton class="h-3 w-32" /><Skeleton class="h-2.5 w-20" /></div></div>
                <Skeleton v-else class="h-3" :class="col.align === 'right' ? 'ml-auto w-16' : 'w-20'" />
              </td>
            </tr>
          </template>
          <tr
            v-for="row in rows" v-else :key="row[rowKey]"
            class="group relative transition-colors"
            :class="[clickable && 'cursor-pointer hover:bg-subtle/60', loading && 'opacity-60', selected.includes(row[rowKey]) && '!bg-brand/[0.06]']"
            @click="clickable && emit('row-click', row)"
          >
            <td v-if="selectable" class="w-10 pl-5 pr-1" @click.stop>
              <input
                type="checkbox" class="form-checkbox h-4 w-4 rounded border-line text-brand focus:ring-brand/30"
                :checked="selected.includes(row[rowKey])" aria-label="Select row" @change="toggleRow(row[rowKey])"
              />
            </td>
            <td
              v-for="col in columns" :key="col.key"
              class="px-4 py-3 align-middle first:pl-5 last:pr-5"
              :class="[alignClass(col), hide[col.hideBelow], col.class]"
            >
              <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">{{ row[col.key] ?? '—' }}</slot>
            </td>
            <!-- Hover actions float over the row's right edge (desktop only). -->
            <td v-if="slots['row-actions']" class="pointer-events-none absolute inset-y-0 right-0 hidden items-center pr-4 lg:flex" @click.stop>
              <div class="pointer-events-auto flex translate-x-1 items-center gap-1 rounded-xl border border-line bg-surface p-1 opacity-0 shadow-lift transition group-hover:translate-x-0 group-hover:opacity-100">
                <slot name="row-actions" :row="row" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ErrorState v-if="error && !loading && !rows.length" :message="error" @retry="emit('retry')" />
    <EmptyState v-else-if="!loading && !rows.length" :title="emptyTitle" :description="emptyDescription" :illustration="emptyIllustration">
      <slot name="empty-actions" />
    </EmptyState>

    <div v-if="meta && meta.total > 0" class="flex items-center justify-between gap-3 border-t border-line px-5 py-3 text-[13px] text-muted">
      <span class="num">{{ meta.from }}–{{ meta.to }} of {{ meta.total }}</span>
      <nav v-if="pages.length" class="flex items-center gap-1" aria-label="Pagination">
        <button class="rounded-lg p-1.5 hover:bg-subtle disabled:opacity-40" :disabled="meta.current_page <= 1" aria-label="Previous page" @click="emit('page', meta.current_page - 1)"><ChevronLeft class="h-4 w-4" /></button>
        <template v-for="(p, i) in pages" :key="i">
          <span v-if="p === '…'" class="px-1">…</span>
          <button
            v-else class="num h-7 min-w-[28px] rounded-lg px-2 font-medium transition"
            :class="p === meta.current_page ? 'bg-navy text-white dark:bg-white dark:text-navy' : 'hover:bg-subtle hover:text-ink'"
            @click="emit('page', p)"
          >{{ p }}</button>
        </template>
        <button class="rounded-lg p-1.5 hover:bg-subtle disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" aria-label="Next page" @click="emit('page', meta.current_page + 1)"><ChevronRight class="h-4 w-4" /></button>
      </nav>
    </div>
  </div>
</template>
