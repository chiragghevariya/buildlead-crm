<script setup>
import { onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppSidebar from './AppSidebar.vue'
import AppTopbar from './AppTopbar.vue'
import MobileNav from './MobileNav.vue'
import CommandPalette from '@/components/CommandPalette.vue'
import NewLeadModal from '@/components/leads/NewLeadModal.vue'
import LeadDrawer from '@/components/leads/LeadDrawer.vue'
import ShortcutsDialog from '@/components/ShortcutsDialog.vue'
import { useShortcuts } from '@/composables/useShortcuts'
import { useMetaStore } from '@/stores/meta'
import { useUiStore } from '@/stores/ui'
import { DEMO_MODE } from '@/api/http'

const ui = useUiStore()
const route = useRoute()
const { sidebarRail, leadDrawerId } = storeToRefs(ui)
useShortcuts()
onMounted(() => useMetaStore().load())

// Navigating anywhere closes the quick-view drawer.
watch(() => route.fullPath, () => { leadDrawerId.value = null })
</script>

<template>
  <div class="min-h-screen bg-canvas">
    <a href="#main" class="sr-only z-[60] rounded-lg bg-brand px-4 py-2 text-[13px] font-semibold text-navy focus:not-sr-only focus:fixed focus:left-4 focus:top-3">Skip to content</a>
    <AppSidebar />
    <div class="flex min-h-screen flex-col transition-[padding] duration-200 ease-out" :class="sidebarRail ? 'md:pl-[72px]' : 'md:pl-[248px]'">
      <div v-if="DEMO_MODE" data-demo-banner class="flex items-center justify-center gap-2 bg-navy px-4 py-1.5 text-center text-[12px] text-slate-300">
        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand" /> Demo mode — realistic sample data, changes reset on reload
      </div>
      <AppTopbar />
      <main id="main" tabindex="-1" class="mx-auto outline-none w-full max-w-[1440px] flex-1 px-4 pb-28 pt-6 sm:px-6 md:pb-12 lg:px-8">
        <RouterView v-slot="{ Component, route: r }">
          <Transition name="page" mode="out-in">
            <component :is="Component" :key="r.meta.viewKey || r.fullPath.split('?')[0]" />
          </Transition>
        </RouterView>
      </main>
    </div>
    <MobileNav />
    <CommandPalette />
    <NewLeadModal />
    <LeadDrawer />
    <ShortcutsDialog />
  </div>
</template>
