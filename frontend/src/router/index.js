import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import AppShell from '@/components/layout/AppShell.vue'

const routes = [
  { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { guest: true, title: 'Sign in' } },
  {
    path: '/',
    component: AppShell,
    meta: { auth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
      { path: 'leads', name: 'leads', component: () => import('@/pages/LeadsPage.vue'), meta: { title: 'Leads' } },
      { path: 'leads/:id', name: 'lead', component: () => import('@/pages/LeadDetailPage.vue'), meta: { title: 'Lead' } },
      { path: 'pipeline', name: 'pipeline', component: () => import('@/pages/PipelinePage.vue'), meta: { title: 'Pipeline' } },
      { path: 'quotes', name: 'quotes', component: () => import('@/pages/QuotesPage.vue'), meta: { title: 'Quotes' } },
      { path: 'quotes/new', name: 'quote-new', component: () => import('@/pages/QuoteBuilderPage.vue'), meta: { title: 'New quote', viewKey: 'quote-builder' } },
      { path: 'quotes/:id', name: 'quote', component: () => import('@/pages/QuoteBuilderPage.vue'), meta: { title: 'Quote', viewKey: 'quote-builder' } },
      { path: 'projects', name: 'projects', component: () => import('@/pages/ProjectsPage.vue'), meta: { title: 'Projects', roles: ['admin', 'manager'] } },
      { path: 'projects/:id', name: 'project', component: () => import('@/pages/ProjectDetailPage.vue'), meta: { title: 'Project', roles: ['admin', 'manager'] } },
      { path: 'team', redirect: { name: 'settings', query: { tab: 'team' } } },
      { path: 'invoices', name: 'invoices', component: () => import('@/pages/InvoicesPage.vue'), meta: { title: 'Invoices', roles: ['admin', 'manager'] } },
      { path: 'settings', name: 'settings', component: () => import('@/pages/SettingsPage.vue'), meta: { title: 'Settings', roles: ['admin', 'manager'] } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFoundPage.vue'), meta: { title: 'Not found' } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: (to, from, saved) => saved || (to.path !== from.path ? { top: 0 } : undefined),
})

router.beforeEach(async (to, from) => {
  const auth = useAuthStore()
  await auth.init()

  if (to.matched.some((r) => r.meta.auth) && !auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  if (to.meta.guest && auth.isAuthenticated) return { name: 'dashboard' }
  // Role-restricted screens (spec §3): sales reps only work their own leads and quotes.
  if (to.matched.some((r) => r.meta.roles && !r.meta.roles.includes(auth.role))) {
    useToastStore().info(`${to.meta.title ?? 'That page'} isn't available for your role`, 'Ask a manager if you need access.')
    return from.name && from.name !== 'login' ? false : { name: 'dashboard' }
  }
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · BuildLead` : 'BuildLead CRM'
})

export default router
