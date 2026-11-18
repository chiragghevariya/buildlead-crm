import { FileText, FolderKanban, LayoutDashboard, Receipt, Settings, SquareKanban, Users } from 'lucide-vue-next'

/** Single source for sidebar, mobile nav, command palette and breadcrumbs. */
export const NAV = [
  { name: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, shortcut: 'G D', keywords: 'home overview kpi' },
  { name: 'leads', label: 'Leads', icon: Users, shortcut: 'G L', keywords: 'contacts customers enquiries' },
  { name: 'pipeline', label: 'Pipeline', icon: SquareKanban, shortcut: 'G P', keywords: 'kanban board deals stages' },
  { name: 'quotes', label: 'Quotes', icon: FileText, keywords: 'angebote offers proposals' },
  { name: 'projects', label: 'Projects', icon: FolderKanban, roles: ['admin', 'manager'], keywords: 'jobs sites construction' },
  { name: 'invoices', label: 'Invoices', icon: Receipt, roles: ['admin', 'manager'], keywords: 'rechnungen billing payments' },
  { name: 'settings', label: 'Settings', icon: Settings, roles: ['admin', 'manager'], keywords: 'preferences scoring theme profile' },
]

export const navFor = (role) => NAV.filter((item) => !item.roles || item.roles.includes(role))
